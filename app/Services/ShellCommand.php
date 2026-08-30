<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * One shell-out, with anything untrusted kept off the command line.
 *
 * The static call is the call sites' interface and resolves through the container, so a
 * test can swap the runner and see exactly what nsupdate and dig were asked to do -
 * which is the only way to pin `update replace` without a name server to talk to.
 */
class ShellCommand
{
    public static function execute($cmd, ?string $input = null): string
    {
        return app(static::class)->run($cmd, $input);
    }

    /**
     * @param  string|null  $input  Written to the command's stdin rather than composed
     *                              into $cmd. Anything the caller does not control has
     *                              to arrive this way: a heredoc inside a shell command
     *                              line is expanded by the shell before the program ever
     *                              sees it, so `$(...)` in a hostname would run.
     */
    public function run(string $cmd, ?string $input = null): string
    {
        $process = Process::fromShellCommandline($cmd);

        if ($input !== null) {
            $process->setInput($input);
        }

        $processOutput = '';

        $captureOutput = function ($type, $line) use (&$processOutput) {
            $processOutput .= $line;
        };

        $process->setTimeout(null)
            ->run($captureOutput);

        if ($process->getExitCode()) {
            $exception = new \Exception($cmd.' - '.$processOutput);
            report($exception);

            throw $exception;
        }

        return $processOutput;
    }
}
