<?php

namespace App\Enums;

/**
 * What can hold stock without taking it from a batch (StockAvailability): an
 * order the shop has promised or is still deciding on. Stock only leaves when
 * the order becomes a sale through CreateSale.
 */
enum StockHoldKind: string
{
    /** W7: a booked sales order, until delivered or cancelled. */
    case SalesOrder = 'sales_order';
    /** A WhatsApp order the customer confirmed, until accepted or rejected. */
    case WhatsAppOrder = 'whatsapp_order';
    /** An online-store order received, until accepted, rejected or cancelled. */
    case StoreOrder = 'store_order';
    /** A part priced on an open job card (repair workshops), until the job is billed or cancelled. */
    case JobCard = 'job_card';

    /** As it reads in "... is held for booked orders". */
    public function heldFor(): string
    {
        return match ($this) {
            self::SalesOrder => 'booked orders',
            self::WhatsAppOrder => 'pending WhatsApp orders',
            self::StoreOrder => 'pending online-store orders',
            self::JobCard => 'open job cards',
        };
    }
}
