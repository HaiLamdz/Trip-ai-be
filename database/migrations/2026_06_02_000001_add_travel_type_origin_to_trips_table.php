<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('origin')->nullable()->after('destination');
            $table->enum('travel_type', ['solo', 'couple', 'family', 'group'])->nullable()->after('num_people');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['origin', 'travel_type']);
        });
    }
};
