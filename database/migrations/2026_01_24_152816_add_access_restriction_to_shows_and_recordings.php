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
        Schema::table('shows', function (Blueprint $table) {
            // JSON array of role slugs that can access this show
            // null or empty = public (everyone can access)
            // ['staff'] = only staff can access
            // ['admin', 'staff'] = admin OR staff can access
            $table->json('required_roles')->nullable()->after('metadata');
        });

        Schema::table('recordings', function (Blueprint $table) {
            // Same access restriction for recordings
            $table->json('required_roles')->nullable()->after('is_published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('required_roles');
        });

        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn('required_roles');
        });
    }
};
