<?php

namespace App\Models;

use App\Enums\JobItemKind;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** One unit on a job card, with the details its kind asks for (JobItemKind::fields). */
class JobCardItem extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'job_card_id', 'kind', 'label', 'details', 'problem', 'findings'];

    protected function casts(): array
    {
        return ['kind' => JobItemKind::class, 'details' => 'array'];
    }

    public function displayLabel(): string
    {
        $key = $this->details['reg_no'] ?? $this->details['serial'] ?? null;

        return $this->label.(filled($key) ? ' ('.$key.')' : '');
    }
}
