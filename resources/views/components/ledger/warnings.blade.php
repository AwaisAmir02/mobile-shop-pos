@props(['failed' => 0, 'noCost' => 0])

@if ($failed > 0)
    <div class="mb-4 rounded-xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm text-danger">
        {{ $failed }} {{ str('record')->plural($failed) }} couldn't post to the ledger, so these figures are incomplete. The records themselves are safe.
    </div>
@endif

@if ($noCost > 0)
    <div class="mb-4 rounded-xl border border-warning/20 bg-warning-soft px-4 py-3 text-sm text-warning">
        {{ number_format($noCost) }} sold {{ str('line')->plural($noCost) }} have no cost, so profit looks higher than it was.
    </div>
@endif
