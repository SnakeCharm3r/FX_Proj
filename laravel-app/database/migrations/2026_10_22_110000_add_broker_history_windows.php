<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broker_history_windows', function (Blueprint $table) {
            $table->id();
            $table->string('instrument',20);
            $table->unsignedBigInteger('first_time');
            $table->unsignedBigInteger('last_time');
            $table->unique(['instrument','first_time','last_time'], 'broker_history_window_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('broker_history_windows'); }
};
