<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The import ledger lives on the show itself: one pretalx slot can only ever back one
     * show, and deleting the show is what makes the slot importable again.
     */
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->string('pretalx_slot_id')->nullable()->unique()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropUnique(['pretalx_slot_id']);
            $table->dropColumn('pretalx_slot_id');
        });
    }
};
