<?php

namespace App\Services;

use App\Actions\CreateProduct;
use App\Enums\LimitKey;
use App\Enums\QuantityUnit;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Services\Billing\Entitlements;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The rules for variant groups: what a variant copies from its group, and how
 * each change moves the plan's product count (a group counts as one product,
 * however many variants it has). Variants are never given stock here; that
 * only ever comes through Stock In or the product form, like any product.
 */
class ProductGroupService
{
    public const MAX_VARIANTS_PER_RUN = 100;

    public const LABEL_SEPARATOR = ' / ';

    public function __construct(protected Entitlements $entitlements) {}

    public static function fullName(string $groupName, string $label): string
    {
        return $groupName.' - '.$label;
    }

    /** @return array<int, string> the comma-separated values, trimmed, without blanks or repeats */
    public static function parseOptions(string $values): array
    {
        return collect(explode(',', $values))
            ->map(fn (string $value) => trim(preg_replace('/\s+/', ' ', $value)))
            ->filter(fn (string $value) => $value !== '')
            ->unique(fn (string $value) => mb_strtolower($value))
            ->values()
            ->all();
    }

    /** @return array<int, string> every combination, e.g. "M / Red"; one list alone gives its own values */
    public static function combineOptions(array $first, array $second): array
    {
        if ($first === [] || $second === []) {
            return array_values(array_merge($first, $second));
        }

        $labels = [];

        foreach ($first as $a) {
            foreach ($second as $b) {
                $labels[] = $a.self::LABEL_SEPARATOR.$b;
            }
        }

        return $labels;
    }

    public function createGroup(int $shopId, string $name, string $type, string $category, ?string $imagePath = null, QuantityUnit $unit = QuantityUnit::Piece): ProductGroup
    {
        $this->assertAllowedType($type);

        return ProductGroup::create([
            'shop_id' => $shopId,
            'name' => $name,
            'type' => $type,
            'unit' => $unit,
            'details' => CreateProduct::detailsForType($type, $category),
            'image_path' => $imagePath,
        ]);
    }

    /**
     * A new variant inherits the group's WhatsApp listing, so a listed group
     * doesn't silently hide what was just added to it. Labels the group
     * already has are skipped.
     *
     * @return array<int, Product> the variants created
     */
    public function addVariants(ProductGroup $group, array $labels, float|string $price, float|string|null $costPrice): array
    {
        return DB::transaction(function () use ($group, $labels, $price, $costPrice) {
            $existing = $group->variants()->pluck('variant_label')->map(fn ($label) => mb_strtolower((string) $label))->all();
            $listed = $group->variants()->where('whatsapp_listed', true)->exists();
            $created = [];

            foreach ($labels as $label) {
                if (in_array(mb_strtolower($label), $existing, true)) {
                    continue;
                }

                $existing[] = mb_strtolower($label);

                $product = new Product([
                    'shop_id' => $group->shop_id,
                    'product_group_id' => $group->id,
                    'variant_label' => $label,
                    'type' => $group->type,
                    'name' => self::fullName($group->name, $label),
                    'price' => $price,
                    'cost_price' => $costPrice,
                    'stock_quantity' => 0,
                    'unit' => $group->unit,
                    'whatsapp_listed' => $listed,
                    'details' => $group->details,
                ]);
                $product->shop_id = $group->shop_id;
                $product->save();
                $created[] = $product;
            }

            $this->entitlements->forgetMeters($this->shopOf($group));

            return $created;
        });
    }

    /**
     * An existing product joins the group with its batches, stock, barcode
     * and price untouched. Past sales keep the name they were sold under.
     */
    public function attach(ProductGroup $group, Product $product, string $label): void
    {
        if ($product->product_group_id !== null) {
            throw ValidationException::withMessages(['attachLabels' => "{$product->name} is already in a variant group."]);
        }

        if (! $product->canHaveVariants()) {
            throw ValidationException::withMessages(['attachLabels' => "{$product->name} is IMEI-tracked, so it can't be a variant."]);
        }

        if ($product->unit !== $group->unit) {
            throw ValidationException::withMessages(['attachLabels' => "{$product->name} is sold by {$product->unit->symbol()} and this group by {$group->unit->symbol()}. Every variant in a group has to use the same unit."]);
        }

        if ($group->variants()->where('variant_label', $label)->exists()) {
            throw ValidationException::withMessages(['attachLabels' => "This group already has a variant called \"{$label}\"."]);
        }

        $product->forceFill([
            'product_group_id' => $group->id,
            'variant_label' => $label,
            'name' => self::fullName($group->name, $label),
            'type' => $group->type,
            'details' => $group->details,
        ])->saveQuietly();

        $this->entitlements->forgetMeters($this->shopOf($group));
    }

