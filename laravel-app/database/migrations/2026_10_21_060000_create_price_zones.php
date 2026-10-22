<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_zones', function (Blueprint $table) {
            $table->id();
            $table->string('zone_key', 64)->unique();
            $table->string('source', 32);
            $table->string('instrument', 32);
            $table->string('version', 40);
            $table->string('type', 8); // DEMAND | SUPPLY
            $table->string('timeframe', 5)->default('1h');
            $table->decimal('low', 14, 5);
            $table->decimal('high', 14, 5);
            $table->double('atr');
            $table->unsignedBigInteger('origin_time');
            $table->unsignedBigInteger('confirmed_time');
            $table->unsignedBigInteger('invalidated_time')->nullable();
            $table->string('status', 16)->default('active');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('last_price_received')->nullable();
            $table->boolean('was_inside')->nullable();
            $table->unsignedInteger('entry_count')->default(0);
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamps();
            $table->index(['source', 'instrument', 'status']);
        });
        Schema::table('alert_logs', function (Blueprint $table) {
            $table->foreignId('price_zone_id')->nullable()->constrained('price_zones')->nullOnDelete();
            $table->unsignedInteger('zone_entry')->nullable();
            $table->unique(['price_zone_id', 'zone_entry']);
        });
    }

    public function down(): void
    {
        Schema::table('alert_logs', function (Blueprint $table) {
            $table->dropUnique(['price_zone_id', 'zone_entry']);
            $table->dropConstrainedForeignId('price_zone_id');
            $table->dropColumn('zone_entry');
        });
        Schema::dropIfExists('price_zones');
    }
};
