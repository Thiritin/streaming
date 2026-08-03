<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Prints `slug:stream_key` pairs for the local publisher containers.
 *
 * Stream keys are encrypted at rest, so the compose stack cannot read them out
 * of the database itself; scripts/dev-stack.sh calls this and passes the result
 * to the publisher service as an environment variable.
 */
class DevStreamKeys extends Command
{
    protected $signature = 'dev:stream-keys {--limit=0 : Only output this many channels}';

    protected $description = 'Output slug:stream_key pairs for local dev publishers';

    public function handle(): int
    {
        if (! app()->isLocal()) {
            $this->error('dev:stream-keys only runs in the local environment.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');

        $sources = Source::ordered()
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get();

        if ($sources->isEmpty()) {
            $this->error('No sources found. Run: php artisan db:seed --class=DevStreamChannelsSeeder');

            return self::FAILURE;
        }

        // Space separated so the shell can pass it straight through as one env var.
        $this->line($sources->map(fn (Source $source) => $source->slug.':'.$source->stream_key)->implode(' '));

        return self::SUCCESS;
    }
}
