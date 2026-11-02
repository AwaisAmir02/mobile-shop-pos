<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Services\Marketing\MetaAdsOnboarding;
use App\Services\Marketing\MetaOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsIntegrationsSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected const WHATSAPP_CARD = "Connect your shop's WhatsApp Business number";

    protected const FACEBOOK_CARD = "Connect your shop's Facebook Page";

    protected const ADS_CARD = 'Needed to run paid ads';

    protected const MESSAGES_EDITOR = 'WhatsApp chatbot messages';

    protected function ownerOf(array $flags): User
    {
        $shop = Shop::create(['name' => 'Shop A'] + $flags);

        return User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
    }

    public function test_with_whatsapp_on_the_default_is_whatsapp_with_its_card_and_the_messages_editor_only(): void
    {
        $this->actingAs($this->ownerOf(['whatsapp_ordering_enabled' => true, 'whatsapp_messaging_enabled' => true, 'social_enabled' => true, 'ads_enabled' => true]));

        Livewire::test('settings.integrations')
            ->assertSet('section', 'whatsapp')
            ->assertSeeInOrder(['WhatsApp', 'Facebook &amp; Instagram', 'Meta Ad Account'], false)
            ->assertSee(self::WHATSAPP_CARD)
            ->assertSee(self::MESSAGES_EDITOR)
            ->assertDontSee(self::FACEBOOK_CARD)
            ->assertDontSee(self::ADS_CARD);

        $this->get(route('settings.index', ['tab' => 'integrations']))
            ->assertOk()
            ->assertSee(self::WHATSAPP_CARD)
            ->assertSee(self::MESSAGES_EDITOR)
            ->assertDontSee(self::FACEBOOK_CARD);
    }

    public function test_the_default_is_the_first_feature_turned_on(): void
    {
        $this->actingAs($this->ownerOf(['social_enabled' => true, 'ads_enabled' => true]));
        Livewire::test('settings.integrations')
            ->assertSet('section', 'facebook')
            ->assertSee(self::FACEBOOK_CARD)
            ->assertDontSee(self::WHATSAPP_CARD)
            ->assertDontSee(self::MESSAGES_EDITOR);

        $this->actingAs($this->ownerOf(['ads_enabled' => true]));
        Livewire::test('settings.integrations')
            ->assertSet('section', 'ads')
            ->assertSee(self::ADS_CARD)
            ->assertDontSee(self::WHATSAPP_CARD);

        // Nothing on: today's WhatsApp "not enabled" card.
        $this->actingAs($this->ownerOf([]));
        Livewire::test('settings.integrations')
            ->assertSet('section', 'whatsapp')
            ->assertSee('WhatsApp ordering is not enabled for your shop.')
            ->assertDontSee(self::MESSAGES_EDITOR);
    }

    public function test_each_sub_tab_shows_only_its_own_card(): void
    {
        $this->actingAs($this->ownerOf(['whatsapp_ordering_enabled' => true, 'whatsapp_messaging_enabled' => true, 'social_enabled' => true, 'ads_enabled' => true]));

        Livewire::test('settings.integrations')
            ->call('setSection', 'facebook')
            ->assertSet('section', 'facebook')
            ->assertSee(self::FACEBOOK_CARD)
            ->assertDontSee(self::WHATSAPP_CARD)
            ->assertDontSee(self::ADS_CARD)
            ->assertDontSee(self::MESSAGES_EDITOR)
            ->call('setSection', 'ads')
            ->assertSee(self::ADS_CARD)
            ->assertDontSee(self::FACEBOOK_CARD)
            ->assertDontSee(self::MESSAGES_EDITOR)
            ->call('setSection', 'whatsapp')
            ->assertSee(self::WHATSAPP_CARD)
            ->assertSee(self::MESSAGES_EDITOR);
    }

    public function test_the_ad_account_sub_tab_needs_ads_and_unknown_sections_fall_back(): void
    {
        $this->actingAs($this->ownerOf(['whatsapp_ordering_enabled' => true, 'whatsapp_messaging_enabled' => true, 'social_enabled' => true]));

        Livewire::test('settings.integrations')
            ->assertDontSee('Meta Ad Account')
            ->call('setSection', 'ads')
            ->assertSet('section', 'whatsapp')
            ->assertDontSee(self::ADS_CARD)
            ->call('setSection', 'nonsense')
            ->assertSet('section', 'whatsapp');

        Livewire::withQueryParams(['section' => 'ads'])->test('settings.integrations')->assertSet('section', 'whatsapp');
        Livewire::withQueryParams(['section' => 'facebook'])->test('settings.integrations')->assertSet('section', 'facebook')->assertSee(self::FACEBOOK_CARD);
    }

    public function test_an_explicit_section_in_the_address_is_kept_even_when_not_the_default(): void
    {
        $this->actingAs($this->ownerOf(['social_enabled' => true]));

        Livewire::withQueryParams(['section' => 'whatsapp'])->test('settings.integrations')
            ->assertSet('section', 'whatsapp')
            ->assertSee('WhatsApp ordering is not enabled for your shop.');

        $this->get(route('settings.index', ['tab' => 'integrations', 'section' => 'whatsapp']))->assertOk()->assertSee(self::WHATSAPP_CARD);
        $this->get(route('settings.index', ['tab' => 'integrations']))->assertOk()->assertSee(self::FACEBOOK_CARD)->assertDontSee(self::WHATSAPP_CARD);
    }

    public function test_returning_from_a_meta_login_opens_that_login_sub_tab(): void
    {
        $this->mock(MetaOnboarding::class, fn ($mock) => $mock->shouldReceive('exchangeCode')->andReturn([])->shouldReceive('candidates')->andReturn([]));
        $this->mock(MetaAdsOnboarding::class, fn ($mock) => $mock->shouldReceive('exchangeCode')->andReturn([])->shouldReceive('candidates')->andReturn([]));
        $this->actingAs($this->ownerOf(['whatsapp_ordering_enabled' => true, 'whatsapp_messaging_enabled' => true, 'social_enabled' => true, 'ads_enabled' => true]));

        Livewire::test('settings.integrations')
            ->assertSet('section', 'whatsapp')
            ->call('completeMetaLogin', 'code-1')
            ->assertSet('section', 'facebook')
            ->call('setSection', 'whatsapp')
            ->call('completeAdsLogin', 'code-2')
            ->assertSet('section', 'ads');
    }
}
