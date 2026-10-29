<?php

namespace App\Services\Billing;

use App\Actions\CreateShopWithOwner;
use App\Enums\PlanType;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SubscriptionPaymentService
{
    public const PROOF_DISK = 'local';

    public function __construct(
        protected SubscriptionBillingService $billing,
        protected PaymentGateways $gateways,
        protected Entitlements $entitlements,
    ) {}

    /**
     * @param  array{name: string, phone: string, email?: ?string}  $shop
     * @param  array{name: string, email?: ?string, phone?: ?string, password?: string, password_hash?: string, phone_verified?: bool}  $owner
     * @return array{0: Shop, 1: User, 2: SubscriptionPayment}
     */
    public function register(Plan $plan, array $shop, array $owner, bool $emailVerified = false): array
    {
        if (! $plan->is_active) {
            throw new RuntimeException('This plan is no longer available.');
        }

        return DB::transaction(function () use ($plan, $shop, $owner, $emailVerified) {
            $today = now()->toDateString();

            [$createdShop, $user] = CreateShopWithOwner::handle($shop, $owner, [
                'plan_id' => $plan->id,
                'plan_type' => $plan->billing_interval->value,
                'subscription_status' => SubscriptionStatus::Inactive,
                'subscription_start_date' => $today,
            ], $emailVerified);

            $invoice = $this->billing->create($createdShop, [
                'period_start' => $today,
                'months' => $plan->billing_interval === PlanType::Yearly ? 12 : 1,
                'amount' => (float) $plan->price,
                'notes' => $plan->name.' plan — self-serve signup',
            ], $user->id);

            $this->entitlements->syncFeatureFlags($createdShop);

            $payment = $this->gateways->current()->start($invoice);

            return [$createdShop, $user, $payment];
        });
    }

    public function submitProof(SubscriptionPayment $payment, string $reference, ?UploadedFile $proof): SubscriptionPayment
    {
        return DB::transaction(function () use ($payment, $reference, $proof) {
            $payment = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === SubscriptionPaymentStatus::Confirmed) {
                throw new RuntimeException('This payment has already been confirmed.');
            }

            $oldProof = $payment->proof_path;
            $path = $proof?->store('payment-proofs/'.$payment->shop_id, self::PROOF_DISK);

            $payment->update([
                'status' => SubscriptionPaymentStatus::Pending,
                'reference' => trim($reference),
                'proof_path' => $path ?? $oldProof,
                'submitted_at' => now(),
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            if ($path && $oldProof) {
                Storage::disk(self::PROOF_DISK)->delete($oldProof);
            }

            return $payment->fresh();
        });
    }

    public function confirm(SubscriptionPayment $payment, int $adminId): SubscriptionPayment
    {
        return DB::transaction(function () use ($payment, $adminId) {
            $payment = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== SubscriptionPaymentStatus::Pending) {
                throw new RuntimeException('Only a pending payment can be confirmed.');
            }

            $invoice = $payment->invoice()->firstOrFail();

            if (! $invoice->isPaid()) {
                $this->billing->markPaid($invoice);
            }

            $shopAddon = ShopAddon::where('subscription_invoice_id', $invoice->id)->first();

            if ($shopAddon) {
                app(AddonService::class)->activate($shopAddon);
            } else {
                Shop::whereKey($payment->shop_id)->update([
                    'subscription_status' => SubscriptionStatus::Active->value,
                    'subscription_start_date' => $invoice->period_start->toDateString(),
                ]);
            }

            $payment->update([
                'status' => SubscriptionPaymentStatus::Confirmed,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            return $payment->fresh();
        });
    }

    public function reject(SubscriptionPayment $payment, int $adminId, string $reason): SubscriptionPayment
    {
        return DB::transaction(function () use ($payment, $adminId, $reason) {
            $payment = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== SubscriptionPaymentStatus::Pending) {
                throw new RuntimeException('Only a pending payment can be rejected.');
            }

            $payment->update([
                'status' => SubscriptionPaymentStatus::Rejected,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $payment->fresh();
        });
    }
}
