<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shop_only_sees_its_own_expenses(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);
        $userB = User::factory()->create(['shop_id' => $shopB->id]);

        Expense::create([
            'shop_id' => $shopA->id,
            'user_id' => $userA->id,
            'description' => 'Shop A electricity bill',
            'amount' => 500,
            'expense_date' => now()->toDateString(),
        ]);

        Expense::create([
            'shop_id' => $shopB->id,
            'user_id' => $userB->id,
            'description' => 'Shop B internet bill',
            'amount' => 1000,
            'expense_date' => now()->toDateString(),
        ]);

        $this->actingAs($userA);

        Livewire::test('expenses.index')
            ->assertSee('Shop A electricity bill')
            ->assertDontSee('Shop B internet bill')
            ->assertSee('Rs 500.00');
    }

    public function test_saving_an_expense_auto_assigns_the_authenticated_users_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        Livewire::test('expenses.index')
            ->set('description', 'Tea and snacks')
            ->set('amount', '250')
            ->call('save');

        $expense = Expense::first();
        $this->assertSame($shop->id, $expense->shop_id);
        $this->assertEquals(250, $expense->amount);
    }

    public function test_a_shop_cannot_delete_another_shops_expense(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);

        $expenseB = Expense::create([
            'shop_id' => $shopB->id,
            'description' => 'Shop B rent',
            'amount' => 5000,
            'expense_date' => now()->toDateString(),
        ]);

        $this->actingAs($userA);

        try {
            Livewire::test('expenses.index')->call('delete', $expenseB->id);
            $this->fail('Expected a ModelNotFoundException when deleting another shop\'s expense.');
        } catch (ModelNotFoundException) {
            // Expected: the global scope hides shop B's expense from shop A entirely.
        }

        $this->assertNotNull(Expense::withoutGlobalScopes()->find($expenseB->id));
    }
}
