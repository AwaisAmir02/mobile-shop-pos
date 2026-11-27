<?php

namespace App\Enums;

/**
 * The Wholesale P2 tools a trade can use. Which trade gets which is decided in
 * one place, BusinessCategory::wholesaleTools(); a shop asks with
 * Shop::usesTool(), and a screen behind one is routed with the shop.tool
 * middleware (404 without it).
 */
enum WholesaleTool: string
{
    case Cheques = 'cheques';               // W6: post-dated cheques in and out
    case PurchaseReturns = 'returns';       // W10: goods back to the vendor, debit notes
    case BonusQuantity = 'bonus';           // W9: free quantity on Stock In
    case Contra = 'contra';                 // W11: a customer who is also a vendor
    case SalesOrders = 'orders';            // W7: booked orders, load sheets, challans
    case Routes = 'routes';                 // W8: delivery routes and bookers
    case Schemes = 'schemes';               // W9: buy X get Y and the claims on them
}
