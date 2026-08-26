<?php

namespace App\Enums;

enum UdhaarTransactionType: string
{
    case Given = 'given';
    case Repayment = 'repayment';
    case GivenReversal = 'given_reversal';
    case RepaymentReversal = 'repayment_reversal';

    public function label(): string
    {
        return match ($this) {
            self::Given => 'Udhaar Given',
            self::Repayment => 'Repayment',
            self::GivenReversal => 'Reversal of Udhaar Given',
            self::RepaymentReversal => 'Reversal of Repayment',
        };
    }

    /**
     * +1 increases what the customer owes, -1 decreases it.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Given, self::RepaymentReversal => 1,
            self::Repayment, self::GivenReversal => -1,
        };
    }

    public function isReversal(): bool
    {
        return match ($this) {
            self::GivenReversal, self::RepaymentReversal => true,
            self::Given, self::Repayment => false,
        };
    }

    public function reversalTypeFor(): self
    {
        return match ($this) {
            self::Given => self::GivenReversal,
            self::Repayment => self::RepaymentReversal,
            default => throw new \LogicException('Only Given or Repayment transactions can be reversed.'),
        };
    }
}
