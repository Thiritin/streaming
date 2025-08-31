<?php

namespace App\Console\Commands\Chat;

use App\Contracts\CommandInterface;
use App\Events\CommandFeedbackEvent;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

abstract class AbstractChatCommand implements CommandInterface
{
    /**
     * The raw command input.
     */
    protected string $rawInput;

    /**
     * Parsed parameters from the command.
     */
    protected array $parsedParameters = [];

    /**
     * Command properties that can be overridden.
     */
    protected string $name = '';
    protected string $signature = '';
    protected string $description = '';
    protected array $aliases = [];
    protected array $parameters = [];

    /**
     * Set the raw command input.
     */
    public function setRawInput(string $input): self
    {
        $this->rawInput = $input;
        return $this;
    }

    /**
     * Get the command signature.
     */
    public function signature(): string
    {
        return $this->signature;
    }

    /**
     * Get the command name.
     */
    public function name(): string
    {
        if ($this->name) {
            return $this->name;
        }
        
        // Extract from signature if not set
        $signature = $this->signature();
        $parts = explode(' ', $signature);
        $name = $parts[0] ?? '';
        return trim($name, '/!');
    }

    /**
     * Get the command description.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Get command aliases.
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /**
     * Get validation rules.
     */
    public function rules(): array
    {
        // Build rules from parameters if not overridden
        $rules = [];
        foreach ($this->parameters as $key => $config) {
            if (isset($config['required']) && $config['required']) {
                $rules[$key] = 'required';
                if (isset($config['type'])) {
                    $rules[$key] .= '|' . $config['type'];
                }
            } elseif (isset($config['type'])) {
                $rules[$key] = 'nullable|' . $config['type'];
            }
        }
        return $rules;
    }

    /**
     * Get parameter descriptions.
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get the permission required.
     */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * Check if user can execute this command.
     */
    public function authorize(User $user): bool
    {
        $permission = $this->permission();
        
        if ($permission === null) {
            return true;
        }

        return $user->can($permission);
    }

    /**
     * Parse parameters from the raw input.
     */
    protected function parseParameters(): array
    {
        if (!empty($this->parsedParameters)) {
            return $this->parsedParameters;
        }

        // Remove command name from input
        $input = trim($this->rawInput);
        $commandName = $this->name();
        
        // Check if input starts with command name or any alias
        $allNames = array_merge([$commandName], $this->aliases());
        foreach ($allNames as $name) {
            if (Str::startsWith($input, '/' . $name)) {
                $input = Str::after($input, '/' . $name);
                break;
            } elseif (Str::startsWith($input, '!' . $name)) {
                $input = Str::after($input, '!' . $name);
                break;
            }
        }

        $input = trim($input);

        // Parse quoted strings and regular arguments
        preg_match_all('/"([^"]+)"|\'([^\']+)\'|(\S+)/', $input, $matches);
        
        $parameters = [];
        foreach ($matches[0] as $match) {
            // Remove quotes if present
            $param = trim($match, '"\'');
            $parameters[] = $param;
        }

        // Map parameters to signature
        $this->parsedParameters = $this->mapToSignature($parameters);
        
        return $this->parsedParameters;
    }

    /**
     * Map raw parameters to command signature.
     */
    protected function mapToSignature(array $rawParams): array
    {
        $signature = $this->signature();
        
        // Extract parameter names from signature - support both {} and <> brackets
        preg_match_all('/[{<]([^}>]+)[}>]/', $signature, $matches);
        $paramNames = $matches[1] ?? [];
        
        $mapped = [];
        foreach ($paramNames as $index => $name) {
            // Remove optional indicator
            $cleanName = str_replace('?', '', $name);
            
            // Check if parameter has default value
            if (str_contains($cleanName, '=')) {
                [$cleanName, $default] = explode('=', $cleanName, 2);
                $mapped[$cleanName] = $rawParams[$index] ?? $default;
            } else {
                // For the last parameter, if there are more raw params than expected,
                // combine them all into the last parameter (for message-like parameters)
                if ($index === count($paramNames) - 1 && count($rawParams) > count($paramNames)) {
                    $mapped[$cleanName] = implode(' ', array_slice($rawParams, $index));
                } else {
                    $mapped[$cleanName] = $rawParams[$index] ?? null;
                }
            }
        }
        
        return $mapped;
    }

    /**
     * Validate command parameters.
     */
    protected function validateParameters(array $parameters): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($parameters, $this->rules());
    }

    /**
     * Execute the command with validation.
     */
    public function handle(User $user, array $parameters): void
    {
        // Set parameters if not already parsed
        if (empty($this->parsedParameters)) {
            $this->parsedParameters = $parameters;
        }

        // Validate parameters
        $validator = $this->validateParameters($this->parsedParameters);
        
        if ($validator->fails()) {
            $errors = $validator->errors()->all();
            $this->feedback($user, implode("\n", $errors), 'error');
            return;
        }

        // Check authorization
        if (!$this->authorize($user)) {
            $this->feedback($user, 'You do not have permission to use this command.', 'error');
            return;
        }

        // Execute the command
        $this->execute($user, $validator->validated());
    }

    /**
     * Execute the command logic.
     */
    abstract protected function execute(User $user, array $parameters): void;

    /**
     * Send feedback to the user.
     */
    public function feedback(User $user, string $message, string $type = 'info', array $data = []): void
    {
        broadcast(new CommandFeedbackEvent($user, $message, $type, $data))
            ->toOthers();
        
        // Also send to the user themselves on their private channel
        broadcast(new CommandFeedbackEvent($user, $message, $type, $data))
            ->via('private-command-feedback.' . $user->id);
    }

    /**
     * Broadcast a system message to all users.
     */
    protected function broadcastSystemMessage(string $message, string $type = 'info'): void
    {
        broadcast(new \App\Events\SystemMessageEvent([
            'id' => uniqid('system_'),
            'type' => 'system',
            'content' => $message,
            'timestamp' => now()->toIso8601String(),
            'system_type' => $type,
        ]))->toOthers();
    }

    /**
     * Get command metadata for frontend.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'signature' => $this->signature(),
            'description' => $this->description(),
            'aliases' => $this->aliases(),
            'parameters' => $this->parameters(),
            'permission' => $this->permission(),
        ];
    }
}