<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_attendee')->default(false)->after('is_provisioning');
            $table->unsignedInteger('reg_id')->nullable()->after('is_attendee');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_attendee');
            $table->dropColumn('reg_id');
        });
    }
};
