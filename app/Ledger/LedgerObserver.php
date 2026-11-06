<?php

namespace App\Ledger;

use App\Models\LedgerOutbox;
use Illuminate\Database\Eloquent\Model;

/**
 * Notes that a document changed, in the same transaction as the change, so an
 * event can't be lost. It does nothing else: a posting problem can never fail a
 * sale. With the ledger off this is one remembered check and no writes.
 */
class LedgerObserver
{
    public function saved(Model $model): void
    {
        $this->mark($model);
    }

    public function deleted(Model $model): void
    {
        $this->mark($model);
    }

    protected function mark(Model $model): void
    {
        $shopId = $model->getAttribute('shop_id');

        if (! app(LedgerGate::class)->isPosting($shopId)) {
            return;
        }

        $document = LedgerSources::documentFor($model);

        if ($document !== null) {
            LedgerOutbox::mark((int) $shopId, $document[0], $document[1]);
        }
    }
}
