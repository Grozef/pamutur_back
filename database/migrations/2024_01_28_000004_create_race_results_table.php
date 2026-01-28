<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('race_id')->unique();
            $table->date('race_date');
            $table->string('hippodrome');
            $table->integer('race_number');
            $table->json('final_rankings'); // Array with horse_id, horse_name, rank
            $table->json('rapports'); // PMU payouts (simple_gagnant, simple_place, couple, trio, etc)
            $table->json('dividends')->nullable(); // Detailed dividend information
            $table->datetime('fetched_at');
            $table->timestamps();

            // Foreign keys
            $table->foreign('race_id')->references('id')->on('races')->onDelete('cascade');

            // Indexes
            $table->index('race_date');
            $table->index(['race_date', 'hippodrome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_results');
    }
};
