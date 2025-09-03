<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
        ]);
        
        // Create local development servers and test source for testing
        if (App::isLocal()) {
            $this->call([
                LocalDevelopmentServersSeeder::class,
                LocalDevelopmentSourceSeeder::class,
                ShowSeeder::class,
                RecordingSeeder::class,
            ]);
        }
    }
}
