<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class LedgerAccount extends Model
{
    use BelongsToShop;

    public const ASSET = 'asset';
    public const LIABILITY = 'liability';
    public const EQUITY = 'equity';
    public const INCOME = 'income';
    public const EXPENSE = 'expense';

    protected $fillable = ['shop_id', 'code', 'name', 'type', 'shop_account_id'];
}
