<?php

declare(strict_types=1);

namespace DbTools\Tests\Command;

use DbTools\Command\RestoreCommand;
use PHPUnit\Framework\TestCase;

/**
 * The fzf picker records the chosen backup with an execute-silent action, which
 * fzf runs through $SHELL. Run as www-data (sudo -u www-data php vendor/bin/
 * db-tools restore), $SHELL is /usr/sbin/nologin: the action did nothing, no
 * backup was ever chosen, and every restore ended with "Restore cancelled."
 */
final class RestoreFzfCommandTest extends TestCase
{
    public function testFzfRunsItsActionsWithAShellThatExists(): void
    {
        $cmd = $this->fzfCommand('/tmp/out', '/tmp/in');

        $this->assertMatchesRegularExpression('/\| SHELL=\/bin\/sh fzf /', $cmd);
    }

    public function testTheChosenPathStillGoesToTheOutputFile(): void
    {
        $cmd = $this->fzfCommand('/tmp/out', '/tmp/in');

        $this->assertStringContainsString("OUT='/tmp/out'; export OUT; cat '/tmp/in' |", $cmd);
        $this->assertStringContainsString('enter:execute-silent(echo {1} > \"$OUT\")+abort', $cmd);
    }

    private function fzfCommand(string $outputFile, string $inputFile): string
    {
        $method = new \ReflectionMethod(RestoreCommand::class, 'fzfCommand');
        $command = (new \ReflectionClass(RestoreCommand::class))->newInstanceWithoutConstructor();

        return $method->invoke($command, $outputFile, $inputFile);
    }
}
