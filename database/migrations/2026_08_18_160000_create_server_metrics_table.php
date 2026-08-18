<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per server per heartbeat, which is once a minute.
     *
     * Rates rather than counters: the box computes the delta since its own last
     * heartbeat, so a reboot or a missed minute cannot show up here as a spike of
     * several hours' traffic. Everything nullable because a server that has only just
     * been installed has no previous sample to diff against, and manually managed
     * servers may never report at all.
     */
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');

            $table->decimal('cpu_percent', 5, 2)->nullable();
            $table->decimal('load_1', 8, 2)->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();

            $table->unsignedBigInteger('memory_used_bytes')->nullable();
            $table->unsignedBigInteger('memory_total_bytes')->nullable();

            $table->unsignedBigInteger('disk_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();

            // Down and up, as seen by the box: rx is what it pulled from the origin,
            // tx is what it served to viewers. Bytes per second, averaged over the
            // interval between two heartbeats.
            $table->unsignedBigInteger('net_rx_bytes_per_sec')->nullable();
            $table->unsignedBigInteger('net_tx_bytes_per_sec')->nullable();

            $table->unsignedBigInteger('uptime_seconds')->nullable();

            // The app's own view of load at the moment the sample landed, so the
            // viewers graph shares an x axis with the machine graphs.
            $table->unsignedInteger('viewer_count')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
