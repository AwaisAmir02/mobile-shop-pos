<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('tagline', 160)->nullable();
            $table->decimal('price', 12, 2);
            $table->string('billing_interval', 20);
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('plan_type')->constrained('plans')->restrictOnDelete();
        });

        $now = now();
        $plans = [
            ['Starter', 'For a single counter getting organised', 2500, false, [
                ['Sales, invoices & barcode scanning', true],
                ['Stock In with FIFO costing', true],
                ['Udhaar & customer ledger', true],
                ['Repairs, SIM, loads, bills & NADRA', true],
                ['WhatsApp ordering', false],
                ['Social posting & Meta ads', false],
            ]],
            ['Growth', 'For shops ready to sell on WhatsApp', 4500, true, [
                ['Everything in Starter', true],
                ['WhatsApp chatbot ordering', true],
                ['Order updates & payment reminders', true],
                ['Restock alerts & review requests', true],
                ['Staff roles & permissions', true],
                ['Social posting & Meta ads', false],
            ]],
            ['Commerce', 'For shops that market and advertise', 7500, false, [
                ['Everything in Growth', true],
                ['Facebook & Instagram posts and Stories', true],
                ['Meta ads incl. WhatsApp Status', true],
                ['Ad results: chats, orders & sales', true],
                ['QR posters & share kit', true],
                ['Marketing overview', true],
            ]],
        ];

        foreach ($plans as $index => [$name, $tagline, $price, $featured, $features]) {
            DB::table('plans')->insert([
                'name' => $name,
                'tagline' => $tagline,
                'price' => $price,
                'billing_interval' => 'monthly',
                'features' => json_encode(array_map(fn ($f) => ['label' => $f[0], 'included' => $f[1]], $features)),
                'is_active' => true,
                'is_featured' => $featured,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });

        Schema::dropIfExists('plans');
    }
};
