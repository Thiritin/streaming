<?php

namespace App\Services;

/**
 * What a provider driver answers when the panel asks whether it works.
 *
 * One shape for DNS and for cloud, because the button that presses them is the same
 * button and a second shape would only mean a second way to render a failure.
 */
final class DriverCheck
{
    /**
     * @param  array<string, string>  $details  Rows shown under the message.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $details = [],
    ) {}

    /**
     * @param  array<string, string>  $details
     */
    public static function pass(string $message, array $details = []): self
    {
        return new self(true, $message, $details);
    }

    /**
     * @param  array<string, string>  $details
     */
    public static function fail(string $message, array $details = []): self
    {
        return new self(false, $message, $details);
    }
}
