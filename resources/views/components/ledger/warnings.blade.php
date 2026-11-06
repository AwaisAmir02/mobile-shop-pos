@props(['failed' => 0, 'noCost' => 0])

@if ($failed > 0)
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ $failed }} {{ str('record')->plural($failed) }} couldn't post to the ledger, so these figures are incomplete. The records themselves are safe.
    </div>
@endif

@if ($noCost > 0)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        {{ number_format($noCost) }} sold {{ str('line')->plural($noCost) }} have no cost, so profit looks higher than it was.
    </div>
@endif
