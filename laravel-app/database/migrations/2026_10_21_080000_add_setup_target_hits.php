<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('setups', function (Blueprint $table) {
            $table->timestamp('tp1_hit_at')->nullable();
            $table->timestamp('tp2_hit_at')->nullable();
            $table->json('target_hit_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('setups', fn (Blueprint $table) => $table->dropColumn(['tp1_hit_at', 'tp2_hit_at', 'target_hit_details']));
    }
};
