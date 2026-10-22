<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('market_participation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('instrument', 32);
            $table->string('timeframe', 8);
            $table->unsignedBigInteger('candle_time');
            foreach (['volume','ask_volume','bid_volume','baseline_volume','session_volume'] as $field) $table->decimal($field, 20, 4)->nullable();
            foreach (['delta','session_delta'] as $field) $table->decimal($field, 20, 4)->nullable();
            $table->decimal('participation_ratio', 12, 6)->nullable();
            $table->decimal('delta_share', 12, 6)->nullable();
            $table->string('participation_state', 16);
            $table->string('flow_state', 16);
            $table->unsignedSmallInteger('baseline_sample_size');
            $table->unsignedBigInteger('session_start')->nullable();
            $table->unsignedBigInteger('session_first_observed')->nullable();
            $table->string('session_coverage', 16);
            $table->unsignedSmallInteger('session_bar_count');
            $table->json('context')->nullable();
            $table->timestamps();
            $table->unique(['source','instrument','timeframe','candle_time'], 'market_participation_candle_unique');
            $table->index(['instrument','timeframe','candle_time'], 'market_participation_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_participation_snapshots');
    }
};
