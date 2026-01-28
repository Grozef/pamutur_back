<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_combinations', function (Blueprint $table) {
            $table->id();
            $table->date('bet_date');
            $table->unsignedBigInteger('race_id')->nullable(); // Nullable car peut ne pas être dans races table
            $table->integer('reunion_number');
            $table->integer('course_number');
            $table->enum('combination_type', ['COUPLE', 'TRIO']);
            $table->json('horses'); // [{horse_id: "...", horse_name: "..."}, ...]
            $table->decimal('amount', 8, 2)->default(10.00);
            $table->json('metadata')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            // Indexes
            $table->index('bet_date');
            $table->index(['bet_date', 'combination_type']);
            $table->index('race_id');
            $table->index(['reunion_number', 'course_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_combinations');
    }
};