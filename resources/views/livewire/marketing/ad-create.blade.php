<?php

use App\Enums\AdGoal;
use App\Jobs\BuildMetaAdCampaign;
use App\Livewire\Concerns\Toasts;
use App\Models\MarketingPost;
use App\Services\Marketing\MetaAdsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('New Ad')] class extends Component
{
    use Toasts;

    #[Url]
    public int $post = 0;

    public string $goal = 'whatsapp_messages';
    public string $dailyBudget = '';
    public string $startDate = '';
    public string $days = '7';
    public string $locationMode = 'cities';
    public string $citySearch = '';
    public array $cityResults = [];
    public array $cities = [];
    public string $cityRadius = '15';
    public string $latitude = '';
    public string $longitude = '';
    public string $radiusKm = '5';
    public string $ageMin = '18';
    public string $ageMax = '55';
    public string $gender = 'all';
    public bool $whatsappStatus = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->ads_enabled, 403);
        MarketingPost::findOrFail($this->post);

        $adAccount = MetaAdsManager::adAccount(Auth::user()->shop_id);
        $this->dailyBudget = (string) max(500, (int) ceil($adAccount ? MetaAdsManager::minDailyBudget($adAccount) : 500));
        $this->startDate = now()->toDateString();
    }

    public function updatedCitySearch(): void
    {
        $ads = app(MetaAdsManager::class);
        $adAccount = MetaAdsManager::adAccount(Auth::user()->shop_id);

        if ($adAccount === null || mb_strlen(trim($this->citySearch)) < 2) {
            $this->cityResults = [];

            return;
        }

        try {
            $this->cityResults = $ads->searchCities($adAccount, trim($this->citySearch));
        } catch (\RuntimeException $e) {
            $this->cityResults = [];
            $this->addError('citySearch', $e->getMessage());
        }
    }

    public function addCity(string $key): void
    {
        $city = collect($this->cityResults)->firstWhere('key', $key);

        if ($city && ! collect($this->cities)->contains('key', $key) && count($this->cities) < 10) {
            $this->cities[] = $city;
        }

        $this->citySearch = '';
        $this->cityResults = [];
    }

    public function removeCity(string $key): void
    {
        $this->cities = collect($this->cities)->reject(fn ($c) => $c['key'] === $key)->values()->all();
    }

    public function maxSpend(): float
    {
        return round(max(0, (float) $this->dailyBudget) * max(0, (int) $this->days), 2);
    }

    public function create(MetaAdsManager $ads): void
    {
        abort_unless(Auth::user()->shop?->ads_enabled && Auth::user()->hasAccessTo('marketing-ads'), 403);

        $this->validate([
            'goal' => ['required', 'in:'.implode(',', array_column(AdGoal::cases(), 'value'))],
            'dailyBudget' => ['required', 'numeric', 'min:1'],
            'startDate' => ['required', 'date', 'after_or_equal:today', 'before:'.now()->addDays(60)->toDateString()],
            'days' => ['required', 'integer', 'min:'.MetaAdsManager::MIN_DAYS, 'max:'.MetaAdsManager::MAX_DAYS],
            'locationMode' => ['required', 'in:country,cities,radius'],
            'cities' => ['required_if:locationMode,cities', 'array', 'max:10'],
            'cityRadius' => ['required_if:locationMode,cities', 'nullable', 'integer', 'min:10', 'max:80'],
            'latitude' => ['required_if:locationMode,radius', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:locationMode,radius', 'nullable', 'numeric', 'between:-180,180'],
            'radiusKm' => ['required_if:locationMode,radius', 'nullable', 'integer', 'min:1', 'max:80'],
            'ageMin' => ['required', 'integer', 'min:18', 'max:65'],
            'ageMax' => ['required', 'integer', 'gte:ageMin', 'max:65'],
            'gender' => ['required', 'in:all,male,female'],
        ], ['cities.required_if' => 'Add at least one city.']);

        $start = Carbon::parse($this->startDate)->isToday() ? now()->addMinutes(10) : Carbon::parse($this->startDate)->startOfDay();
        $end = $start->copy()->addDays((int) $this->days);

        $targeting = [
            'mode' => $this->locationMode,
            'countries' => ['PK'],
            'cities' => $this->locationMode === 'cities' ? $this->cities : [],
            'city_radius_km' => (int) $this->cityRadius,
            'latitude' => $this->locationMode === 'radius' ? (float) $this->latitude : null,
            'longitude' => $this->locationMode === 'radius' ? (float) $this->longitude : null,
            'radius_km' => (int) $this->radiusKm,
            'age_min' => (int) $this->ageMin,
            'age_max' => (int) $this->ageMax,
            'gender' => $this->gender,
            'whatsapp_status' => $this->whatsappStatus,
        ];

        $campaign = $ads->createDraft(Auth::user()->shop, Auth::id(), MarketingPost::findOrFail($this->post), AdGoal::from($this->goal), (float) $this->dailyBudget, $start, $end, $targeting);

        BuildMetaAdCampaign::dispatch($campaign->id);

        $this->toastSuccess('Setting up your ad on Meta. Nothing is spent until you press Launch.');
        $this->redirectRoute('marketing.ads.show', $campaign, navigate: true);
    }

    public function with(): array
    {
        $adAccount = MetaAdsManager::adAccount(Auth::user()->shop_id);

        return [
            'postModel' => MarketingPost::findOrFail($this->post),
            'adAccount' => $adAccount,
            'currency' => $adAccount?->meta['currency'] ?? 'PKR',
            'minBudget' => $adAccount ? MetaAdsManager::minDailyBudget($adAccount) : null,
            'statusAvailable' => AdGoal::tryFrom($this->goal) !== null && MetaAdsManager::whatsappStatusAvailable(Auth::user()->shop_id, AdGoal::from($this->goal)),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Marketing</h1>
    </x-slot>

    <x-marketing.nav active="marketing.ads" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form wire:submit="create" class="space-y-6">
                <x-ui.card title="Goal">
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (AdGoal::cases() as $option)
                            <label @class(['flex cursor-pointer flex-col gap-1 rounded-lg border p-4', 'border-brand-500 ring-1 ring-brand-500' => $goal === $option->value, 'border-slate-200' => $goal !== $option->value])>
                                <span class="flex items-center gap-2 font-medium text-slate-900"><input type="radio" wire:model.live="goal" value="{{ $option->value }}" class="text-brand-600 focus:ring-brand-500"> {{ $option->label() }}</span>
                                <span class="text-xs text-slate-500">{{ $option->description() }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-ui.error :messages="$errors->get('goal')" class="mt-2" />
                    @if ($statusAvailable)
                        <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-4">
                            <input type="checkbox" wire:model="whatsappStatus" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="block font-medium text-slate-900">Also show in WhatsApp Status</span>
                                <span class="block text-xs text-slate-500">Your ad also appears as a Sponsored update in people's WhatsApp Status, alongside Instagram Stories. Same budget — Meta splits it across placements.</span>
                            </span>
                        </label>
                    @endif
                </x-ui.card>

                <x-ui.card title="Budget & dates">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.field label="Daily budget ({{ $currency }})" name="dailyBudget" for="dailyBudget" :help="$minBudget ? 'Meta minimum: '.number_format($minBudget, 2) : null">
                            <x-ui.input wire:model.live.debounce.400ms="dailyBudget" id="dailyBudget" type="number" min="1" step="1" />
                        </x-ui.field>
                        <x-ui.field label="Start" name="startDate" for="startDate">
                            <x-ui.input wire:model="startDate" id="startDate" type="date" min="{{ now()->toDateString() }}" />
                        </x-ui.field>
                        <x-ui.field label="Days" name="days" for="days" :help="'1 to '.MetaAdsManager::MAX_DAYS">
                            <x-ui.input wire:model.live.debounce.400ms="days" id="days" type="number" min="1" max="{{ MetaAdsManager::MAX_DAYS }}" />
                        </x-ui.field>
                    </div>
                    <x-ui.error :messages="$errors->get('endDate')" class="mt-2" />
                </x-ui.card>

                <x-ui.card title="Who sees it">
                    <div class="space-y-4">
                        <x-ui.field label="Location" name="locationMode" for="locationMode">
                            <x-ui.select wire:model.live="locationMode" id="locationMode">
                                <option value="cities">Specific cities</option>
                                <option value="radius">Around my shop</option>
                                <option value="country">All of Pakistan</option>
                            </x-ui.select>
                        </x-ui.field>

                        @if ($locationMode === 'cities')
                            <div>
                                <x-ui.field label="Add cities" name="citySearch" for="citySearch">
                                    <x-ui.input wire:model.live.debounce.400ms="citySearch" id="citySearch" placeholder="e.g. Lahore" autocomplete="off" />
                                </x-ui.field>
                                @if ($cityResults !== [])
                                    <div class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200">
                                        @foreach ($cityResults as $city)
                                            <button type="button" wire:click="addCity('{{ $city['key'] }}')" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50">{{ $city['name'] }}</button>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($cities as $city)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-800">
                                            {{ $city['name'] }}
                                            <button type="button" wire:click="removeCity('{{ $city['key'] }}')" class="text-brand-600 hover:text-brand-900" aria-label="Remove {{ $city['name'] }}">×</button>
                                        </span>
                                    @endforeach
                                </div>
                                <x-ui.error :messages="$errors->get('cities')" class="mt-1" />
                            </div>
                            <x-ui.field label="Include area around each city (km)" name="cityRadius" for="cityRadius" class="sm:max-w-[14rem]">
                                <x-ui.input wire:model="cityRadius" id="cityRadius" type="number" min="10" max="80" />
                            </x-ui.field>
                        @elseif ($locationMode === 'radius')
                            <div class="grid gap-4 sm:grid-cols-3">
                                <x-ui.field label="Latitude" name="latitude" for="latitude"><x-ui.input wire:model="latitude" id="latitude" placeholder="31.5204" /></x-ui.field>
                                <x-ui.field label="Longitude" name="longitude" for="longitude"><x-ui.input wire:model="longitude" id="longitude" placeholder="74.3587" /></x-ui.field>
                                <x-ui.field label="Radius (km)" name="radiusKm" for="radiusKm"><x-ui.input wire:model="radiusKm" id="radiusKm" type="number" min="1" max="80" /></x-ui.field>
                            </div>
                            <p class="text-xs text-slate-500">Tip: long-press your shop in Google Maps to copy its coordinates.</p>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-ui.field label="Age from" name="ageMin" for="ageMin"><x-ui.input wire:model="ageMin" id="ageMin" type="number" min="18" max="65" /></x-ui.field>
                            <x-ui.field label="Age to" name="ageMax" for="ageMax"><x-ui.input wire:model="ageMax" id="ageMax" type="number" min="18" max="65" /></x-ui.field>
                            <x-ui.field label="Gender" name="gender" for="gender">
                                <x-ui.select wire:model="gender" id="gender">
                                    <option value="all">Everyone</option>
                                    <option value="male">Men</option>
                                    <option value="female">Women</option>
                                </x-ui.select>
                            </x-ui.field>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.button type="submit" wire:loading.attr="disabled" :disabled="! $adAccount">Set up ad (doesn't spend yet)</x-ui.button>
            </form>
        </div>

        <div class="space-y-4">
            <x-ui.card title="Ad preview" :padding="false">
                <img src="{{ $postModel->imageUrl() }}" alt="Ad image" class="aspect-square w-full rounded-t-xl object-cover">
                <p class="whitespace-pre-line p-4 text-xs text-slate-600">{{ \Illuminate\Support\Str::limit($postModel->caption, 300) }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Maximum you can be charged</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $currency }} {{ number_format($this->maxSpend(), 2) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $currency }} {{ number_format((float) $dailyBudget) }} × {{ (int) $days }} days, billed by Meta to your own ad account. You confirm this again before the ad starts.</p>
            </x-ui.card>
        </div>
    </div>
</div>
