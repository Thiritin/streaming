<?php

namespace App\Services;

use App\Contracts\CommandInterface;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CommandRegistry
{
    /**
     * Registered commands.
     */
    protected array $commands = [];

    /**
     * Command aliases mapping.
     */
    protected array $aliases = [];

    /**
     * Initialize the registry.
     */
    public function __construct()
    {
        $this->discoverCommands();
    }

    /**
     * Discover and register all commands.
     */
    protected function discoverCommands(): void
    {
        // Cache the discovered commands for performance
        $this->commands = Cache::remember('chat_commands', 3600, function () {
            $commands = [];
            $commandPath = app_path('Console/Commands/Chat');
            
            if (!File::exists($commandPath)) {
                return $commands;
            }

            $files = File::allFiles($commandPath);
            
            foreach ($files as $file) {
                $className = $this->getClassNameFromFile($file);
                
                if ($className && class_exists($className)) {
                    $reflection = new \ReflectionClass($className);
                    
                    // Skip abstract classes and non-command classes
                    if ($reflection->isAbstract() || !$reflection->implementsInterface(CommandInterface::class)) {
                        continue;
                    }
                    
                    $command = new $className();
                    $commandName = $command->name();
                    
                    $commands[$commandName] = [
                        'class' => $className,
                        'instance' => null, // Will be instantiated when needed
                        'metadata' => $command->toArray(),
                    ];
                    
                    // Register aliases
                    foreach ($command->aliases() as $alias) {
                        $this->aliases[$alias] = $commandName;
                    }
                }
            }
            
            return $commands;
        });
    }

    /**
     * Get class name from file.
     */
    protected function getClassNameFromFile($file): ?string
    {
        $relativePath = str_replace(app_path() . '/', '', $file->getPathname());
        $relativePath = str_replace('.php', '', $relativePath);
        $relativePath = str_replace('/', '\\', $relativePath);
        
        return 'App\\' . $relativePath;
    }

    /**
     * Get all registered commands.
     */
    public function all(): array
    {
        return $this->commands;
    }

    /**
     * Get commands available to a user.
     */
    public function availableFor(User $user): array
    {
        $available = [];
        
        foreach ($this->commands as $name => $data) {
            $command = $this->get($name);
            if ($command && $command->authorize($user)) {
                $available[$name] = $data['metadata'];
            }
        }
        
        return $available;
    }

    /**
     * Search commands by query.
     */
    public function search(string $query, ?User $user = null): array
    {
        $results = [];
        $query = strtolower($query);
        
        foreach ($this->commands as $name => $data) {
            $metadata = $data['metadata'];
            
            // Check if user can access this command
            if ($user) {
                $command = $this->get($name);
                if (!$command->authorize($user)) {
                    continue;
                }
            }
            
            // Search in name, aliases, and description
            $searchable = strtolower($name . ' ' . 
                implode(' ', $metadata['aliases'] ?? []) . ' ' . 
                ($metadata['description'] ?? ''));
            
            if (str_contains($searchable, $query)) {
                $results[$name] = $metadata;
            }
        }
        
        return $results;
    }

    /**
     * Get a command by name or alias.
     */
    public function get(string $name): ?CommandInterface
    {
        // Check if it's an alias
        if (isset($this->aliases[$name])) {
            $name = $this->aliases[$name];
        }
        
        if (!isset($this->commands[$name])) {
            return null;
        }
        
        // Lazy instantiation
        if ($this->commands[$name]['instance'] === null) {
            $className = $this->commands[$name]['class'];
            $this->commands[$name]['instance'] = new $className();
        }
        
        return $this->commands[$name]['instance'];
    }

    /**
     * Find command by raw input.
     */
    public function findByInput(string $input): ?CommandInterface
    {
        $input = trim($input);
        
        // Remove command prefix
        if (str_starts_with($input, '/') || str_starts_with($input, '!')) {
            $input = substr($input, 1);
        }
        
        // Extract command name (first word)
        $parts = explode(' ', $input);
        $commandName = $parts[0] ?? '';
        
        return $this->get($commandName);
    }

    /**
     * Clear the command cache.
     */
    public function clearCache(): void
    {
        Cache::forget('chat_commands');
        $this->commands = [];
        $this->aliases = [];
        $this->discoverCommands();
    }

    /**
     * Register a command manually.
     */
    public function register(CommandInterface $command): void
    {
        $name = $command->name();
        
        $this->commands[$name] = [
            'class' => get_class($command),
            'instance' => $command,
            'metadata' => $command->toArray(),
        ];
        
        foreach ($command->aliases() as $alias) {
            $this->aliases[$alias] = $name;
        }
    }

    /**
     * Check if a command exists.
     */
    public function has(string $name): bool
    {
        return isset($this->commands[$name]) || isset($this->aliases[$name]);
    }

    /**
     * Get command suggestions for autocomplete.
     */
    public function getSuggestions(string $partial, User $user): array
    {
        $suggestions = [];
        $partial = strtolower(trim($partial, '/!'));
        
        foreach ($this->availableFor($user) as $name => $metadata) {
            if (empty($partial) || str_starts_with($name, $partial)) {
                $suggestions[] = [
                    'name' => $name,
                    'signature' => $metadata['signature'],
                    'description' => $metadata['description'],
                    'aliases' => $metadata['aliases'],
                ];
            }
        }
        
        // Sort by relevance (exact matches first, then alphabetical)
        usort($suggestions, function ($a, $b) use ($partial) {
            if ($a['name'] === $partial) return -1;
            if ($b['name'] === $partial) return 1;
            return strcmp($a['name'], $b['name']);
        });
        
        return array_slice($suggestions, 0, 10); // Limit to 10 suggestions
    }
}