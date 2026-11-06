<?php

namespace App\Livewire\Concerns;

use App\Models\Product;
use App\Support\Quantity;

/**
 * Adding "1" of something sold by weight or length is nearly always wrong, so
 * picking or scanning one asks how much first. The component supplies
 * cartLineFor() and quantityLimitFor().
 */
trait PromptsForMeasuredQuantity
{
    public ?int $quantityPromptId = null;

    public string $quantityPromptValue = '';

    protected function promptForQuantity(Product $product): void
    {
        $this->quantityPromptId = $product->id;
        $this->quantityPromptValue = '';
        $this->resetErrorBag('quantityPromptValue');
        $this->dispatch('open-modal', name: 'sale-quantity-prompt');
    }

    public function confirmQuantityPrompt(): void
    {
        $product = Product::findOrFail($this->quantityPromptId);

        $this->validate(['quantityPromptValue' => $product->unit->quantityRules()], [], ['quantityPromptValue' => 'quantity']);

        $newQuantity = Quantity::add($this->cart[$product->id]['quantity'] ?? 0, $this->quantityPromptValue);
        $limit = $this->quantityLimitFor($product);

        if (Quantity::compare($newQuantity, $limit) > 0) {
            $this->addError('quantityPromptValue', 'Only '.$product->unit->amount($limit).' of '.$product->name.' available.');

            return;
        }

        if (isset($this->cart[$product->id])) {
            $this->cart[$product->id]['quantity'] = $newQuantity;
        } else {
            $this->cart[$product->id] = $this->cartLineFor($product, $newQuantity);
        }

        $this->closeQuantityPrompt();
    }

    public function closeQuantityPrompt(): void
    {
        $this->reset(['quantityPromptId', 'quantityPromptValue']);
        $this->resetErrorBag('quantityPromptValue');
        $this->dispatch('close-modal', name: 'sale-quantity-prompt');
        $this->dispatch('focus-scan');
    }
}
