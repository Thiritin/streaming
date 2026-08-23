<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });

        /*
         * A show carries the category; its recordings inherit it. The column on
         * recordings is the override, for an edit imported without a show and for
         * the odd cut that is not what its show was.
         */
        Schema::table('shows', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('source_id')->constrained()->nullOnDelete();
        });

        Schema::table('recordings', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('source_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('categories');
    }
};
