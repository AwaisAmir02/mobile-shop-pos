<?php

namespace App\Enums;

/**
 * What kind of unit came in on a job card, which decides the details asked
 * for. Stored as details JSON on the item, so a new kind is one case here.
 */
enum JobItemKind: string
{
    case Vehicle = 'vehicle';
    case Bike = 'bike';
    case AirConditioner = 'ac';
    case Appliance = 'appliance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vehicle => 'Car / vehicle',
            self::Bike => 'Motorbike',
            self::AirConditioner => 'AC',
            self::Appliance => 'Appliance / machine',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> detail key => label, in the order asked */
    public function fields(): array
    {
        return match ($this) {
            self::Vehicle => ['reg_no' => 'Registration no.', 'make_model' => 'Make & model', 'odometer' => 'Odometer (km)', 'chassis_no' => 'Chassis no.'],
            self::Bike => ['reg_no' => 'Registration no.', 'make_model' => 'Make & model', 'odometer' => 'Odometer (km)', 'chassis_no' => 'Chassis no.'],
            self::AirConditioner => ['make_model' => 'Make & model', 'tonnage' => 'Tonnage', 'ac_type' => 'Type (split, window, inverter)', 'gas' => 'Gas (R22, R410…)', 'serial' => 'Serial no.'],
            self::Appliance => ['make_model' => 'Make & model', 'serial' => 'Serial no.'],
            self::Other => ['make_model' => 'Make & model', 'serial' => 'Serial no.'],
        };
    }
}
