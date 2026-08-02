<?php

namespace App\Contracts;

use App\Models\User;

interface CommandInterface
{
    /**
     * Execute the command.
     *
     * @param  User  $user  The user executing the command
     * @param  array  $parameters  The parsed command parameters
     */
    public function handle(User $user, array $parameters): void;

    /**
     * Get the command signature.
     * Example: "timeout {username} {duration}"
     */
    public function signature(): string;

    /**
     * Get the command name.
     */
    public function name(): string;

    /**
     * Get the command description.
     */
    public function description(): string;

    /**
     * Get command aliases.
     */
    public function aliases(): array;

    /**
     * Get validation rules for command parameters.
     */
    public function rules(): array;

    /**
     * Get parameter descriptions for help text.
     */
    public function parameters(): array;

    /**
     * Check if the user can execute this command.
     */
    public function authorize(User $user): bool;

    /**
     * Get the permission required to execute this command.
     */
    public function permission(): ?string;

    /**
     * Send feedback to the user.
     *
     * @param  string  $type  success|error|info|warning
     * @param  array  $data  Additional data to send
     */
    public function feedback(User $user, string $message, string $type = 'info', array $data = []): void;
}
