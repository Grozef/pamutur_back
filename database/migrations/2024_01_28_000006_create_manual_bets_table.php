<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_bets', function (Blueprint $table) {
            $table->id();
            $table->date('bet_date');
            $table->string('horse_id');
            $table->string('horse_name');
            $table->decimal('amount', 10, 2); // Montant en euros
            $table->enum('bet_type', ['SIMPLE', 'COUPLE_PLACE'])->default('SIMPLE');
            $table->float('probability')->nullable();
            $table->float('odds')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            $table->index(['bet_date', 'is_processed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_bets');
    }
};