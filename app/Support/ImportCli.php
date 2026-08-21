<?php

namespace App\Support;

/**
 * Where the built import tool can be downloaded from.
 *
 * Every release attaches one binary per platform under a fixed asset name
 * (.github/workflows/streaming-archiver.yml), so the "latest" URL keeps answering with the newest
 * build and nothing in the panel has to be updated per release - the same arrangement the
 * Companion module uses.
 *
 * A fork that publishes its own builds overrides the base URL; an empty one hides the
 * download links, which is what an installation with no published release wants.
 */
final class ImportCli
{
    /**
     * Asset name per platform, in the order the panel offers them. The names are the
     * contract with the release workflow: changing one here without changing it there
     * produces a button that 404s.
     */
    public const PLATFORMS = [
        'macOS (Apple Silicon)' => 'streaming-archiver-macos-arm64',
        'macOS (Intel)' => 'streaming-archiver-macos-amd64',
        'Windows' => 'streaming-archiver-windows-amd64.exe',
        'Linux' => 'streaming-archiver-linux-amd64',
        'Linux (ARM)' => 'streaming-archiver-linux-arm64',
    ];

    /**
     * @return array<int, array{label: string, url: string}>
     */
    public static function downloads(?string $base = null): array
    {
        $base = rtrim(trim((string) ($base ?? config('stream.import_cli_base_url'))), '/');

        if ($base === '') {
            return [];
        }

        return array_map(
            fn (string $label, string $asset) => ['label' => $label, 'url' => "{$base}/{$asset}"],
            array_keys(self::PLATFORMS),
            array_values(self::PLATFORMS),
        );
    }
}
