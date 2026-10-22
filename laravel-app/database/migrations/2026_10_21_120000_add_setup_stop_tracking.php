<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('setups', function (Blueprint $table) {
            $table->timestamp('sl_hit_at')->nullable();
            $table->string('exit_tracking_state', 20)->default('watching');
        });
    }

    public function down(): void
    {
        Schema::table('setups', fn (Blueprint $table) => $table->dropColumn(['sl_hit_at', 'exit_tracking_state']));
    }
};
