<?php

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;

/**
 * Which DNS provider wrote each server's A record, and into which zone.
 *
 * Nothing recorded it before, so switching provider would have made every existing
 * record undeleteable: the teardown would ask the newly selected API about a name it
 * has never held, and the old one would keep answering for a machine that is gone. The
 * currently configured driver is the only guess available for a row that predates this,
 * and it is the right answer for every row today.
 *
 * `metadata` already exists and is cast to an array, so this is a backfill rather than
 * a schema change. Nothing is written when the guess would be `none`: an unstamped row
 * falls back to the configured driver at read time, which is the same answer without
 * pinning a wrong one that only SQL could undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provider = (string) config('dns.driver', 'none');
        $zone = (string) config('dns.zone', '');

        /*
         * Only a guess worth pinning. `none` is what an installation whose DNS settings
         * this release could not recognise resolves to, and stamping that onto every row
         * would make every existing record undeleteable with no way back but SQL. A row
         * left unstamped falls back to the configured driver when it is read, which is
         * the same answer without the trap.
         */
        if ($provider === '' || $provider === 'none') {
            return;
        }

        Server::query()
            ->whereNotNull('hostname')
            ->where('hostname', '!=', '')
            ->where('hostname', '!=', 'pending')
            ->chunkById(200, function ($servers) use ($provider, $zone) {
                foreach ($servers as $server) {
                    $metadata = $server->metadata ?? [];

                    if (isset($metadata['dns_provider'])) {
                        continue;
                    }

                    $server->newQuery()->whereKey($server->getKey())->update([
                        'metadata' => json_encode($metadata + [
                            'dns_provider' => $provider,
                            'dns_zone' => $zone,
                        ]),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Server::query()->chunkById(200, function ($servers) {
            foreach ($servers as $server) {
                $metadata = $server->metadata ?? [];

                unset($metadata['dns_provider'], $metadata['dns_zone']);

                $server->newQuery()->whereKey($server->getKey())->update([
                    'metadata' => $metadata === [] ? null : json_encode($metadata),
                ]);
            }
        });
    }
};
