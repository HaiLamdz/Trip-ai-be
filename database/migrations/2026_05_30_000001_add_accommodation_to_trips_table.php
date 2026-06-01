<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // Loại chỗ ở người dùng chọn
            $table->string('accommodation_type')->nullable()->after('transport_mode');
            // Khu vực / tên chỗ ở người dùng nhập (tuỳ chọn)
            $table->string('accommodation_area')->nullable()->after('accommodation_type');
            // Giờ đến điểm đến ngày đầu (HH:MM)
            $table->string('arrival_time')->nullable()->after('accommodation_area');
            // Tọa độ chỗ ở — do AI điền sau khi chọn khách sạn cụ thể
            $table->decimal('accommodation_lat', 10, 7)->nullable()->after('arrival_time');
            $table->decimal('accommodation_lng', 10, 7)->nullable()->after('accommodation_lat');
            // Tên khách sạn cụ thể AI đã chọn
            $table->string('accommodation_name')->nullable()->after('accommodation_lng');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn([
                'accommodation_type',
                'accommodation_area',
                'arrival_time',
                'accommodation_lat',
                'accommodation_lng',
                'accommodation_name',
            ]);
        });
    }
};
