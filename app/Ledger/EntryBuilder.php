<?php

namespace App\Ledger;

use Illuminate\Database\Eloquent\Model;

/**
 * Says what a document should look like in the ledger, from its current state.
 * Reads only. Return nothing for a document that shouldn't post, such as a
 * cancelled one: the poster then reverses whatever it had. Lines must balance
 * for each date.
 */
interface EntryBuilder
{
    /** @return array<int, Line> */
    public function lines(Model $document): array;

    public function description(Model $document): string;
}
