<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * config/stream.php is the shipped fallback behind the settings pane, so what it reads
 * off the environment is a decision rather than an accident.
 */
class StreamConfigTest extends TestCase
{
    /**
     * The system streamkey has had two env names. STREAM_KEY is the older one, and it is
     * still read so a deployment that only ever set that keeps working - but only when
     * the current name has nothing in it, empty included.
     */
    public function test_the_older_stream_key_name_answers_only_while_the_current_one_is_empty(): void
    {
        $this->assertSame('the-older-name', $this->systemStreamkeyWith([
            'STREAM_SYSTEM_STREAMKEY' => '',
            'STREAM_KEY' => 'the-older-name',
        ]));

        $this->assertSame('the-current-name', $this->systemStreamkeyWith([
            'STREAM_SYSTEM_STREAMKEY' => 'the-current-name',
            'STREAM_KEY' => 'the-older-name',
        ]));

        $this->assertSame('', $this->systemStreamkeyWith([
            'STREAM_SYSTEM_STREAMKEY' => '',
            'STREAM_KEY' => '',
        ]));
    }

    /**
     * What config/stream.php resolves the key to with those variables set.
     *
     * @param  array<string, string>  $variables
     */
    private function systemStreamkeyWith(array $variables): string
    {
        $original = [];

        foreach ($variables as $name => $value) {
            $original[$name] = $_ENV[$name] ?? null;

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return (require base_path('config/stream.php'))['system_streamkey'];
        } finally {
            foreach ($original as $name => $value) {
                if ($value === null) {
                    unset($_ENV[$name], $_SERVER[$name]);
                    putenv($name);

                    continue;
                }

                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }
}
