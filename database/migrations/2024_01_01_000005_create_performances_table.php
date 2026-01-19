<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('performances', function (Blueprint $table) {
            $table->id();
            $table->string('horse_id');
            $table->foreignId('race_id')->constrained()->onDelete('cascade');
            $table->foreignId('jockey_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('trainer_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('rank')->nullable(); // 0 for D/Dai/Tombé
            $table->integer('weight')->nullable(); // in grams
            $table->integer('draw')->nullable(); // placeCorde
            $table->text('raw_musique')->nullable();
            $table->float('odds_ref')->nullable();
            $table->integer('gains_race')->nullable();
            $table->timestamps();

            $table->foreign('horse_id')->references('id_cheval_pmu')->on('horses')->onDelete('cascade');
            
            $table->index(['horse_id', 'race_id']);
            $table->index('jockey_id');
            $table->index('trainer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performances');
    }
};
