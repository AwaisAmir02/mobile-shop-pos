<?php

namespace App\Enums;

use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

/**
 * What a product is sold by. Pieces stay whole numbers; everything else takes
 * up to three decimals. Metre, yard and gaz are all lengths but are never
 * converted or added together, because shops price them separately.
 */
enum QuantityUnit: string
{
    case Piece = 'piece';
    case Kg = 'kg';
    case Litre = 'litre';
    case Metre = 'metre';
    case Yard = 'yard';
    case Gaz = 'gaz';

    /** Mobiles and SIMs are one tracked item per row, so they only sell by the piece. */
    public const PIECE_ONLY_TYPES = ['mobile', 'sim'];

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom((string) $value) ?? self::Piece;
    }

    /** A unit as someone might type it in a spreadsheet; null when it isn't one. */
    public static function fromInput(string $input): ?self
    {
        return match (mb_strtolower(trim($input))) {
            '', 'piece', 'pieces', 'pc', 'pcs' => self::Piece,
            'kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms' => self::Kg,
            'l', 'ltr', 'litre', 'litres', 'liter', 'liters' => self::Litre,
            'm', 'mtr', 'metre', 'metres', 'meter', 'meters' => self::Metre,
            'yd', 'yard', 'yards' => self::Yard,
            'gaz' => self::Gaz,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'Piece',
            self::Kg => 'Kilogram (kg)',
            self::Litre => 'Litre',
            self::Metre => 'Metre',
            self::Yard => 'Yard',
            self::Gaz => 'Gaz',
        };
    }

    public function symbol(): string
    {
        return $this->value;
    }

    public function allowsDecimals(): bool
    {
        return $this !== self::Piece;
    }

    /** "1.5 kg"; a piece count is just the number, exactly as it has always read. */
    public function amount(mixed $quantity): string
    {
        return $this->allowsDecimals() ? Quantity::plain($quantity).' '.$this->symbol() : Quantity::plain($quantity);
    }

    /** amount() with thousands separators. */
    public function groupedAmount(mixed $quantity): string
    {
        return $this->allowsDecimals() ? Quantity::grouped($quantity).' '.$this->symbol() : Quantity::grouped($quantity);
    }

    /** " / kg" after a price; nothing for a piece. */
    public function perSuffix(): string
    {
        return $this->allowsDecimals() ? ' / '.$this->symbol() : '';
    }

    public function inputStep(): string
    {
        return $this->allowsDecimals() ? '0.001' : '1';
    }

    public function inputMin(): string
    {
        return $this->allowsDecimals() ? '0.001' : '1';
    }

    /** @return array<int, string> for a quantity being sold or stocked: above zero */
    public function quantityRules(): array
    {
        return $this->allowsDecimals()
            ? ['required', 'numeric', 'decimal:0,'.Quantity::DECIMALS, 'min:0.001', 'max:'.Quantity::MAX]
            : ['required', 'integer', 'min:1', 'max:999999999'];
    }

    /** @return array<int, string> for a stock level: zero is fine */
    public function stockRules(): array
    {
        return $this->allowsDecimals()
            ? ['required', 'numeric', 'decimal:0,'.Quantity::DECIMALS, 'min:0', 'max:'.Quantity::MAX]
            : ['required', 'integer', 'min:0', 'max:999999999'];
    }

    /**
     * The same rule as quantityRules(), for the Actions: a screen's check can
     * be sidestepped by a crafted request, so nothing gets past here.
     */
    public function assertQuantity(mixed $quantity, string $errorKey, string $name = 'This item'): void
    {
        if (! is_numeric($quantity) || Quantity::compare($quantity, 0) <= 0 || (float) $quantity > Quantity::MAX || ! Quantity::fitsScale($quantity)) {
            throw ValidationException::withMessages([$errorKey => "{$name} needs a quantity above zero, with at most ".Quantity::DECIMALS.' decimal places.']);
        }

        if (! $this->allowsDecimals() && ! Quantity::isWhole($quantity)) {
            throw ValidationException::withMessages([$errorKey => "{$name} is sold by the piece, so its quantity must be a whole number."]);
        }
    }

    /**
     * "12.5 kg · 3 litre", in a fixed order, for totals that must never be
     * added across units. Empty when there are none.
     *
     * @param  array<string, int|float>  $totals  unit value => total
     */
    public static function summarize(array $totals): string
    {
        return collect(self::cases())
            ->filter(fn (self $unit) => isset($totals[$unit->value]) && Quantity::compare($totals[$unit->value], 0) !== 0)
            ->map(fn (self $unit) => $unit->groupedAmount($totals[$unit->value]))
            ->implode(' · ');
    }
}
