<?php

declare(strict_types=1);

namespace DbTools\Tests\Console;

use DbTools\Console\ConsoleFactory;
use DbTools\Service\NullRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Boots the real Application wiring. Service-level tests mock the runtime and
 * never touch ConsoleFactory, so this is the only guard against breakage in
 * command registration or framework API changes (e.g. Symfony's removal of
 * Application::add() in favour of addCommand()).
 */
final class ConsoleFactoryTest extends TestCase
{
    /** Every command the application is expected to expose. */
    private const EXPECTED_COMMANDS = [
        'backup',
        'restore',
        'verify',
        'collation',
        'export',
        'clean',
        'pitr-info',
        'pitr-restore',
        'purge-binlogs',
        'setup',
        'config:list',
        'config:show',
        'show',
        'size',
        'import',
        'mysqlcheck',
        'maintain',
        'db:test',
    ];

    public function testApplicationBootsAndListsCommands(): void
    {
        $app = ConsoleFactory::create(new NullRuntime());
        $app->setAutoExit(false);

        $tester = new ApplicationTester($app);
        $exit = $tester->run(['command' => 'list']);

        $this->assertSame(0, $exit, $tester->getDisplay());
    }

    public function testAllExpectedCommandsAreRegistered(): void
    {
        $app = ConsoleFactory::create(new NullRuntime());

        foreach (self::EXPECTED_COMMANDS as $name) {
            $this->assertTrue($app->has($name), "Command '{$name}' is not registered");
        }
    }

    /**
     * Running --help forces each command's configure() to execute, catching
     * per-command construction or definition errors that `list` alone misses.
     */
    public function testEveryCommandHelpBootsWithoutError(): void
    {
        $app = ConsoleFactory::create(new NullRuntime());
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);

        foreach (self::EXPECTED_COMMANDS as $name) {
            $exit = $tester->run(['command' => 'help', 'command_name' => $name]);
            $this->assertSame(0, $exit, "help for '{$name}' failed:\n" . $tester->getDisplay());
        }
    }
}
