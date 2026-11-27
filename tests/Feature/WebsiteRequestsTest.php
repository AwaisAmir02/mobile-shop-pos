<?php

namespace Tests\Feature;

use App\Actions\StoreConnections;
use App\Actions\WebsiteRequests;
use App\Enums\WebsiteRequestStatus;
use App\Models\Shop;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WebsiteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\OnlineShopFixtures;
use Tests\TestCase;

/**
 * O9: "build my website" intake, talked through on a support ticket, moved
 * along and marked delivered by our team with the store connection the site uses.
 */
class WebsiteRequestsTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop('online_brand', ['online_store_enabled' => true]);
    }

    protected function submit(): WebsiteRequest
    {
        Livewire::test('website-requests.index')
            ->set('about', 'We sell hand-embroidered lawn suits to women across Pakistan.')
            ->set('goal', 'orders')
            ->set('products', '120')
            ->set('domain', 'stylehub.pk')
            ->set('phone', '03001234567')
            ->call('submit')
            ->assertHasNoErrors();

        return WebsiteRequest::sole();
    }

    public function test_a_request_opens_a_support_conversation_and_only_one_is_open_at_a_time(): void
    {
        $request = $this->submit();

        $this->assertSame(WebsiteRequestStatus::Submitted, $request->status);
        $ticket = SupportTicket::sole();
        $this->assertSame($request->support_ticket_id, $ticket->id);
        $this->assertStringContainsString('Take orders on the site, cash on delivery', $ticket->firstMessage->body);
        $this->get(route('website-requests.index'))->assertSee($request->number())->assertSee('You have a request open');

        $this->expectException(ValidationException::class);
        WebsiteRequests::submit($this->owner, ['about' => str_repeat('x', 30), 'goal' => 'catalogue', 'products' => 5, 'phone' => '0300']);
    }

    public function test_our_team_moves_it_along_and_marks_it_delivered_with_the_shops_own_connection(): void
    {
        $request = $this->submit();
        $connection = StoreConnections::create($this->shop->id, 'stylehub.pk', $this->owner->id)['connection'];
        $foreignShop = Shop::create(['name' => 'Other', 'business_category' => 'online_brand', 'online_store_enabled' => true]);
        $foreign = StoreConnections::create($foreignShop->id, 'other.pk', null)['connection'];
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $this->actingAs($admin);

        $this->get(route('admin.website-requests'))->assertOk()->assertSee($request->number());
        $page = Livewire::test('admin.website-requests.show', ['websiteRequest' => $request->id])->assertSee('hand-embroidered');

        $page->set('note', 'Design draft by Friday.')->call('setStatus', 'in_progress');
        $this->assertSame(WebsiteRequestStatus::InProgress, $request->fresh()->status);

        try {
            WebsiteRequests::fulfil($request->fresh(), $foreign->id, 'https://stylehub.pk', null, $admin);
            $this->fail('Another shop\'s connection must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('storeConnectionId', $e->errors());
        }

        $page->set('siteUrl', 'https://stylehub.pk')->set('storeConnectionId', (string) $connection->id)->set('note', '')->call('fulfil')->assertHasNoErrors();

        $request->refresh();
        $this->assertSame([WebsiteRequestStatus::Fulfilled, $connection->id, 'https://stylehub.pk', $admin->id], [$request->status, $request->store_connection_id, $request->site_url, $request->fulfilled_by]);
        $replies = SupportTicket::sole()->messages()->pluck('body')->all();
        $this->assertStringContainsString('Design draft by Friday.', $replies[1]);
        $this->assertStringContainsString('Your website is ready: https://stylehub.pk', $replies[2]);

    }

    public function test_only_online_sellers_ask_and_only_admins_see_the_list(): void
    {
        $this->get(route('admin.website-requests'))->assertForbidden();

        $retail = Shop::create(['name' => 'Corner', 'business_category' => 'kiryana']);
        $this->actingAs(User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $this->get(route('website-requests.index'))->assertNotFound();
    }
}
