<?php

namespace App\Providers;

use App\Support\RuntimeConfig;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;

/**
 * Lays the settings table over the config repository, first of the application's
 * providers so everything booted after it reads the values an administrator saved.
 *
 * Applied again per Octane request and per queue job, because both keep a booted
 * container alive for hours: without it a worker would go on serving whatever config
 * held when it started. The map comes from the cache, so re-applying is a cache read,
 * and RuntimeConfig only purges resolved services when the answers have changed.
 */
class RuntimeConfigServiceProvider extends ServiceProvider
{
    /**
     * Commands that write the config repository to disk. Both must see the shipped
     * config, never an administrator's saved values.
     */
    private const DUMPS_CONFIG = ['config:cache', 'optimize'];

    public function boot(): void
    {
        // Cheap first line of defence only. It is a guess at the argv, which a wrapper
        // defeats; the CommandStarting revert below is what makes it correct, and it
        // latches for the process, so the second application config:cache boots to dump
        // is covered too.
        if ($this->cachingConfig()) {
            RuntimeConfig::revert();

            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (in_array($event->command, self::DUMPS_CONFIG, true)) {
                RuntimeConfig::revert();
            }
        });

        RuntimeConfig::apply();

        if (class_exists(RequestReceived::class)) {
            Event::listen(RequestReceived::class, fn () => RuntimeConfig::apply());
        }

        // Horizon's supervisors run Laravel's own Worker, so Looping covers them. The
        // per-job listener is for what it does not reach: a sync connection and anything
        // dispatched inside a request.
        Queue::looping(fn () => RuntimeConfig::apply());
        Event::listen(JobProcessing::class, fn () => RuntimeConfig::apply());
    }

    private function cachingConfig(): bool
    {
        return $this->app->runningInConsole()
            && array_intersect(self::DUMPS_CONFIG, (array) ($_SERVER['argv'] ?? [])) !== [];
    }
}
