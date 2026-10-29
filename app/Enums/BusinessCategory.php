<?php

namespace App\Enums;

enum BusinessCategory: string
{
    case MobileElectronics = 'mobile_electronics';
    case Kiryana = 'kiryana';
    case Bakery = 'bakery';
    case Clothing = 'clothing';
    case Pharmacy = 'pharmacy';
    case OnlineSeller = 'online_seller';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MobileElectronics => 'Mobile & electronics shop',
            self::Kiryana => 'Kiryana / general store',
            self::Bakery => 'Bakery & sweets',
            self::Clothing => 'Clothing & garments',
            self::Pharmacy => 'Pharmacy / medical store',
            self::OnlineSeller => 'Online seller (Daraz / social media)',
            self::Other => 'Other',
        };
    }

    public function showsMobileModules(): bool
    {
        return $this === self::MobileElectronics;
    }

    public function starterCategories(): array
    {
        return match ($this) {
            self::Kiryana => ['Grocery', 'Household'],
            self::Bakery => ['Bakery', 'Sweets'],
            self::Clothing => ['Men', 'Women', 'Kids'],
            self::Pharmacy => ['Medicines', 'Personal care'],
            default => [],
        };
    }
}
