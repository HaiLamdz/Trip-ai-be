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
        Schema::create('trip_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_day_id')->constrained('trip_days')->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->string('place_name');
            $table->enum('place_type', ['food', 'attraction', 'hotel', 'cafe', 'transport', 'other']);
            $table->string('time'); // HH:MM format
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->string('transport_to_next')->nullable();
            $table->decimal('distance_to_next_km', 8, 2)->default(0);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('trip_day_id');
            $table->index('trip_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_places');
    }
};
