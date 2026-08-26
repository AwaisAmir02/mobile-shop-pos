<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    if (Auth::user()->is_super_admin) {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route(Auth::user()->firstAccessibleScreenRouteName() ?? 'profile');
});

Route::middleware(['auth'])->group(function () {
    Route::view('profile', 'profile')->name('profile');

    Route::middleware('shop.user')->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard')->middleware('shop.access:dashboard');

        Volt::route('products', 'products.index')->name('products.index')->middleware('shop.access:products');

        Volt::route('sales', 'sales.create')->name('sales.index')->middleware('shop.access:sales');
        Volt::route('sales/history', 'sales.history')->name('sales.history')->middleware('shop.access:sales');
        Volt::route('sales/{sale}', 'sales.show')->name('sales.show')->middleware('shop.access:sales');

        Volt::route('balance-loads', 'balance-loads.create')->name('balance-loads.index')->middleware('shop.access:balance-loads');
        Volt::route('balance-loads/history', 'balance-loads.history')->name('balance-loads.history')->middleware('shop.access:balance-loads');

        Volt::route('wallet-loads', 'wallet-loads.create')->name('wallet-loads.index')->middleware('shop.access:wallet-loads');
        Volt::route('wallet-loads/history', 'wallet-loads.history')->name('wallet-loads.history')->middleware('shop.access:wallet-loads');

        Volt::route('expenses', 'expenses.index')->name('expenses.index')->middleware('shop.access:expenses');
        Volt::route('reports', 'reports.index')->name('reports.index')->middleware('shop.access:reports');

        Volt::route('settings', 'settings.index')->name('settings.index')->middleware('shop.access:settings');

        Volt::route('roles', 'roles.index')->name('roles.index')->middleware('shop.access:users');
        Volt::route('users', 'users.index')->name('users.index')->middleware('shop.access:users');
    });

    Route::middleware('super.admin')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('/', 'admin.dashboard')->name('dashboard');
        Volt::route('shops/create', 'admin.shops.create')->name('shops.create');
        Volt::route('shops/{shop}', 'admin.shops.show')->name('shops.show');
    });
});

require __DIR__.'/auth.php';
