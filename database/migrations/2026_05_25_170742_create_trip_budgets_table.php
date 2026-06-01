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
        Schema::create('trip_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();

            // Estimated budget by category
            $table->decimal('food', 15, 2)->default(0);
            $table->decimal('transport', 15, 2)->default(0);
            $table->decimal('attraction', 15, 2)->default(0);
            $table->decimal('accommodation', 15, 2)->default(0);
            $table->decimal('other', 15, 2)->default(0);
            $table->decimal('total_estimated', 15, 2)->default(0);

            // Actual spending by category
            $table->decimal('food_actual', 15, 2)->default(0);
            $table->decimal('transport_actual', 15, 2)->default(0);
            $table->decimal('attraction_actual', 15, 2)->default(0);
            $table->decimal('accommodation_actual', 15, 2)->default(0);
            $table->decimal('other_actual', 15, 2)->default(0);
            $table->decimal('total_actual', 15, 2)->default(0);

            $table->timestamps();

            // One budget per trip
            $table->unique('trip_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_budgets');
    }
};