    /** Renaming or re-categorising a group rewrites every variant to match. */
    public function updateGroup(ProductGroup $group, string $name, string $type, string $category, ?QuantityUnit $unit = null): void
    {
        $this->assertAllowedType($type);

        $unit ??= $group->unit;

        if ($unit !== $group->unit) {
            $this->assertUnitCanChange($group, $unit);
        }

        DB::transaction(function () use ($group, $name, $type, $category, $unit) {
            $group->update(['name' => $name, 'type' => $type, 'unit' => $unit, 'details' => CreateProduct::detailsForType($type, $category)]);

            foreach ($group->variants()->get() as $variant) {
                $variant->forceFill([
                    'name' => self::fullName($group->name, (string) $variant->variant_label),
                    'type' => $group->type,
                    'unit' => $unit,
                    'whatsapp_listed' => $unit->allowsDecimals() ? false : $variant->whatsapp_listed,
                    'details' => $group->details,
                ])->saveQuietly();
            }
        });
    }

    /** A group's unit is locked as soon as any one variant's would be. */
    public function unitIsLocked(ProductGroup $group): bool
    {
        return $group->variants()->get()->contains(fn (Product $variant) => $variant->unitIsLocked());
    }

    protected function assertUnitCanChange(ProductGroup $group, QuantityUnit $unit): void
    {
        if ($this->unitIsLocked($group)) {
            throw ValidationException::withMessages(['groupUnit' => 'A variant in this group already has stock-in or sales records, so the unit can\'t be changed.']);
        }

        if (! $unit->allowsDecimals() && $group->variants()->get()->contains(fn (Product $variant) => ! Quantity::isWhole($variant->stock_quantity))) {
            throw ValidationException::withMessages(['groupUnit' => 'A variant has part-unit stock, so this group can\'t switch to pieces.']);
        }
    }

    /** A variant leaves its group as a normal product under its full name. */
    public function detach(Product $variant): void
    {
        $group = $variant->group;

        if ($group === null) {
            return;
        }

        $othersActive = $group->variants()->whereKeyNot($variant->id)->whereNull('archived_at')->exists();

        if ($variant->archived_at === null && $othersActive) {
            $this->entitlements->ensureCanAdd($this->shopOf($group), LimitKey::Products);
        }

        DB::transaction(function () use ($group, $variant) {
            $this->release($group, $variant);
            $this->deleteIfEmpty($group->id);
        });

        $this->entitlements->forgetMeters($this->shopOf($group));
    }

    /** Dissolves the group; every variant carries on as a normal product under its full name. */
    public function ungroup(ProductGroup $group): void
    {
        $adding = $group->variants()->whereNull('archived_at')->count() - 1;

        if ($adding > 0) {
            $this->entitlements->ensureCanAdd($this->shopOf($group), LimitKey::Products, $adding);
        }

        DB::transaction(function () use ($group) {
            foreach ($group->variants()->get() as $variant) {
                $this->release($group, $variant);
            }

            $this->deleteIfEmpty($group->id);
        });

        $this->entitlements->forgetMeters($this->shopOf($group));
    }

    /** A group with no variants left has nothing to show or sell, so it goes too. */
    public function deleteIfEmpty(?int $groupId): void
    {
        if ($groupId === null) {
            return;
        }

        $group = ProductGroup::withoutGlobalScopes()->find($groupId);

        if ($group === null || $group->variants()->exists()) {
            return;
        }

        if ($group->image_path) {
            Storage::disk('public')->delete($group->image_path);
        }

        $group->delete();
    }

    /** Whether adding to this group would add one to the plan's product count. */
    public function addingCountsTowardLimit(ProductGroup $group): bool
    {
        return ! $group->variants()->whereNull('archived_at')->exists();
    }

    /** Restoring an archived variant only adds to the count when no sibling is already counted. */
    public function restoringCountsTowardLimit(Product $product): bool
    {
        return $product->group === null || $this->addingCountsTowardLimit($product->group);
    }

    protected function release(ProductGroup $group, Product $variant): void
    {
        $attributes = ['product_group_id' => null, 'variant_label' => null];

        // Each product owns its image file (deleting one removes the file), so it gets its own copy.
        if ($variant->image_path === null && $group->image_path !== null && Storage::disk('public')->exists($group->image_path)) {
            $copy = dirname($group->image_path).'/'.Str::random(40).'.'.pathinfo($group->image_path, PATHINFO_EXTENSION);
            Storage::disk('public')->copy($group->image_path, $copy);
            $attributes['image_path'] = $copy;
        }

        $variant->forceFill($attributes)->saveQuietly();
    }

    protected function assertAllowedType(string $type): void
    {
        if (in_array($type, ProductGroup::EXCLUDED_TYPES, true)) {
            throw ValidationException::withMessages(['groupType' => "IMEI-tracked mobiles and SIMs can't have variants."]);
        }
    }

    protected function shopOf(ProductGroup $group): Shop
    {
        return Shop::findOrFail($group->shop_id);
    }
}
