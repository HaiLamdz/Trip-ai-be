<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->after('is_public');
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->text('publish_description')->nullable()->after('published_at');
            $table->unsignedInteger('clone_count')->default(0)->after('publish_description');
            $table->unsignedInteger('view_count')->default(0)->after('clone_count');
            $table->foreignId('cloned_from_id')->nullable()->constrained('trips')->nullOnDelete()->after('view_count');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['cloned_from_id']);
            $table->dropColumn(['is_published', 'published_at', 'publish_description', 'clone_count', 'view_count', 'cloned_from_id']);
        });
    }
};
