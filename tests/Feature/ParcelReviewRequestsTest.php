<?php

namespace Tests\Feature;

use App\Actions\ParcelReviews;
use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Enums\ShipmentStatus;
use App\Models\CustomerNotification;
use App\Models\CustomerReview;
use App\Models\Shipment;
use App\Models\ShopChannelConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\OnlineShopFixtures;
use Tests\TestCase;

/**
 * O8: a delivered parcel's review request, by WhatsApp when the shop has
 * messaging and from the shop's own WhatsApp otherwise, and the feedback page.
 */
class ParcelReviewRequestsTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop();
    }

    protected function deliveredLongAgo(): Shipment
    {
        $parcel = $this->parcel($this->codSale());
        $parcel->forceFill(['delivered_at' => now()->subHours(ParcelReviews::DELAY_HOURS + 1)])->save();

        return $parcel->fresh();
    }

    public function test_with_whatsapp_messaging_the_request_goes_out_by_itself_once(): void
    {
        $this->shop->update(['whatsapp_messaging_enabled' => true]);
        ShopChannelConnection::create(['shop_id' => $this->shop->id, 'provider' => ChannelProvider::WhatsApp, 'external_id' => '100200300', 'waba_id' => '900', 'display_name' => 'Style Hub', 'access_token' => 'token', 'status' => ConnectionStatus::Connected, 'connected_at' => now()]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);
        $parcel = $this->deliveredLongAgo();
        $fresh = $this->parcel($this->codSale(1, 250, '0333 1112223'));

        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $this->artisan('whatsapp:follow-ups')->assertSuccessful();

        $this->assertNotNull($parcel->fresh()->review_requested_at);
        $this->assertNull($fresh->fresh()->review_requested_at);
        $notification = CustomerNotification::sole();
        $this->assertSame([Shipment::class, $parcel->id], [$notification->notifiable_type, $notification->notifiable_id]);
        // In an open chat the message carries the feedback page; outside it, the approved template's rating buttons.
        $this->assertStringContainsString(route('parcel-review.show', $parcel->fresh()->review_token), $notification->body);
        $this->assertSame('review_request', $notification->template_key);
    }

    public function test_the_buyer_leaves_stars_and_a_comment_on_the_feedback_page(): void
    {
        $parcel = $this->deliveredLongAgo();
        $link = ParcelReviews::link($parcel);
        auth()->logout();

        $this->get($link)->assertOk()->assertSee('How was your order?')->assertSee('Lawn Suit');
        $this->post(route('parcel-review.store', $parcel->fresh()->review_token), ['rating' => 5, 'comment' => 'Lovely fabric', 'consent' => '1'])->assertRedirect($link);
        $this->post(route('parcel-review.store', $parcel->fresh()->review_token), ['rating' => 4, 'comment' => 'Lovely fabric, late'])->assertRedirect($link);

        $review = CustomerReview::sole();
        $this->assertSame([$parcel->id, 4, 'Lovely fabric, late', false, 'complete'], [$review->shipment_id, $review->rating, $review->comment, $review->publish_consent, $review->status]);
        $this->assertSame($this->customerOf($parcel->sale)->id, $review->customer_id);
    }

    public function test_an_undelivered_parcel_takes_no_review_and_an_unknown_link_is_not_found(): void
    {
        $parcel = $this->parcel($this->codSale(), ShipmentStatus::InTransit);
        $link = ParcelReviews::link($parcel);

        $this->get($link)->assertOk()->assertSee('once the order is delivered');
        $this->post(route('parcel-review.store', $parcel->fresh()->review_token), ['rating' => 5])->assertSessionHasErrors('rating');
        $this->get(route('parcel-review.show', str_repeat('x', 40)))->assertNotFound();
        $this->assertSame(0, CustomerReview::count());
    }

    public function test_without_whatsapp_the_shop_sends_the_link_from_shipments(): void
    {
        $parcel = $this->deliveredLongAgo();

        Livewire::test('shipments.index', ['show' => 'delivered'])
            ->assertSee('Ask for review')
            ->call('askReview', $parcel->id)
            ->assertRedirectContains('https://wa.me/923214445556?text=');

        $this->assertNotNull($parcel->fresh()->review_requested_at);
        $this->assertSame([], ParcelReviews::due(10)->all());
    }

    public function test_retail_and_daraz_parcels_are_never_asked_about(): void
    {
        $retail = \App\Models\Shop::create(['name' => 'Corner', 'business_category' => 'clothing', 'couriers_enabled' => true, 'whatsapp_messaging_enabled' => true]);
        $this->assertFalse(ParcelReviews::enabled($retail));

        $this->shop->update(['whatsapp_messaging_enabled' => true]);
        $parcel = $this->deliveredLongAgo();
        $parcel->update(['courier' => \App\Enums\CourierKey::Daraz]);
        $this->assertSame([], ParcelReviews::due(10)->all());
    }
}
