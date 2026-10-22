<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broker_hourly_prices', function (Blueprint $table) {
            $table->id();
            $table->string('instrument', 20);
            $table->string('symbol', 40);
            $table->unsignedBigInteger('time');
            foreach (['open','high','low','close'] as $field) $table->double($field);
            $table->unique(['instrument','time']);
        });
        Schema::table('setups', function (Blueprint $table) {
            $table->string('strategy_version')->default('weighted_orderflow_v1');
            $table->foreignId('price_zone_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('setups', function (Blueprint $table) {
            $table->dropForeign(['price_zone_id']);
            $table->dropColumn(['strategy_version','price_zone_id']);
        });
        Schema::dropIfExists('broker_hourly_prices');
    }
};
