<?php

namespace App\Mail;

use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Products that just got the "Low" badge. The items are a snapshot taken
 * when it happened, so the queue worker never re-reads shop-scoped products.
 */
class LowStockMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  array<int, array{name: string, stock: string}>  $items */
    public function __construct(public Shop $shop, public array $items)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $count = count($this->items);

        return new Envelope(subject: $count === 1
            ? 'Low stock: '.$this->items[0]['name']
            : 'Low stock: '.$count.' products are running low');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.low-stock');
    }
}
