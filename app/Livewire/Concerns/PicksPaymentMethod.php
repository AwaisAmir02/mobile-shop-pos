<?php

namespace App\Livewire\Concerns;

use App\Enums\SalePaymentMethod;
use App\Models\ShopAccount;
use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

/**
 * The "Paid by" picker for sale payments: '' (not recorded), 'cash', a shop
 * account id, or '__create__' (opens the shop-account quick-create).
 */
trait PicksPaymentMethod
{
    public string $paymentMethod = '';

    public function updatedPaymentMethod(): void
    {
        if ($this->paymentMethod === '__create__') {
            $this->paymentMethod = '';
            $this->dispatch('open-modal', name: 'quick-create-shop-account');
        }
    }

    #[On('shop-account-created')]
    public function onPaymentAccountCreated(int $shopAccountId): void
    {
        $this->paymentMethod = (string) $shopAccountId;
    }

    /**
     * @return array{0: ?string, 1: ?int} the payment method and shop account id
     */
    protected function chosenPaymentMethod(): array
    {
        return match (true) {
            $this->paymentMethod === '' => [null, null],
            ctype_digit($this->paymentMethod) => [SalePaymentMethod::Account->value, (int) $this->paymentMethod],
            default => [$this->paymentMethod, null],
        };
    }

    protected function paymentAccounts(): Collection
    {
        // Shared accounts plus the cash drawers of the branch this payment is recorded at.
        return ShopAccount::query()->usableAt(BranchContext::current()->recordingBranchId())->orderBy('name')->get();
    }
}
