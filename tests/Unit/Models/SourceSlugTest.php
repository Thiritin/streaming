<?php

namespace Tests\Unit\Models;

use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SourceSlugTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_slug_is_derived_from_the_name_on_create()
    {
        $source = Source::factory()->create(['name' => 'Main Stage', 'slug' => null]);

        $this->assertSame('main-stage', $source->slug);
    }

    #[Test]
    public function renaming_a_source_leaves_the_slug_alone()
    {
        $source = Source::factory()->create(['name' => 'Main Stage', 'slug' => 'main-stage']);

        $source->update(['name' => 'Second Stage']);

        $this->assertSame('main-stage', $source->fresh()->slug);
    }

    #[Test]
    public function the_slug_cannot_be_changed_after_create()
    {
        $source = Source::factory()->create(['slug' => 'main-stage']);

        $source->update(['slug' => 'somewhere-else']);

        $this->assertSame('main-stage', $source->fresh()->slug);
    }

    #[Test]
    public function the_rtmp_url_keeps_the_original_slug_after_a_rename()
    {
        $source = Source::factory()->create(['name' => 'Main Stage', 'slug' => 'main-stage']);

        $source->update(['name' => 'Renamed Stage']);

        $this->assertStringContainsString('/main-stage?secret=', $source->fresh()->getRtmpPushUrl());
    }
}
