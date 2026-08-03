<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The footer used to be three fixed slots (support, imprint, privacy). It is now
 * a list of {label, url} under one `footer_links` key, so an installation can
 * have any number of them under its own titles.
 *
 * Anything an installation had configured is carried over in the old order,
 * keeping the titles the footer used to hardcode.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const LEGACY = [
        'support_url' => 'Support',
        'imprint_url' => 'Legal Notice',
        'privacy_url' => 'Privacy',
    ];

    public function up(): void
    {
        $rows = DB::table('branding_settings')
            ->whereIn('key', array_keys(self::LEGACY))
            ->pluck('value', 'key');

        $links = [];

        foreach (self::LEGACY as $key => $label) {
            $url = trim((string) ($rows[$key] ?? ''));

            if ($url !== '') {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        if ($links !== []) {
            DB::table('branding_settings')->updateOrInsert(
                ['key' => 'footer_links'],
                [
                    'value' => json_encode($links),
                    'description' => 'Title and address for each footer link, in the order they are shown.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('branding_settings')->whereIn('key', array_keys(self::LEGACY))->delete();
    }

    public function down(): void
    {
        $stored = DB::table('branding_settings')->where('key', 'footer_links')->value('value');
        $links = json_decode((string) $stored, true) ?: [];

        // Only the three known titles can go back into named slots; anything an
        // installation added beyond them has nowhere to live in the old shape.
        foreach (self::LEGACY as $key => $label) {
            $match = collect($links)->firstWhere('label', $label);

            if ($match === null) {
                continue;
            }

            DB::table('branding_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => $match['url'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('branding_settings')->where('key', 'footer_links')->delete();
    }
};
