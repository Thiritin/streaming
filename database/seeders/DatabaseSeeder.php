<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Enum\ServerStatusEnum;
use App\Models\Server;
use Illuminate\Database\Seeder;

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

        Server::create([
            "hostname" => "test.stream.eurofurence.org",
            "ip" => 1,
            "status" => ServerStatusEnum::ACTIVE->value,
            "hetzner_id" => 1,
            "cloudflare_id" => 1
        ]);
    }
}
