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
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('destination');
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();
            $table->date('start_date');
            $table->unsignedSmallInteger('duration_days');
            $table->decimal('budget', 15, 2);
            $table->unsignedSmallInteger('num_people');
            $table->string('transport_mode')->nullable();
            $table->json('preferences')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'processing', 'completed', 'failed'])->default('draft');
            $table->json('timeline')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
