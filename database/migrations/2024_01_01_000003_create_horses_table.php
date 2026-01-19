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
        Schema::create('horses', function (Blueprint $table) {
            $table->string('id_cheval_pmu')->primary();
            $table->string('name');
            $table->enum('sex', ['MALES', 'FEMELLES', 'HONGRES'])->nullable();
            $table->integer('age')->nullable();
            $table->string('father_id')->nullable();
            $table->string('mother_id')->nullable();
            $table->string('dam_sire_name')->nullable();
            $table->string('breed', 100)->nullable();
            $table->timestamps();

            $table->foreign('father_id')->references('id_cheval_pmu')->on('horses')->onDelete('set null');
            $table->foreign('mother_id')->references('id_cheval_pmu')->on('horses')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('horses');
    }
};
