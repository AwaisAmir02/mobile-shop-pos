<?php

namespace App\Http\Controllers;

use App\Actions\ParcelReviews;
use App\Enums\ShipmentStatus;
use App\Models\CustomerReview;
use App\Models\Shipment;
use App\Models\Shop;
use App\Support\ApiShopContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The feedback page for a delivered parcel (O8, App\Actions\ParcelReviews):
 * stars, a line of comment, and whether the shop may show it. Token-only, like
 * the customer's /k/ page; nothing but the order's items is shown.
 */
class ParcelReviewController extends Controller
{
    protected function shipment(string $token): Shipment
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);

        return Shipment::withoutGlobalScopes()->where('review_token', $token)->firstOrFail();
    }

    public function show(string $token): View
    {
        $shipment = $this->shipment($token);

        return ApiShopContext::current()->run($shipment->shop_id, fn () => view('parcel-review.show', [
            'shipment' => $shipment,
            'shop' => Shop::findOrFail($shipment->shop_id),
            'items' => $shipment->sale?->items()->get() ?? collect(),
            'review' => CustomerReview::withoutGlobalScopes()->where('shipment_id', $shipment->id)->first(),
            'delivered' => $shipment->status === ShipmentStatus::Delivered,
        ]));
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $shipment = $this->shipment($token);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'consent' => ['nullable', 'boolean'],
        ]);

        ApiShopContext::current()->run($shipment->shop_id, fn () => ParcelReviews::submit($shipment, (int) $data['rating'], $data['comment'] ?? null, (bool) ($data['consent'] ?? false), $data['display_name'] ?? null));

        return redirect()->route('parcel-review.show', $token)->with('thanked', true);
    }
}
