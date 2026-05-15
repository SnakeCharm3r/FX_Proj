<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setup_id')->nullable()->constrained('setups')->nullOnDelete();
            $table->string('channel', 10);            // telegram | email | browser
            $table->string('type', 20);               // NEW_SETUP | UPDATE | TEST | INVALIDATED
            $table->text('message');
            $table->string('status', 10)->default('pending'); // pending | sent | failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['channel', 'created_at']);
            $table->index(['status',  'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
    }
};
