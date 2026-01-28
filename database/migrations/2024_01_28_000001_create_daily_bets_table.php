<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_bets', function (Blueprint $table) {
            $table->id();
            $table->date('bet_date');
            $table->unsignedBigInteger('race_id');
            $table->string('horse_id');
            $table->string('horse_name');
            $table->float('probability');
            $table->float('odds')->nullable();
            $table->string('bet_type')->default('SIMPLE');
            $table->json('metadata')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            // Indexes only - no foreign keys
            $table->index(['bet_date', 'probability']);
            $table->index(['race_id', 'horse_id']);
            $table->index('is_processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_bets');
    }
};