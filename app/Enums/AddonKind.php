<?php

namespace App\Enums;

enum AddonKind: string
{
    case LimitPack = 'limit_pack';
    case FeatureUnlock = 'feature_unlock';

    public function label(): string
    {
        return match ($this) {
            self::LimitPack => 'Extra allowance (one-off pack)',
            self::FeatureUnlock => 'Feature unlock (recurring)',
        };
    }
}
