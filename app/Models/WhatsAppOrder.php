<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use App\Enums\WhatsAppPaymentChoice;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppOrder extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $table = 'whatsapp_orders';

    protected $fillable = [
        'shop_id',
        'whatsapp_contact_id',
        'customer_id',
        'status',
        'fulfillment_status',
        'fulfillment_updated_at',
        'delivered_at',
        'review_requested_at',
        'quoted_total',
        'customer_note',
        'delivery_name',
        'delivery_address',
        'delivery_area',
        'delivery_phone',
        'payment_choice',
        'payment_slip_path',
        'submitted_at',
        'decided_by',
        'decided_at',
        'reject_reason',
        'sale_id',
        'origin_ref',
        'referral',
    ];

    protected function casts(): array
    {
        return [
            'status' => WhatsAppOrderStatus::class,
            'payment_choice' => WhatsAppPaymentChoice::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'fulfillment_updated_at' => 'datetime',
            'delivered_at' => 'datetime',
            'review_requested_at' => 'datetime',
            'quoted_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'customer_notified_at' => 'datetime',
            'referral' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WhatsAppOrderItem::class, 'whatsapp_order_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function orderNumber(): string
    {
        return 'WA-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function recalculateQuotedTotal(): void
    {
        $this->quoted_total = $this->items()->withoutGlobalScopes()->get()
            ->sum(fn (WhatsAppOrderItem $item) => $item->lineTotal());
        $this->save();
    }

    public function isPickup(): bool
    {
        return in_array($this->delivery_address, [__('whatsapp.pickup_label', [], 'en'), __('whatsapp.pickup_label', [], 'ur')], true);
    }

    /** The address and area as one line, or null for a pickup or no address. */
    public function deliveryAddressLine(): ?string
    {
        if ($this->isPickup() || blank($this->delivery_address)) {
            return null;
        }

        return collect([$this->delivery_address, $this->delivery_area])->filter(fn ($part) => filled($part))->implode(', ');
    }

    public function slipIsPdf(): bool
    {
        return str_ends_with((string) $this->payment_slip_path, '.pdf');
    }

    public function isDecidable(): bool
    {
        return $this->status === WhatsAppOrderStatus::Pending;
    }
}
