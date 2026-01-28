<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelly_bets', function (Blueprint $table) {
            $table->id();
            $table->date('bet_date');
            $table->unsignedBigInteger('race_id');
            $table->string('horse_id');
            $table->string('horse_name');
            $table->float('probability');
            $table->float('odds');
            $table->float('kelly_fraction'); // Calculated Kelly %
            $table->decimal('bet_amount', 10, 2); // Amount to bet
            $table->decimal('bankroll', 10, 2); // Bankroll at time of bet
            $table->json('metadata')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            // Indexes only
            $table->index(['bet_date', 'is_processed']);
            $table->index('race_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelly_bets');
    }
};