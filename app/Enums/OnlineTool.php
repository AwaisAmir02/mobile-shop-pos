<?php

namespace App\Enums;

/**
 * The Online P1 tools a trade can use, the WholesaleTool pattern: which trade
 * gets which is decided in one place, BusinessCategory::onlineTools(); a shop
 * asks with Shop::usesTool(), and a screen behind one is routed with the
 * shop.tool middleware (404 without it).
 *
 * Values are shared with WholesaleTool in the shop.tool middleware, so no
 * value may repeat across the two enums (OnlineToolsTest guards this).
 */
enum OnlineTool: string
{
    /** O1/O2: the register records the channel, delivery details and a delivery charge. */
    case Delivery = 'delivery';
    /** O3: the register refuses stock held for pending WhatsApp and online-store orders. */
    case OrderHolds = 'holds';
    /** O4: courier payouts; a cash-on-delivery parcel is owed by the courier, not the customer. */
    case CourierSettlements = 'settlements';
    /** O5: parcels returned undelivered: the return charge, damaged goods, and refusals by phone. */
    case ParcelReturns = 'rto';
    /** O6: Daraz orders and payout statements imported from Seller Center files. */
    case Marketplace = 'marketplace';
    /** O7: a JazzCash link that pays for one order, before or after it ships. */
    case PaymentLinks = 'paylinks';
    /** O8: a review request once a parcel is delivered, by WhatsApp or a feedback link. */
    case ReviewRequests = 'reviews';
    /** O9: Shopify and WooCommerce store connections, beside the own-website API. */
    case Storefronts = 'storefronts';
    /** O9: asking us to build a website, tracked like a support ticket. */
    case WebsiteRequests = 'website';
}
