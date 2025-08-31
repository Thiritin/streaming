<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CommandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CommandController extends Controller
{
    protected CommandRegistry $registry;

    public function __construct(CommandRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Execute a command.
     */
    public function execute(Request $request)
    {
        $request->validate([
            'command' => 'required|string|max:1000',
        ]);

        $user = Auth::user();
        $commandInput = $request->input('command');

        // Rate limiting for commands
        $key = 'command-execute:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'error' => "Too many commands. Please wait {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }
        RateLimiter::hit($key, 60);

        // Find the command
        $command = $this->registry->findByInput($commandInput);
        
        if (!$command) {
            return response()->json([
                'success' => false,
                'error' => 'Command not found. Type /help for available commands.',
            ], 404);
        }

        // Set the raw input on the command
        if (method_exists($command, 'setRawInput')) {
            $command->setRawInput($commandInput);
        }

        // Check authorization
        if (!$command->authorize($user)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to use this command.',
            ], 403);
        }

        try {
            // Parse parameters from raw input
            $parameters = [];
            if (method_exists($command, 'parseParameters')) {
                $reflection = new \ReflectionMethod($command, 'parseParameters');
                $reflection->setAccessible(true);
                $parameters = $reflection->invoke($command);
            }

            // Execute the command
            $command->handle($user, $parameters);

            // Log command execution
            Log::info('Command executed', [
                'user_id' => $user->id,
                'command' => $command->name(),
                'input' => $commandInput,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Command executed successfully.',
                'command' => $command->name(),
            ]);
        } catch (\Exception $e) {
            Log::error('Command execution failed', [
                'user_id' => $user->id,
                'command' => $command->name(),
                'input' => $commandInput,
                'error' => $e->getMessage(),
            ]);

            // Send error feedback to user
            $command->feedback($user, 'Command failed: ' . $e->getMessage(), 'error');

            return response()->json([
                'success' => false,
                'error' => 'Command execution failed.',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get command suggestions for autocomplete.
     */
    public function suggestions(Request $request)
    {
        $request->validate([
            'query' => 'nullable|string|max:100',
        ]);

        $user = Auth::user();
        $query = $request->input('query', '');

        $suggestions = $this->registry->getSuggestions($query, $user);

        return response()->json([
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Get all available commands for the user.
     */
    public function list()
    {
        $user = Auth::user();
        $commands = $this->registry->availableFor($user);

        // Group commands by category (if we add categories later)
        $grouped = [];
        foreach ($commands as $name => $metadata) {
            $category = $metadata['category'] ?? 'General';
            $grouped[$category][] = $metadata;
        }

        return response()->json([
            'commands' => $commands,
            'grouped' => $grouped,
        ]);
    }

    /**
     * Search commands.
     */
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1|max:100',
        ]);

        $user = Auth::user();
        $query = $request->input('query');

        $results = $this->registry->search($query, $user);

        return response()->json([
            'results' => $results,
        ]);
    }

    /**
     * Get detailed help for a specific command.
     */
    public function help(Request $request)
    {
        $request->validate([
            'command' => 'required|string|max:50',
        ]);

        $user = Auth::user();
        $commandName = $request->input('command');

        $command = $this->registry->get($commandName);

        if (!$command) {
            return response()->json([
                'error' => 'Command not found.',
            ], 404);
        }

        if (!$command->authorize($user)) {
            return response()->json([
                'error' => 'You do not have permission to view this command.',
            ], 403);
        }

        return response()->json([
            'command' => $command->toArray(),
            'examples' => method_exists($command, 'examples') ? $command->examples() : [],
        ]);
    }
}