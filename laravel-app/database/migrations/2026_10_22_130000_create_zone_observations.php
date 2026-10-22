<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zone_observations',function(Blueprint $table){
            $table->id();$table->string('event_key',64)->unique();
            $table->foreignId('price_zone_id')->constrained()->cascadeOnDelete();
            $table->string('event_type',32);$table->string('direction',8);
            $table->unsignedBigInteger('candle_time');$table->unsignedBigInteger('decision_time');
            $table->double('price');$table->boolean('historical');$table->json('evidence');
            $table->timestamps();$table->index(['decision_time','price_zone_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('zone_observations'); }
};
