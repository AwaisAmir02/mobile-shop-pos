<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillCategory extends Model
{
    use BelongsToShop;

    public const DEFAULTS = [
        'Electricity' => [
            ['name' => 'LESCO', 'region' => 'Punjab'],
            ['name' => 'FESCO', 'region' => 'Punjab'],
            ['name' => 'GEPCO', 'region' => 'Punjab'],
            ['name' => 'MEPCO', 'region' => 'Punjab'],
            ['name' => 'IESCO', 'region' => 'Punjab/Islamabad'],
            ['name' => 'HESCO', 'region' => 'Sindh'],
            ['name' => 'SEPCO', 'region' => 'Sindh'],
            ['name' => 'K-Electric', 'region' => 'Sindh'],
            ['name' => 'PESCO', 'region' => 'Khyber Pakhtunkhwa'],
            ['name' => 'TESCO', 'region' => 'Khyber Pakhtunkhwa'],
            ['name' => 'QESCO', 'region' => 'Balochistan'],
        ],
        'Gas' => [
            ['name' => 'SSGC', 'region' => 'Sindh & Balochistan'],
            ['name' => 'SNGPL', 'region' => 'Punjab & Khyber Pakhtunkhwa'],
        ],
        'Telephone' => [
            ['name' => 'PTCL', 'region' => 'Nationwide'],
        ],
        'Water' => [
            ['name' => 'Water Board', 'region' => null],
        ],
    ];

    protected $fillable = [
        'shop_id',
        'name',
    ];

    public function billProviders(): HasMany
    {
        return $this->hasMany(BillProvider::class);
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public static function ensureDefaultsExist(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $categoryName => $providers) {
            $category = static::create(['name' => $categoryName]);

            foreach ($providers as $provider) {
                $category->billProviders()->create($provider);
            }
        }
    }
}
