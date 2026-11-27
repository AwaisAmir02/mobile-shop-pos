<?php

namespace App\Http\Controllers\Store;

use App\Actions\StorefrontConnections;
use App\Http\Controllers\Controller;
use App\Storefronts\StorefrontException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Where a shop owner lands back from Shopify's or WooCommerce's approval page
 * (O9). Signed in, so the state is checked against their own shop.
 */
class StorefrontConnectController extends Controller
{
    public function shopify(Request $request): RedirectResponse
    {
        return $this->finish(fn () => StorefrontConnections::completeShopify($request->query(), Auth::user()->shop));
    }

    public function wooCommerce(Request $request): RedirectResponse
    {
        return $this->finish(fn () => StorefrontConnections::finishWooCommerce((string) $request->query('state', ''), Auth::user()->shop));
    }

    protected function finish(\Closure $complete): RedirectResponse
    {
        $back = route('settings.index', ['tab' => 'integrations', 'section' => 'online-store']);

        try {
            $connection = $complete();
        } catch (StorefrontException $e) {
            return redirect($back)->with('storefront_error', $e->getMessage());
        }

        $unmatched = count($connection->setting('unmatched', []));

        return redirect($back)->with('storefront_connected', $connection->driver->label().' store '.$connection->external_domain.' is connected.'
            .($unmatched > 0 ? " {$unmatched} of its products didn't match a product here by SKU; map them below." : ''));
    }
}
