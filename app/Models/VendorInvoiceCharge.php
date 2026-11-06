<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** Delivery, round-off or a discount (negative): on the bill, but not tied to one product. */
class VendorInvoiceCharge extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $fillable = ['shop_id', 'vendor_invoice_id', 'label', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
