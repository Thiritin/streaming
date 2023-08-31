<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestStreamListenerCountTest extends TestCase
{
    use RefreshDatabase;
    public function testBasic()
    {
        // Test the UpdateListenerCountJob
        /*
         * $count = DB::table('server_user')
            ->join('clients', 'clients.server_user_id','=','server_user.id')
            ->whereNotNull('clients.start')
            ->whereNull('clients.stop')
            ->groupBy('server_user.id')
            ->count();
        event(new \App\Events\StreamListenerChangeEvent($count));
         */
        // Create Three Server Users with active clients
        $serverUser1 = \App\Models\ServerUser::factory()->create();
        $serverUser2 = \App\Models\ServerUser::factory()->create();
        $serverUser3 = \App\Models\ServerUser::factory()->create();
        // Create Clients for the Server USers
        $client1 = \App\Models\Client::factory()->create([
            'server_user_id' => $serverUser1->id,
            'start' => now(),
            'stop' => null,
        ]);
        $client2 = \App\Models\Client::factory()->create([
            'server_user_id' => $serverUser2->id,
            'start' => now(),
            'stop' => null,
        ]);
        $client3 = \App\Models\Client::factory()->create([
            'server_user_id' => $serverUser3->id,
            'start' => now(),
            'stop' => null,
        ]);
    }
}
