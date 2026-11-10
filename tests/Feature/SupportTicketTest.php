<?php

namespace Tests\Feature;

use App\Actions\Support\OpenSupportTicket;
use App\Actions\Support\ReplyToSupportTicket;
use App\Enums\SupportTicketStatus;
use App\Mail\NewSupportTicketMail;
use App\Models\Role;
use App\Models\Shop;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\SupportScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Support tickets. Any shop user opens one (no screen permission), with
 * photos and the screen they were on; the platform admin answers in the same
 * thread. Shops never see each other's tickets; staff see only their own.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    private User $cashier;

    private User $helper;

    private User $otherOwner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        $this->shop = Shop::create(['name' => 'Ali Mobiles']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Ali Owner']);

        // A role with no screens at all: support still has to be reachable.
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Nothing', 'permissions' => []]);
        $this->cashier = User::factory()->staff()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id, 'name' => 'Cashier Bilal']);
        $this->helper = User::factory()->staff()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id, 'name' => 'Helper Sana']);

        $other = Shop::create(['name' => 'Other Shop']);
        $this->otherOwner = User::factory()->create(['shop_id' => $other->id, 'name' => 'Other Owner']);

        $this->admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true, 'is_owner' => false, 'name' => 'Platform Admin']);
    }

    private function open(User $user, string $body = 'Complete Sale does nothing', array $images = [], ?string $from = null, ?string $title = null): SupportTicket
    {
        $this->actingAs($user);

        return app(OpenSupportTicket::class)->handle($user, $body, $images, $from, $title, 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36');
    }

    private function reply(SupportTicket $ticket, User $author, string $body = 'Reply'): void
    {
        $this->actingAs($author);
        app(ReplyToSupportTicket::class)->handle($ticket->fresh(), $author, $body);
    }

    public function test_staff_with_no_screens_can_open_a_ticket_with_photos_and_the_screen_they_were_on(): void
    {
        $this->actingAs($this->cashier);

        $page = Livewire::withQueryParams(['from' => '/sales/history?page=2', 'screen' => 'Sales History — '.config('app.name')])
            ->test('support.create')
            ->assertSee('About the screen I was on:')
            ->assertSee('Sales History')
            ->set('body', 'The total is wrong after a discount.')
            ->set('newImages', [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.png')])
            ->assertCount('images', 2)
            ->call('submit')
            ->assertHasNoErrors();

        $ticket = SupportTicket::sole();
        $page->assertRedirect(route('support.show', $ticket));

        $this->assertSame($this->shop->id, $ticket->shop_id);
        $this->assertSame($this->cashier->id, $ticket->user_id);
        $this->assertSame(SupportTicketStatus::Open, $ticket->status);
        $this->assertSame('sales.history', $ticket->screen_route);
        $this->assertSame('/sales/history?page=2', $ticket->screen_url);
        $this->assertSame('Sales History', $ticket->screen_title);

        $message = $ticket->messages()->sole();
        $this->assertSame('The total is wrong after a discount.', $message->body);
        $this->assertFalse($message->from_admin);
        $this->assertCount(2, $message->attachments);

        foreach ($message->attachments as $attachment) {
            $this->assertStringStartsWith('support/'.$this->shop->id.'/'.$ticket->id.'/', $attachment->path);
            Storage::disk('local')->assertExists($attachment->path);
        }

        Mail::assertQueued(NewSupportTicketMail::class, fn (NewSupportTicketMail $mail) => $mail->hasTo('info@hisabokitab.com') && $mail->ticket->is($ticket));
    }

    public function test_unticking_the_screen_leaves_it_off_the_ticket(): void
    {
        $this->actingAs($this->owner);

        Livewire::withQueryParams(['from' => '/products', 'screen' => 'Products'])
            ->test('support.create')
            ->set('aboutScreen', false)
            ->set('body', 'How do I change my shop logo?')
            ->call('submit')
            ->assertHasNoErrors();

        $ticket = SupportTicket::sole();
        $this->assertNull($ticket->screen_route);
        $this->assertNull($ticket->screen_url);
    }

    public function test_a_message_is_needed_to_open_a_ticket(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('support.create')->set('body', '   ')->call('submit')->assertHasErrors(['body']);

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_photos_add_up_across_picks_and_stop_at_five(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('support.create')
            ->set('newImages', [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')])
            ->set('newImages', [UploadedFile::fake()->image('d.jpg'), UploadedFile::fake()->image('e.jpg'), UploadedFile::fake()->image('f.jpg')])
            ->assertCount('images', 5)
            ->assertHasErrors(['images'])
            ->call('removeImage', 0)
            ->assertCount('images', 4);
    }

    public function test_only_photos_up_to_four_megabytes_are_accepted(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('support.create')
            ->set('newImages', [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')])
            ->assertHasErrors(['newImages.0'])
            ->assertCount('images', 0)
            ->set('newImages', [UploadedFile::fake()->image('huge.jpg')->size(5000)])
            ->assertHasErrors(['newImages.0'])
            ->assertCount('images', 0);
    }

    public function test_only_our_own_shop_screens_are_recorded_as_the_screen(): void
    {
        $this->assertSame('products.show', SupportScreen::resolve('/products/12')['route']);
        $this->assertSame('settings.index', SupportScreen::resolve('http://localhost/settings?tab=branding')['route']);
        $this->assertSame('/settings?tab=branding', SupportScreen::resolve('http://localhost/settings?tab=branding')['url']);

        $this->assertNull(SupportScreen::resolve('https://evil.example/sales'));
        $this->assertNull(SupportScreen::resolve('/support'));
        $this->assertNull(SupportScreen::resolve('/admin/plans'));
        $this->assertNull(SupportScreen::resolve('/no-such-page'));
        $this->assertNull(SupportScreen::resolve(''));
    }

    public function test_the_owner_sees_every_ticket_of_the_shop_and_staff_only_their_own(): void
    {
        $this->open($this->cashier, 'From the cashier');
        $this->open($this->helper, 'From the helper');
        $this->open($this->owner, 'From the owner');
        $this->open($this->otherOwner, 'From another shop');

        $rows = fn (User $user) => substr_count($this->actingAs($user)->get(route('support.index'))->assertOk()->getContent(), 'data-ticket-row=');

        $this->assertSame(3, $rows($this->owner));
        $this->assertSame(1, $rows($this->cashier));
        $this->assertSame(1, $rows($this->helper));
        $this->assertSame(1, $rows($this->otherOwner));

        $this->actingAs($this->owner)->get(route('support.index'))->assertSee('From the helper')->assertSee('Opened by')->assertDontSee('From another shop');
        $this->actingAs($this->cashier)->get(route('support.index'))->assertSee('From the cashier')->assertDontSee('From the helper')->assertDontSee('Opened by');
    }

    public function test_a_ticket_page_opens_only_for_people_who_can_see_it(): void
    {
        $ticket = $this->open($this->helper);

        $this->actingAs($this->helper)->get(route('support.show', $ticket))->assertOk()->assertSee('Complete Sale does nothing');
        $this->actingAs($this->owner)->get(route('support.show', $ticket))->assertOk();
        $this->actingAs($this->cashier)->get(route('support.show', $ticket))->assertNotFound();
        $this->actingAs($this->otherOwner)->get(route('support.show', $ticket))->assertNotFound();
    }

    public function test_photos_are_served_only_to_people_who_can_see_the_ticket(): void
    {
        $ticket = $this->open($this->helper, 'Look', [UploadedFile::fake()->image('screen.png')]);
        $attachment = $ticket->messages()->sole()->attachments()->sole();

        $this->actingAs($this->helper)->get(route('support.attachment', $attachment))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->owner)->get(route('support.attachment', $attachment))->assertOk();
        $this->actingAs($this->cashier)->get(route('support.attachment', $attachment))->assertNotFound();
        $this->actingAs($this->otherOwner)->get(route('support.attachment', $attachment))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.support.attachment', $attachment))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.support.attachment', $attachment))->assertForbidden();

        auth()->logout();
        $this->get(route('support.attachment', $attachment))->assertRedirect(route('login'));
    }

    public function test_a_reply_from_support_shows_as_unread_until_the_ticket_is_viewed(): void
    {
        $ticket = $this->open($this->cashier);
        $this->travel(1)->minutes();

        $this->actingAs($this->admin);
        Livewire::test('admin.support.show', ['ticket' => $ticket])
            ->set('body', 'Please refresh the page and try again.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertTrue($ticket->fresh()->messages->last()->from_admin);
        $this->assertTrue($ticket->fresh()->isUnreadForShop());

        $this->actingAs($this->cashier)->get(route('profile'))->assertSee('data-support-unread="1"', false);
        $this->actingAs($this->cashier)->get(route('support.index'))->assertSee('New reply');

        $this->travel(1)->minutes();
        $this->actingAs($this->cashier)->get(route('support.show', $ticket))->assertOk()->assertSee('Please refresh the page and try again.')->assertSee(config('app.name').' Support');

        $this->assertFalse($ticket->fresh()->isUnreadForShop());
        $this->actingAs($this->cashier)->get(route('profile'))->assertDontSee('data-support-unread', false);
    }

    public function test_the_opener_gets_one_toast_per_reply_and_others_get_none(): void
    {
        $ticket = $this->open($this->cashier);
        $this->travel(1)->minutes();
        $this->reply($ticket, $this->admin, 'Fixed now');

        $this->actingAs($this->owner)->get(route('profile'))->assertDontSee('data-support-reply-notice', false);

        $this->actingAs($this->cashier)->get(route('profile'))->assertSee('data-support-reply-notice', false)->assertSee('Support replied to your ticket #'.$ticket->id);
        $this->actingAs($this->cashier)->get(route('profile'))->assertDontSee('data-support-reply-notice', false);

        $this->travel(1)->minutes();
        $this->reply($ticket, $this->admin, 'One more thing');
        $this->actingAs($this->cashier)->get(route('profile'))->assertSee('data-support-reply-notice', false);
    }

    public function test_a_shop_reply_on_a_resolved_ticket_reopens_it(): void
    {
        $ticket = $this->open($this->cashier);

        $this->actingAs($this->admin);
        Livewire::test('admin.support.show', ['ticket' => $ticket])->set('status', 'resolved')->assertHasNoErrors();

        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::Resolved, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertFalse($ticket->needsAdminAttention());

        $this->travel(1)->minutes();
        $this->actingAs($this->cashier);
        Livewire::test('support.show', ['ticket' => $ticket])
            ->assertSee('Reply and reopen')
            ->set('body', 'It happened again today.')
            ->set('newImages', [UploadedFile::fake()->image('again.jpg')])
            ->call('send')
            ->assertHasNoErrors();

        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::Open, $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertTrue($ticket->needsAdminAttention());
        $this->assertCount(1, $ticket->messages->last()->attachments);
    }

    public function test_a_reply_may_be_photos_only_but_not_empty(): void
    {
        $ticket = $this->open($this->cashier);
        $this->actingAs($this->cashier);

        Livewire::test('support.show', ['ticket' => $ticket])->call('send')->assertHasErrors(['body']);

        Livewire::test('support.show', ['ticket' => $ticket])
            ->set('newImages', [UploadedFile::fake()->image('only.jpg')])
            ->call('send')
            ->assertHasNoErrors();

        $this->assertNull($ticket->fresh()->messages->last()->body);
    }

    public function test_an_admin_reply_leaves_the_status_alone_and_status_changes_track_resolved_at(): void
    {
        $ticket = $this->open($this->cashier);
        $this->actingAs($this->admin);

        $page = Livewire::test('admin.support.show', ['ticket' => $ticket])->set('status', 'in_progress');
        $this->assertSame(SupportTicketStatus::InProgress, $ticket->fresh()->status);

        $page->set('body', 'Looking into it')->call('send');
        $this->assertSame(SupportTicketStatus::InProgress, $ticket->fresh()->status);

        $page->set('status', 'resolved');
        $this->assertNotNull($ticket->fresh()->resolved_at);

        $page->set('status', 'open');
        $this->assertNull($ticket->fresh()->resolved_at);

        $page->set('status', 'closed')->assertHasErrors(['status']);
    }

    public function test_the_admin_list_covers_every_shop_and_filters(): void
    {
        $mine = $this->open($this->cashier, 'Printer will not print');
        $theirs = $this->open($this->otherOwner, 'Cannot add a product');
        $this->actingAs($this->admin);
        $theirs->update(['status' => SupportTicketStatus::Resolved]);

        $this->get(route('admin.support'))->assertOk()->assertSee('Printer will not print')->assertDontSee('Cannot add a product');
        $this->get(route('admin.support', ['status' => 'all']))->assertSee('Printer will not print')->assertSee('Cannot add a product')->assertSee('Ali Mobiles')->assertSee('Other Shop');
        $this->get(route('admin.support', ['status' => 'all', 'shop' => $theirs->shop_id]))->assertDontSee('Printer will not print')->assertSee('Cannot add a product');
        $this->get(route('admin.support', ['status' => 'all', 'q' => 'printer']))->assertSee('Printer will not print')->assertDontSee('Cannot add a product');
        $this->get(route('admin.support', ['status' => 'all', 'q' => '#'.$theirs->id]))->assertSee('Cannot add a product')->assertDontSee('Printer will not print');

        $this->get(route('admin.support.show', $mine))->assertOk()->assertSee('Cashier Bilal')->assertSee('Chrome on Android');
        $this->assertNotNull($mine->fresh()->admin_read_at);
    }

    public function test_the_admin_sidebar_counts_tickets_waiting_on_a_reply(): void
    {
        $ticket = $this->open($this->cashier);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('data-support-waiting="1"', false);

        $this->actingAs($this->admin)->get(route('admin.support.show', $ticket))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertDontSee('data-support-waiting', false);
    }

    public function test_shop_users_cannot_reach_the_admin_support_pages_and_the_admin_uses_its_own(): void
    {
        $ticket = $this->open($this->owner);

        $this->actingAs($this->owner)->get(route('admin.support'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.support.show', $ticket))->assertForbidden();
        $this->actingAs($this->admin)->get(route('support.index'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_opening_tickets_is_rate_limited(): void
    {
        for ($i = 0; $i < config('support.tickets_per_hour'); $i++) {
            $this->open($this->cashier, 'Ticket '.$i);
        }

        $this->expectException(RuntimeException::class);
        $this->open($this->cashier, 'One too many');
    }

    public function test_the_alert_email_can_be_turned_off_and_carries_the_details(): void
    {
        config(['support.alert_email' => '']);
        $this->open($this->cashier);
        Mail::assertNothingQueued();

        config(['support.alert_email' => 'info@hisabokitab.com']);
        $ticket = $this->open($this->cashier, 'Stock count is off', [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')], '/stock-ins/history', 'Stock In History');

        $html = (new NewSupportTicketMail($ticket))->render();
        $this->assertStringContainsString('Ali Mobiles', $html);
        $this->assertStringContainsString('Cashier Bilal', $html);
        $this->assertStringContainsString('Stock In History', $html);
        $this->assertStringContainsString('Stock count is off', $html);
        $this->assertStringContainsString('2 photos attached', $html);
        $this->assertStringContainsString(route('admin.support.show', $ticket), $html);
    }

    public function test_the_device_is_described_briefly(): void
    {
        $this->assertSame('Chrome on Android', SupportTicket::describeDevice('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36'));
        $this->assertSame('Edge on Windows', SupportTicket::describeDevice('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0'));
        $this->assertSame('Safari on iPhone', SupportTicket::describeDevice('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertNull(SupportTicket::describeDevice(null));
    }
}
