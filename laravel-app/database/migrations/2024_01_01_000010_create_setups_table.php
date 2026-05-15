<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setups', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 4);          // BUY | SELL
            $table->string('timeframe', 5);           // 5m | 15m | 30m | 1h | 4h
            $table->decimal('entry',     10, 2);
            $table->decimal('stop_loss', 10, 2);
            $table->decimal('tp1',       10, 2);
            $table->decimal('tp2',       10, 2);
            $table->unsignedTinyInteger('score')->default(0); // 0–100
            $table->string('status', 12)->default('active');  // active | triggered | closed | invalidated
            $table->json('confluence_detail')->nullable();     // per-signal breakdown
            $table->decimal('risk_pips', 8, 1)->nullable();
            $table->decimal('rr_ratio',  5, 2)->nullable();
            $table->decimal('vwap',      10, 2)->nullable();
            $table->decimal('poc',       10, 2)->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['direction', 'created_at']);
            $table->index(['score', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setups');
    }
};
