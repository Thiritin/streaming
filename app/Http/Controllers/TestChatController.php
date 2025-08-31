<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class TestChatController extends Controller
{
    public function index()
    {
        // Provide mock data for testing command autocomplete
        return Inertia::render('TestChat', [
            'chatCommands' => [
                [
                    'name' => 'timeout',
                    'description' => 'Timeout a user from chatting',
                    'syntax' => '/timeout "username" "duration"',
                    'parameters' => [
                        ['name' => 'username', 'description' => 'The user to timeout', 'required' => true],
                        ['name' => 'duration', 'description' => 'Duration (e.g., 5s, 5m, 5h)', 'required' => true]
                    ],
                    'aliases' => []
                ],
                [
                    'name' => 'slowmode',
                    'description' => 'Enable or disable slow mode',
                    'syntax' => '/slowmode on|off [seconds]',
                    'aliases' => ['slow']
                ],
                [
                    'name' => 'delete',
                    'description' => 'Delete messages',
                    'syntax' => '/delete "username" [count]',
                    'aliases' => []
                ],
                [
                    'name' => 'broadcast',
                    'description' => 'Send a system broadcast',
                    'syntax' => '/broadcast "message"',
                    'aliases' => []
                ],
                [
                    'name' => 'badge',
                    'description' => 'Manage user badges',
                    'syntax' => '/badge <username> <type>',
                    'aliases' => []
                ]
            ],
            'rateLimit' => [
                'secondsLeft' => 0,
                'maxTries' => 10,
                'rateDecay' => 60,
                'slowMode' => false
            ]
        ]);
    }
}