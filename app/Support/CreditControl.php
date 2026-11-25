<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * A customer's credit limit (W5), checked when a sale leaves money unpaid.
 *
 * What counts against the limit is everything the customer owes: every
 * unpaid bill (sales and counter services) plus the udhaar loan balance when
 * it is owed. An udhaar advance is never netted against bills; they are two
 * kinds of money (see Customer::salesOutstandingBalance).
 *
 * Going over the limit is never silently allowed and never a dead end: the
 * shop owner can approve it, from their own login or by typing their
 * password on the cashier's screen. The sale records who approved it and why.
 */
final class CreditControl
{
    public static function owed(Customer $customer): float
    {
        $statement = CustomerDuesStatement::for($customer);

        return round((float) $statement->items()->sum('amountDue') + max(0.0, $statement->udhaarBalance()), 2);
    }

    /**
     * Where the customer stands if this sale leaves $newDue unpaid, or null
     * when they have no limit.
     *
     * @return array{limit: float, owed: float, after: float, available: float, over: bool}|null
     */
    public static function check(Customer $customer, float $newDue): ?array
    {
        if ($customer->credit_limit === null) {
            return null;
        }

        $limit = round((float) $customer->credit_limit, 2);
        $owed = self::owed($customer);
        $after = round($owed + max(0.0, $newDue), 2);

        return [
            'limit' => $limit,
            'owed' => $owed,
            'after' => $after,
            'available' => max(0.0, round($limit - $owed, 2)),
            'over' => max(0.0, $newDue) > 0 && $after > $limit,
        ];
    }

    /**
     * The owner approving a sale over the limit: the signed-in user when they
     * are the owner, otherwise an owner of the same shop whose password was
     * typed. Null when neither.
     */
    public static function approvingOwner(User $user, string $ownerPassword): ?User
    {
        if ($user->is_owner) {
            return $user;
        }

        if ($ownerPassword === '' || $user->shop_id === null) {
            return null;
        }

        return User::query()
            ->where('shop_id', $user->shop_id)
            ->where('is_owner', true)
            ->get()
            ->first(fn (User $owner) => Hash::check($ownerPassword, (string) $owner->password));
    }
}
