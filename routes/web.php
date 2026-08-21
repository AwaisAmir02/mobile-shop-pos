<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route(Auth::user()->is_super_admin ? 'admin.dashboard' : 'dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::view('profile', 'profile')->name('profile');

    Route::middleware('shop.user')->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Volt::route('products', 'products.index')->name('products.index');

        Volt::route('sales', 'sales.create')->name('sales.index');
        Volt::route('sales/history', 'sales.history')->name('sales.history');
        Volt::route('sales/{sale}', 'sales.show')->name('sales.show');

        Volt::route('balance-loads', 'balance-loads.create')->name('balance-loads.index');
        Volt::route('balance-loads/history', 'balance-loads.history')->name('balance-loads.history');

        Volt::route('expenses', 'expenses.index')->name('expenses.index');
        Volt::route('reports', 'reports.index')->name('reports.index');
    });

    Route::middleware('super.admin')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('/', 'admin.dashboard')->name('dashboard');
        Volt::route('shops/create', 'admin.shops.create')->name('shops.create');
        Volt::route('shops/{shop}', 'admin.shops.show')->name('shops.show');
    });
});

require __DIR__.'/auth.php';
