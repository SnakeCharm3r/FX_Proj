<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setup_id')->nullable()->constrained('setups')->nullOnDelete();
            $table->string('type', 40);               // cvd_divergence | delta_imbalance | absorption | stacked_imbalance | exhaustion | vwap_deviation | poc_rejection
            $table->string('direction', 8)->nullable(); // BULLISH | BEARISH
            $table->string('timeframe', 5);
            $table->decimal('value', 12, 4)->nullable(); // raw metric value
            $table->decimal('price',  10, 2)->nullable(); // price at signal time
            $table->unsignedTinyInteger('weight')->default(0); // contribution weight
            $table->json('meta')->nullable();            // extra context data
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index(['timeframe', 'created_at']);
            $table->index('setup_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
