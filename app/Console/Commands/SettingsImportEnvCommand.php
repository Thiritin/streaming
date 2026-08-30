<?php

namespace App\Console\Commands;

use App\Support\EnvImport;
use Illuminate\Console\Command;

/**
 * Move what `.env` still supplies into the settings table, once, on deploy day.
 *
 * Configuration lives in `branding_settings` now and is read back over the config
 * repository by RuntimeConfig. Most fields kept their `env()` call as the shipped
 * default, so an installation that deploys and does nothing mostly keeps working - but
 * a variable that lost its reader is silent, and "mostly" is not a thing to deploy on.
 *
 * A dry run is the default and prints the whole picture: what would be written, what
 * would be skipped and why, what a migration is going to do instead, and which variables
 * are now dead. Writing takes --write.
 *
 * The classification lives in App\Support\EnvImport, one entry per registry field. A
 * field with no entry fails the command rather than being skipped quietly, which is what
 * keeps a knob added next month from slipping past the command that exists to find it.
 */
class SettingsImportEnvCommand extends Command
{
    protected $signature = 'settings:import-env
        {--write : Write the rows. Without it nothing is changed}
        {--dry-run : Print what would happen and change nothing. The default, and it wins over --write}';

    protected $description = 'Copy the values this installation still takes from .env into the settings table';

    public function handle(): int
    {
        $unclassified = EnvImport::unclassified();
        $stale = EnvImport::stale();

        if ($unclassified !== [] || $stale !== []) {
            return $this->refuse($unclassified, $stale);
        }

        $rows = EnvImport::plan();
        $write = $this->option('write') && ! $this->option('dry-run');

        $this->newLine();
        $this->line($write
            ? '<options=bold>Writing settings rows from the environment.</>'
            : '<options=bold>Dry run.</> Nothing is written. Add <info>--write</info> to apply.');

        $imports = array_values(array_filter($rows, fn (array $row) => $row['action'] === 'import'));

        if ($write) {
            foreach ($imports as $row) {
                EnvImport::write($row);
            }
        }

        $this->reportImports($imports, $write);
        $this->reportMoved($imports);
        $this->reportSkipped($rows);
        $this->reportSeeded();
        $this->reportObsolete();
        $this->reportOrphans();
        $this->reportRetirable($imports);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $imports
     */
    private function reportImports(array $imports, bool $write): void
    {
        $this->section($write ? 'Written' : 'Would write');

        if ($imports === []) {
            $this->line('  Nothing. Every field is either saved already, unset, or holding its shipped default.');

            return;
        }

        $this->table(
            ['Key', 'Config path', 'From', 'Value'],
            array_map(fn (array $row) => [
                $row['key'],
                $row['path'],
                $row['var'],
                $this->show($row),
            ], $imports),
        );

        if ($write) {
            $this->line('  <info>'.count($imports).'</info> rows written.');
        }
    }

    /**
     * The renamed ones, said loudly: these are the values the old variable no longer
     * feeds the way it used to, so the import is the fix rather than a tidy-up.
     *
     * @param  array<int, array<string, mixed>>  $imports
     */
    private function reportMoved(array $imports): void
    {
        $moved = array_filter($imports, fn (array $row) => $row['class'] === EnvImport::MOVED);

        if ($moved === []) {
            return;
        }

        $this->newLine();
        $this->line('<fg=black;bg=yellow;options=bold> READ THIS </>');

        foreach ($moved as $row) {
            $this->newLine();
            $this->line("  <options=bold>{$row['key']}</> is not fed by the old variable any more.");
            $this->line('  '.$row['note']);
            $this->line('  Importing it pins the value this installation is running with today.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function reportSkipped(array $rows): void
    {
        $reasons = [
            'saved' => 'already saved, and a saved row is the operator\'s decision',
            'default' => 'the environment sets it to what the config file says anyway',
            'empty' => 'the environment sets it to nothing',
            'unset' => 'not set in the environment',
            'panel' => 'never had an environment variable behind it',
        ];

        $skipped = array_filter($rows, fn (array $row) => $row['action'] === 'skip');

        $this->section('Skipped');

        foreach ($reasons as $reason => $because) {
            $keys = array_column(array_filter($skipped, fn (array $row) => $row['reason'] === $reason), 'key');

            if ($keys === []) {
                continue;
            }

            $this->line('  <options=bold>'.count($keys)."</> {$because}");
            $this->line('  <fg=gray>'.implode(', ', $keys).'</>');
            $this->newLine();
        }
    }

    private function reportSeeded(): void
    {
        $seeded = EnvImport::seeded();

        if ($seeded === []) {
            return;
        }

        $this->section('Handed to a migration, not to this command');

        foreach ($seeded as $group) {
            $this->line('  '.implode(', ', $group['vars']));
            $this->line("  <fg=gray>becomes {$group['what']}, by {$group['by']}</>");
            $this->newLine();
        }

        $this->line('  Nothing here is imported: the migration reads these once and the table it writes');
        $this->line('  is the only source afterwards. Leave them in place until the deploy has run.');
    }

    private function reportObsolete(): void
    {
        $obsolete = EnvImport::obsolete();

        if ($obsolete === []) {
            return;
        }

        $this->section('Obsolete, safe to delete from .env');

        foreach ($obsolete as $var => $why) {
            $this->line("  {$var} <fg=gray>- {$why}</>");
        }
    }

    private function reportOrphans(): void
    {
        $orphans = EnvImport::orphans();

        if ($orphans === []) {
            return;
        }

        $this->section('Saved rows nothing reads any more');

        $this->line('  '.implode(', ', $orphans));
        $this->line('  <fg=gray>Their fields left the registry. Delete them from branding_settings when convenient.</>');
    }

    /**
     * @param  array<int, array<string, mixed>>  $imports
     */
    private function reportRetirable(array $imports): void
    {
        $vars = EnvImport::retirable($imports);

        if ($vars === []) {
            return;
        }

        $this->section('Can come out of .env once these rows are written');

        $this->line('  '.implode(', ', $vars));
        $this->line('  <fg=gray>Everything else the import touched is still read by something outside this app -');
        $this->line('  a container, a provisioning script, or the bootstrap that runs before the database.</>');
    }

    /**
     * @param  array<int, string>  $unclassified
     * @param  array<int, string>  $stale
     */
    private function refuse(array $unclassified, array $stale): int
    {
        $this->newLine();
        $this->error('The classification in App\Support\EnvImport does not match config/settings.php.');

        if ($unclassified !== []) {
            $this->newLine();
            $this->line('  Registry fields with no entry, so nothing knows where their value comes from:');
            $this->line('  <options=bold>'.implode(', ', $unclassified).'</>');
        }

        if ($stale !== []) {
            $this->newLine();
            $this->line('  Entries for fields the registry no longer has:');
            $this->line('  <options=bold>'.implode(', ', $stale).'</>');
        }

        $this->newLine();
        $this->line('  Classify them and run this again. Guessing is what this refuses to do.');

        return self::FAILURE;
    }

    /**
     * A value fit to print. A secret is reported as set and never as itself, which is
     * also why the import writes from config rather than echoing what it wrote.
     *
     * @param  array<string, mixed>  $row
     */
    private function show(array $row): string
    {
        if ($row['masked']) {
            return '<fg=gray>(set, '.($row['secure'] ? 'encrypted' : 'stored as written').')</>';
        }

        $value = (string) $row['stored'];

        return mb_strlen($value) > 40 ? mb_substr($value, 0, 39).'…' : $value;
    }

    private function section(string $heading): void
    {
        $this->newLine();
        $this->line("<options=bold>{$heading}</>");
    }
}
