<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->onDelete('cascade');
            $table->json('predictions'); // Predictions made
            $table->json('actual_results')->nullable(); // Actual race results
            $table->float('accuracy_score')->nullable(); // Overall accuracy 0-100
            $table->float('top_3_accuracy')->nullable(); // % of top 3 correctly predicted
            $table->integer('winner_rank_predicted')->nullable(); // Where we predicted the winner
            $table->string('scenario_detected', 50)->nullable(); // DOMINANT_FAVORITE, etc.
            $table->float('execution_time_ms')->nullable(); // Time to calculate predictions
            $table->timestamps();

            $table->index('race_id');
            $table->index('created_at');
            $table->index('accuracy_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_results');
    }
};