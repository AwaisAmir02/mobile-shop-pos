<?php

namespace App\Http\Controllers;

use App\Couriers\CourierException;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Opens a parcel's printable label. Leopards gives a link the browser can
 * open; PostEx's PDF sits behind the shop's API token, so we fetch it here
 * and pass it on (the token never reaches the browser).
 */
class ShipmentLabelController extends Controller
{
    public function __invoke(Shipment $shipment): RedirectResponse|Response
    {
        abort_unless(auth()->user()?->shop?->couriers_enabled, 404);
        $shipment->loadMissing('connection');

        try {
            $label = $shipment->courier->provider()->label($shipment->connection, $shipment);
        } catch (CourierException $e) {
            return redirect()->route('shipments.index')->with('label_error', $e->getMessage());
        }

        if ($label->url !== null) {
            return redirect()->away($label->url);
        }

        return response($label->pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="label-'.preg_replace('/[^A-Za-z0-9-]/', '', $shipment->tracking_number).'.pdf"',
        ]);
    }
}
