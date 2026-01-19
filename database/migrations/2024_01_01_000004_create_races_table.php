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
        Schema::create('races', function (Blueprint $table) {
            $table->id();
            $table->dateTime('race_date');
            $table->string('hippodrome')->nullable();
            $table->integer('distance')->nullable();
            $table->string('discipline', 50)->nullable();
            $table->string('track_condition', 100)->nullable();
            $table->string('race_code')->nullable(); // R1C1, R2C3, etc.
            $table->timestamps();

            $table->index(['race_date', 'hippodrome']);
            $table->index('race_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('races');
    }
};
