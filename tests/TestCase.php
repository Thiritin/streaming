<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-morning and on the hour. Shows, events and the archive all group by
        // calendar day and segment tokens bucket by the minute, so a suite left on the
        // wall clock passes or fails by the hour it is run at. A test that wants a
        // boundary travels to it from here rather than waiting for one to come round.
        $this->travelTo(today()->addHours(10));
    }
}
