{{-- The user's own account, in one order wherever it appears (/profile and the
     Settings "Profile" tab). --}}
<div {{ $attributes->class(['space-y-8']) }}>
    <x-profile.section title="Profile photo" description="How you appear to your team in the app.">
        <livewire:profile.update-avatar-form />
    </x-profile.section>

    <x-profile.section title="Profile Information" description="Your name, and the email you log in with.">
        <livewire:profile.update-profile-information-form />
    </x-profile.section>

    <x-profile.section title="Update Password" description="Use a long, random password to keep your account secure.">
        <livewire:profile.update-password-form />
    </x-profile.section>

    @if (\App\Enums\SocialProvider::configured() !== [])
        <x-profile.section title="Connected accounts" description="Log in with Google or Microsoft instead of typing your password.">
            <livewire:profile.connected-accounts />
        </x-profile.section>
    @endif

    <x-profile.section title="Delete Account" description="Permanently removes your login. This can't be undone." danger>
        <livewire:profile.delete-user-form />
    </x-profile.section>
</div>
