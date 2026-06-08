<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_place_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Chi tiết chi phí
            $table->decimal('amount', 12, 2);
            $table->enum('category', ['food', 'transport', 'attraction', 'accommodation', 'shopping', 'other'])
                  ->default('other');
            $table->string('note', 500)->nullable();
            $table->string('paid_by', 255)->nullable();   // tên người trả (free text)
            $table->date('expense_date');                 // ngày chi tiêu

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_expenses');
    }
};
