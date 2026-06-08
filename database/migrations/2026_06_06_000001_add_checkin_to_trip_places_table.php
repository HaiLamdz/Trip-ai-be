<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_places', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('sort_order');
            $table->string('checkin_photo')->nullable()->after('checked_in_at');   // relative path in storage/app/public
            $table->text('checkin_note')->nullable()->after('checkin_photo');
            $table->string('actual_time', 10)->nullable()->after('checkin_note');  // HH:MM — giờ thực tế đến
        });
    }

    public function down(): void
    {
        Schema::table('trip_places', function (Blueprint $table) {
            $table->dropColumn(['checked_in_at', 'checkin_photo', 'checkin_note', 'actual_time']);
        });
    }
};
