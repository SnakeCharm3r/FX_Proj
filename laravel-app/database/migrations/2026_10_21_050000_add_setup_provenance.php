<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('setups', function (Blueprint $table) {
            $table->string('source', 32)->nullable();
            $table->string('instrument', 32)->nullable();
            $table->unsignedBigInteger('candle_time')->nullable();
            $table->string('evaluation_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('setups', function (Blueprint $table) {
            $table->dropUnique(['evaluation_key']);
            $table->dropColumn(['source', 'instrument', 'candle_time', 'evaluation_key']);
        });
    }
};
