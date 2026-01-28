<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('value_bets', function (Blueprint $table) {
            $table->id();
            $table->date('bet_date');
            $table->unsignedBigInteger('race_id');
            $table->string('horse_id');
            $table->string('horse_name');
            $table->float('estimated_probability');
            $table->float('offered_odds');
            $table->float('value_score'); // (estimated_probability * offered_odds) - 1
            $table->integer('ranking'); // 1-20
            $table->json('metadata')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            // Indexes only
            $table->index(['bet_date', 'ranking']);
            $table->index(['race_id', 'value_score']);
            $table->index('is_processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_bets');
    }
};