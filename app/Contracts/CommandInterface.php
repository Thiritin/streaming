<?php

namespace App\Contracts;

use App\Models\User;

interface CommandInterface
{
    /**
     * Execute the command.
     *
     * @param User $user The user executing the command
     * @param array $parameters The parsed command parameters
     * @return void
     */
    public function handle(User $user, array $parameters): void;

    /**
     * Get the command signature.
     * Example: "timeout {username} {duration}"
     *
     * @return string
     */
    public function signature(): string;

    /**
     * Get the command name.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Get the command description.
     *
     * @return string
     */
    public function description(): string;

    /**
     * Get command aliases.
     *
     * @return array
     */
    public function aliases(): array;

    /**
     * Get validation rules for command parameters.
     *
     * @return array
     */
    public function rules(): array;

    /**
     * Get parameter descriptions for help text.
     *
     * @return array
     */
    public function parameters(): array;

    /**
     * Check if the user can execute this command.
     *
     * @param User $user
     * @return bool
     */
    public function authorize(User $user): bool;

    /**
     * Get the permission required to execute this command.
     *
     * @return string|null
     */
    public function permission(): ?string;

    /**
     * Send feedback to the user.
     *
     * @param User $user
     * @param string $message
     * @param string $type success|error|info|warning
     * @param array $data Additional data to send
     * @return void
     */
    public function feedback(User $user, string $message, string $type = 'info', array $data = []): void;
}