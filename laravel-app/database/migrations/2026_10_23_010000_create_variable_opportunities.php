<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('variable_opportunities',function(Blueprint $table){
            $table->id();$table->string('evaluation_key',64)->unique();
            $table->string('source',32);$table->string('instrument',32);$table->string('timeframe',5);
            $table->string('strategy_version',40);$table->unsignedBigInteger('candle_time');
            $table->string('direction',8);$table->unsignedSmallInteger('score');
            foreach(['entry','stop_loss','tp1','tp2','risk_points','delta_pct'] as $field)$table->double($field);
            $table->boolean('historical');$table->json('evidence');
            $table->string('telegram_status',24)->default('historical');
            $table->timestamp('telegram_attempted_at')->nullable();$table->timestamps();
            $table->index(['source','instrument','timeframe','candle_time'],'variable_opportunity_scope');
        });
        Schema::table('alert_logs',fn(Blueprint $table)=>$table->foreignId('variable_opportunity_id')->nullable()
            ->constrained('variable_opportunities')->nullOnDelete());
    }
    public function down(): void
    {
        Schema::table('alert_logs',fn(Blueprint $table)=>$table->dropConstrainedForeignId('variable_opportunity_id'));
        Schema::dropIfExists('variable_opportunities');
    }
};
