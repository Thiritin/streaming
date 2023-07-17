<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('server_user_id');
            $table->string('client')->nullable();
            $table->string('client_id')->nullable();
            $table->dateTime('start')->nullable();
            $table->dateTime('stop')->nullable();

            $table->timestamps();

            $table->foreign('server_user_id', 'server_user_id_fk')
                ->references('id')
                ->on('server_user')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
