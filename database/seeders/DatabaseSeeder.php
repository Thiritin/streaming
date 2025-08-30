<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Enum\ServerStatusEnum;
use App\Models\Server;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
        $this->call([
            RoleSeeder::class,
            EurofurenceRolesSeeder::class,
        ]);
        if (App::isLocal()) {
            Server::create([
                'hostname' => 'localhost:8080',
                'ip' => 1,
                'status' => ServerStatusEnum::ACTIVE->value,
                'hetzner_id' => null,
                'immutable' => true,
                'shared_secret' => '9fmtTpLSkNF9j8hSoa0sqBSwru53Mahyvrrro9uo7fr',
            ]);
        }
    }
}
