<?php

namespace App\Support;

use App\Models\Payslip;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The section tabs under a module's page header (x-ui.module-tabs), in one place
 * so every module's tabs look and behave the same (docs/design-system.md).
 *
 * Presentation only. Each tab repeats the visibility rule the link it replaced
 * already had; the screens behind them keep their own route and mount guards,
 * so hiding a tab is never the protection.
 */
class ModuleNav
{
    /**
     * @return list<array{label: string, route: string}>
     */
    public static function tabs(string $module, ?User $user = null): array
    {
        $user ??= Auth::user();

        if (! $user) {
            return [];
        }

        $shop = $user->shop;

        $tabs = match ($module) {
            'sales' => [
                ['label' => 'Register', 'route' => 'sales.index', 'show' => true],
                ['label' => 'History', 'route' => 'sales.history', 'show' => true],
            ],
            'stock' => [
                ['label' => 'Receipts', 'route' => 'stock-ins.history', 'show' => true],
                ['label' => 'Expiring & Expired', 'route' => 'stock-ins.expiry', 'show' => (bool) $shop?->expiry_tracking_enabled],
                ['label' => 'Write-offs', 'route' => 'stock-ins.write-offs', 'show' => true],
            ],
            'purchasing' => [
                ['label' => 'Orders', 'route' => 'purchase-orders.index', 'show' => true],
                ['label' => 'Goods Receipts', 'route' => 'purchase-orders.receipts', 'show' => $user->hasAccessTo('receive-goods')],
                ['label' => 'Vendor Invoices', 'route' => 'purchase-orders.invoices', 'show' => $user->hasAccessTo('vendor-invoices') || $user->hasAccessTo('vendor-invoices-approve')],
            ],
            'payroll' => [
                ['label' => 'Employees', 'route' => 'payroll.employees', 'show' => $user->hasAccessTo('payroll')],
                ['label' => 'Run Payroll', 'route' => 'payroll.run', 'show' => $user->hasAccessTo('payroll-run')],
                ['label' => 'Advances', 'route' => 'payroll.advances', 'show' => $user->hasAccessTo('payroll')],
                ['label' => 'History', 'route' => 'payroll.history', 'show' => $user->hasAccessTo('payroll')],
                ['label' => 'Deduction Types', 'route' => 'payroll.deduction-types', 'show' => $user->hasAccessTo('payroll')],
            ],
            'accounts' => [
                ['label' => 'Trial Balance', 'route' => 'ledger.trial-balance', 'show' => true],
                ['label' => 'Profit & Loss', 'route' => 'ledger.profit-and-loss', 'show' => true],
                ['label' => 'Balance Sheet', 'route' => 'ledger.balance-sheet', 'show' => true],
                ['label' => 'Cash Book', 'route' => 'ledger.cash-book', 'show' => true],
                ['label' => 'Branch Profit', 'route' => 'ledger.branch-profit', 'show' => $user->shop_id && BranchContext::current()->hasMultipleBranches($user->shop_id)],
                ['label' => 'Ledger Health', 'route' => 'ledger.health', 'show' => true],
                ['label' => 'Owner & Held Money', 'route' => 'ledger.owner-money', 'show' => (bool) $user->is_owner],
            ],
            'marketing' => [
                ['label' => 'Overview', 'route' => 'marketing.index', 'show' => $user->hasAccessTo('marketing-analytics')],
                ['label' => 'Posts & Share kit', 'route' => 'marketing.posts', 'show' => $user->hasAccessTo('marketing')],
                ['label' => 'Reviews', 'route' => 'marketing.reviews', 'show' => $user->hasAccessTo('marketing')],
                ['label' => 'QR codes', 'route' => 'marketing.qr', 'show' => $user->hasAccessTo('marketing')],
                ['label' => 'Ads', 'route' => 'marketing.ads', 'show' => $user->hasAccessTo('marketing-ads') && $shop?->ads_enabled],
            ],
            default => [],
        };

        return array_values(array_map(
            fn (array $tab) => ['label' => $tab['label'], 'route' => $tab['route']],
            array_filter($tabs, fn (array $tab) => $tab['show']),
        ));
    }

    /**
     * Payroll history stays reachable after payroll is switched off, as long as
     * payslips exist. Same rule the Reports screen used for its link.
     */
    public static function payrollHistoryVisible(?User $user = null): bool
    {
        $user ??= Auth::user();

        return (bool) $user?->hasAccessTo('payroll') && ($user->shop?->payroll_enabled || Payslip::query()->exists());
    }
}
