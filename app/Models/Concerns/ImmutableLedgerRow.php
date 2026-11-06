<?php

namespace App\Models\Concerns;

use LogicException;

/** The journal is append-only. A correction is a new entry, never a change to an old one. */
trait ImmutableLedgerRow
{
    protected static function bootImmutableLedgerRow(): void
    {
        static::updating(fn () => throw new LogicException('Ledger rows can\'t be changed. Post a new entry.'));
        static::deleting(fn () => throw new LogicException('Ledger rows can\'t be deleted. Post a new entry.'));
    }
}
