<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('level')->default(0)->after('reg_id');
            // drop is_admin, is_attendee
            $table->dropColumn('is_admin');
            $table->dropColumn('is_attendee');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_attendee')->default(false);
            // drop level
            $table->dropColumn('level');
        });
    }
};
