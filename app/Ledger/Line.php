<?php

namespace App\Ledger;

use Carbon\CarbonInterface;

/** One line a document should have in the ledger. Debit is positive, credit negative, in whole paise. */
final class Line
{
    public function __construct(
        public readonly string $account,
        public readonly int $paise,
        public readonly string $date,
        public readonly ?int $branchId = null,
        public readonly ?string $partyType = null,
        public readonly ?int $partyId = null,
    ) {}

    public static function debit(string $account, mixed $amount, CarbonInterface|string $date, ?int $branchId = null, ?string $partyType = null, ?int $partyId = null): self
    {
        return new self($account, self::paise($amount), self::day($date), $branchId, $partyType, $partyId);
    }

    public static function credit(string $account, mixed $amount, CarbonInterface|string $date, ?int $branchId = null, ?string $partyType = null, ?int $partyId = null): self
    {
        return new self($account, -self::paise($amount), self::day($date), $branchId, $partyType, $partyId);
    }

    public static function paise(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public function key(): string
    {
        return implode('|', [$this->account, $this->date, $this->branchId ?? '', $this->partyType ?? '', $this->partyId ?? '']);
    }

    public function toArray(): array
    {
        return ['account' => $this->account, 'paise' => $this->paise, 'date' => $this->date, 'branch_id' => $this->branchId, 'party_type' => $this->partyType, 'party_id' => $this->partyId];
    }

    public static function fromArray(array $line): self
    {
        return new self($line['account'], (int) $line['paise'], $line['date'], $line['branch_id'], $line['party_type'], $line['party_id']);
    }

    protected static function day(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);
    }
}
