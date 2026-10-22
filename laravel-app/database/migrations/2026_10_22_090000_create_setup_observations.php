<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('setup_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setup_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source',32);
            $table->string('instrument',32);
            $table->string('timeframe',8);
            $table->unsignedBigInteger('candle_time');
            $table->string('direction',8);
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('bull_score')->nullable();
            $table->unsignedTinyInteger('bear_score')->nullable();
            $table->unsignedTinyInteger('history_floor');
            $table->unsignedTinyInteger('signal_threshold')->nullable();
            foreach (['entry','stop_loss','tp1','tp2'] as $price) $table->decimal($price,18,8)->nullable();
            $table->json('confluence_detail');
            $table->json('metrics')->nullable();
            $table->string('origin',32);
            $table->string('strategy_version',64);
            $table->timestamp('spotted_at');
            $table->timestamps();
            $table->unique(['source','instrument','timeframe','candle_time'],'setup_observation_candle_unique');
            $table->index(['source','instrument','score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_observations');
    }
};
