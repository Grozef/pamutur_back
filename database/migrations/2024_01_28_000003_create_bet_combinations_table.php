<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_combinations', function (Blueprint $table) {
            $table->id();
            $table->date('bet_date');
            $table->unsignedBigInteger('race_id');
            $table->string('combination_type'); // COUPLE, COUPLE_GAGNANT, COUPLE_PLACE, TRIO
            $table->json('horses'); // Array of horse_ids and names
            $table->float('combined_probability');
            $table->json('source_bets'); // References to daily_bets or value_bets IDs
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            // Indexes only
            $table->index(['bet_date', 'race_id']);
            $table->index(['combination_type', 'combined_probability']);
            $table->index('is_processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_combinations');
    }
};