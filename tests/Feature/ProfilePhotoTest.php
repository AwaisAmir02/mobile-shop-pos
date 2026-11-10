<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A user's own profile photo. Upload, replace and remove
 * from Profile; shown in the header's avatar instead of the initial.
 */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $shop = Shop::create(['name' => 'Shop A']);
        $this->user = User::factory()->create(['shop_id' => $shop->id, 'name' => 'Bilal Ahmed']);
        $this->actingAs($this->user);
    }

    public function test_a_photo_can_be_uploaded(): void
    {
        Livewire::test('profile.update-avatar-form')
            ->set('photo', UploadedFile::fake()->image('me.jpg', 300, 300))
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('avatar-updated');

        $path = $this->user->fresh()->avatar_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('avatars/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_replacing_the_photo_removes_the_old_file(): void
    {
        $page = Livewire::test('profile.update-avatar-form')
            ->set('photo', UploadedFile::fake()->image('first.png'))
            ->call('save');
        $first = $this->user->fresh()->avatar_path;

        $page->set('photo', UploadedFile::fake()->image('second.webp'))->call('save')->assertHasNoErrors();
        $second = $this->user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_removing_the_photo_goes_back_to_the_initial(): void
    {
        Livewire::test('profile.update-avatar-form')
            ->set('photo', UploadedFile::fake()->image('me.jpg'))
            ->call('save');
        $path = $this->user->fresh()->avatar_path;

        Livewire::test('profile.update-avatar-form')->call('remove')->assertDispatched('avatar-updated');

        $this->assertNull($this->user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('B', $this->user->fresh()->initial());
    }

    public function test_only_images_up_to_two_megabytes_are_accepted(): void
    {
        Livewire::test('profile.update-avatar-form')
            ->set('photo', UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'))
            ->assertHasErrors(['photo']);

        Livewire::test('profile.update-avatar-form')
            ->set('photo', UploadedFile::fake()->image('huge.jpg')->size(3000))
            ->assertHasErrors(['photo' => 'max']);

        $this->assertNull($this->user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles('avatars'));
    }

    public function test_the_header_shows_the_photo_or_the_initial(): void
    {
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('data-avatar="initial"', false);

        $this->user->update(['avatar_path' => 'avatars/me.jpg']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-avatar="photo"', false)
            ->assertSee(Storage::disk('public')->url('avatars/me.jpg'), false);
    }

    public function test_deleting_an_account_removes_its_photo(): void
    {
        Storage::disk('public')->put('avatars/me.jpg', 'x');
        $this->user->update(['avatar_path' => 'avatars/me.jpg']);

        $this->user->delete();

        Storage::disk('public')->assertMissing('avatars/me.jpg');
    }

    public function test_profile_and_settings_show_the_same_sections(): void
    {
        $this->get(route('profile'))
            ->assertOk()
            ->assertSeeInOrder(['Profile photo', 'Profile Information', 'Update Password', 'Delete Account'])
            ->assertSeeLivewire('profile.update-avatar-form');

        $this->get(route('settings.index', ['tab' => 'profile']))
            ->assertOk()
            ->assertSeeLivewire('profile.update-avatar-form')
            ->assertSeeLivewire('profile.update-profile-information-form');
    }
}
