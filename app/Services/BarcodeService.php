<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Picqer\Barcode\BarcodeGeneratorPNG;

class BarcodeService
{
    public const MAX_LABEL_COPIES = 200;

    public const BULK_RUN_LIMIT = 200;

    public const BULK_CHUNK_SIZE = 50;

    private const GENERATE_ATTEMPTS = 5;

    private const DUPLICATE_KEY_ERROR = 1062;

    public static function isEan13(string $value): bool
    {
        if (! preg_match('/^\d{13}$/', $value)) {
            return false;
        }

        return self::checkDigitFor(substr($value, 0, 12)) === (int) substr($value, 12, 1);
    }

    public static function checkDigitFor(string $twelveDigits): int
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * GS1 reserves 20–29 for in-store / restricted-circulation numbering, so
     * these can never collide with a manufacturer's barcode — including the
     * ones a shop may scan into other products.
     */
    public static function candidate(): string
    {
        $body = '2'.random_int(0, 9).str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);

        return $body.self::checkDigitFor($body);
    }

    public static function generateUniqueFor(int $shopId, ?int $ignoreProductId = null): string
    {
        for ($attempt = 0; $attempt < self::GENERATE_ATTEMPTS; $attempt++) {
            $candidate = self::candidate();

            $taken = Product::withoutGlobalScopes()
                ->where('shop_id', $shopId)
                ->where('barcode', $candidate)
                ->when($ignoreProductId, fn ($q) => $q->whereKeyNot($ignoreProductId))
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate a free barcode. Please try again.');
    }

    /**
     * Race-safe: the unique (shop_id, barcode) index is the real guard. A
     * conflicting candidate (another request just took it) is retried with
     * a fresh one rather than failing the whole run.
     *
     * @return array{assigned: int, remaining: int}
     */
    public static function assignMissing(int $shopId, int $limit = self::BULK_RUN_LIMIT): array
    {
        $assigned = 0;
        $budget = min($limit, self::BULK_RUN_LIMIT);

        while ($budget > 0) {
            $chunk = Product::withoutGlobalScopes()
                ->where('shop_id', $shopId)
                ->whereNull('barcode')
                ->orderBy('id')
                ->limit(min(self::BULK_CHUNK_SIZE, $budget))
                ->pluck('id');

            if ($chunk->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($chunk, &$assigned) {
                foreach ($chunk as $productId) {
                    if (self::assignToProduct((int) $productId)) {
                        $assigned++;
                    }
                }
            });

            $budget -= $chunk->count();
        }

        $remaining = Product::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('barcode')->count();

        return ['assigned' => $assigned, 'remaining' => $remaining];
    }

    public static function assignToProduct(int $productId): bool
    {
        for ($attempt = 0; $attempt < self::GENERATE_ATTEMPTS; $attempt++) {
            try {
                return Product::withoutGlobalScopes()
                    ->whereKey($productId)
                    ->whereNull('barcode')
                    ->update(['barcode' => self::candidate()]) === 1;
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== self::DUPLICATE_KEY_ERROR) {
                    throw $e;
                }
            }
        }

        return false;
    }

    public static function duplicateMessageFor(int $shopId, string $barcode, ?int $ignoreProductId = null): ?string
    {
        $existing = Product::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('barcode', $barcode)
            ->when($ignoreProductId, fn ($q) => $q->whereKeyNot($ignoreProductId))
            ->first(['id', 'name']);

        return $existing ? "Barcode already used by '{$existing->name}'." : null;
    }

    public static function isPrintable(string $value): bool
    {
        return $value !== '' && strlen($value) <= 64 && preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    public static function imageDataUri(string $value): string
    {
        $generator = new BarcodeGeneratorPNG;
        $type = self::isEan13($value) ? BarcodeGeneratorPNG::TYPE_EAN_13 : BarcodeGeneratorPNG::TYPE_CODE_128;

        return 'data:image/png;base64,'.base64_encode($generator->getBarcode($value, $type, 2, 60));
    }
}
