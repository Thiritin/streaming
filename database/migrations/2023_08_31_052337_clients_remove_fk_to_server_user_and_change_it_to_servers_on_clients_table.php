<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        \App\Models\Client::truncate();
        
        // Check if the column exists before trying to drop it
        if (Schema::hasColumn('clients', 'server_user_id')) {
            if (config('database.default') === 'sqlite') {
                // SQLite requires recreating the table to drop columns with foreign keys
                // Create a temporary table with the new schema
                Schema::create('clients_temp', function (Blueprint $table) {
                    $table->id();
                    $table->foreignIdFor(\App\Models\Server::class)->constrained()->cascadeOnDelete();
                    $table->string('client')->nullable();
                    $table->string('client_id')->nullable();
                    $table->dateTime('start')->nullable();
                    $table->dateTime('stop')->nullable();
                    $table->timestamps();
                });
                
                // Copy data (if any exists)
                DB::statement('INSERT INTO clients_temp (id, client, client_id, start, stop, created_at, updated_at) 
                               SELECT id, client, client_id, start, stop, created_at, updated_at FROM clients');
                
                // Drop old table and rename new one
                Schema::dropIfExists('clients');
                Schema::rename('clients_temp', 'clients');
            } else {
                // For MySQL and other databases that support dropping foreign keys
                Schema::table('clients', function (Blueprint $table) {
                    $table->dropForeign('server_user_id_fk');
                    $table->dropColumn('server_user_id');
                    $table->foreignIdFor(\App\Models\Server::class)->after('id')->constrained()->cascadeOnDelete();
                });
            }
        } else {
            // If column doesn't exist, just add the server_id column
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignIdFor(\App\Models\Server::class)->after('id')->constrained()->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(\App\Models\Server::class);
            $table->foreignId('server_user_id')->nullable()->after('id')->constrained('server_user','id','server_user_id_fk')->cascadeOnDelete();
        });
    }
};
