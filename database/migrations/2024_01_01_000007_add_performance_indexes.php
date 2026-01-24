<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds missing indexes to improve query performance
     */
    public function up(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            // Index on rank for filtering winners/places
            $table->index('rank', 'idx_performances_rank');
        });

        Schema::table('races', function (Blueprint $table) {
            // Composite index for date + race_code lookups
            $table->index(['race_date', 'race_code'], 'idx_races_date_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            $table->dropIndex('idx_performances_rank');
        });

        Schema::table('races', function (Blueprint $table) {
            $table->dropIndex('idx_races_date_code');
        });
    }
};
