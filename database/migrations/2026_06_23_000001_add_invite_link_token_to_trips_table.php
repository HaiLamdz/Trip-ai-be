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
        if (!Schema::hasColumn('trips', 'invite_link_token')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->string('invite_link_token')->nullable()->after('cloned_from_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('trips', 'invite_link_token')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->dropColumn('invite_link_token');
            });
        }
    }
};
