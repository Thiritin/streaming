<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which channel a pretalx room streams on. Remembered so the mapping is decided once
     * per event rather than on every import. Keyed by event slug too: room ids are only
     * unique within an event.
     */
    public function up(): void
    {
        Schema::create('pretalx_room_sources', function (Blueprint $table) {
            $table->id();
            $table->string('event_slug');
            $table->unsignedBigInteger('room_id');
            $table->string('room_name')->nullable();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_slug', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pretalx_room_sources');
    }
};
