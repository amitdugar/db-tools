<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\CollationService;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CollationServiceTest extends TestCase
{
    public function testRunsAlterDatabase(): void
    {
        $runner = new FakeProcessRunner();
        $service = new CollationService($runner);

        $service->changeCollation([
            'database' => 'testdb',
            'collation' => 'utf8mb4_unicode_ci',
            'charset' => 'utf8mb4',
            'host' => 'localhost',
            'user' => 'root',
        ]);

        $this->assertNotEmpty($runner->invocations);
        $cmd = $runner->invocations[0]['cmd'];
        $this->assertSame('mysql', $cmd[0]);
        $this->assertStringContainsString('ALTER DATABASE', implode(' ', $cmd));
    }

    public function testSortTablesByDependenciesWithNoDependencies(): void
    {
        $runner = new FakeProcessRunner();
        $service = new CollationService($runner);

        $tables = ['alpha', 'beta', 'gamma'];
        $dependencies = [];

        $sorted = $service->sortTablesByDependencies($tables, $dependencies);

        // With no dependencies, tables should stay in original order
        $this->assertSame(['alpha', 'beta', 'gamma'], $sorted);
    }

    public function testSortTablesByDependenciesParentsBeforeChildren(): void
    {
        $runner = new FakeProcessRunner();
        $service = new CollationService($runner);

        // child -> [parent] means child references parent
        $tables = ['child', 'parent', 'grandchild'];
        $dependencies = [
            'child' => ['parent'],
            'grandchild' => ['child'],
        ];

        $sorted = $service->sortTablesByDependencies($tables, $dependencies);

        // Parent should come before child, child before grandchild
        $parentPos = array_search('parent', $sorted, true);
        $childPos = array_search('child', $sorted, true);
        $grandchildPos = array_search('grandchild', $sorted, true);

        $this->assertLessThan($childPos, $parentPos, 'Parent should come before child');
        $this->assertLessThan($grandchildPos, $childPos, 'Child should come before grandchild');
    }

    public function testSortTablesByDependenciesWithMultipleParents(): void
    {
        $runner = new FakeProcessRunner();
        $service = new CollationService($runner);

        // junction table references both users and products
        $tables = ['junction', 'users', 'products'];
        $dependencies = [
            'junction' => ['users', 'products'],
        ];

        $sorted = $service->sortTablesByDependencies($tables, $dependencies);

        $junctionPos = array_search('junction', $sorted, true);
        $usersPos = array_search('users', $sorted, true);
        $productsPos = array_search('products', $sorted, true);

        $this->assertLessThan($junctionPos, $usersPos, 'Users should come before junction');
        $this->assertLessThan($junctionPos, $productsPos, 'Products should come before junction');
    }

    public function testSortTablesByDependenciesHandlesCycles(): void
    {
        $runner = new FakeProcessRunner();
        $service = new CollationService($runner);

        // Circular dependency: a -> b -> c -> a
        $tables = ['a', 'b', 'c'];
        $dependencies = [
            'a' => ['c'],
            'b' => ['a'],
            'c' => ['b'],
        ];

        $sorted = $service->sortTablesByDependencies($tables, $dependencies);

        // All tables should be in the result even with cycles
        $this->assertCount(3, $sorted);
        $this->assertContains('a', $sorted);
        $this->assertContains('b', $sorted);
        $this->assertContains('c', $sorted);
    }

    /** @return iterable<string, array{bool, ?bool, bool}> */
    public static function conversionModes(): iterable
    {
        foreach ([false, true] as $columnOnly) {
            foreach (['default' => null, 'disabled' => true, 'enforced' => false] as $mode => $disableChecks) {
                foreach ([false, true] as $dryRun) {
                    $name = ($columnOnly ? 'column' : 'table') . '-' . $mode . ($dryRun ? '-dry-run' : '');
                    yield $name => [$columnOnly, $disableChecks, $dryRun];
                }
            }
        }
    }

    #[DataProvider('conversionModes')]
    public function testConversionsRespectForeignKeyCheckMode(bool $columnOnly, ?bool $disableChecks, bool $dryRun): void
    {
        $runner = new FakeProcessRunner();
        $target = 'utf8mb4_0900_ai_ci';
        $tableCollation = $columnOnly ? $target : 'utf8mb4_general_ci';
        $runner->scriptedOutput = ["TABLE_NAME\tTABLE_COLLATION\tsize_mb\nshipment\t{$tableCollation}\t1"];
        if (!$columnOnly && !$dryRun) {
            $runner->scriptedOutput[] = ''; // Table ALTER output.
        }
        $runner->scriptedOutput[] = "COLUMN_NAME\tCOLUMN_TYPE\tIS_NULLABLE\tCOLUMN_DEFAULT\tEXTRA\tCOLLATION_NAME\tCOLUMN_COMMENT"
            . ($columnOnly ? "\nscheme_type\tvarchar(256)\tYES\tNULL\t\tutf8mb4_general_ci\t" : '');
        if ($columnOnly && !$dryRun) {
            $runner->scriptedOutput[] = ''; // Column ALTER output.
            $runner->scriptedOutput[] = "COLLATION_NAME\n{$target}";
        }
        $options = [...self::options(), 'collation' => $target, 'table' => 'shipment', 'dry_run' => $dryRun];
        if ($disableChecks !== null) {
            $options['disable_fk_checks'] = $disableChecks;
        }

        $result = (new CollationService($runner))->convert($options);

        $this->assertSame([], $result['errors']);
        $this->assertSame($columnOnly && !$dryRun ? 1 : 0, $result['columns_converted']);
        $this->assertSame(!$columnOnly && !$dryRun ? 1 : 0, $result['tables_converted']);
        $conversionQueries = [];
        foreach ($runner->invocations as $invocation) {
            $query = end($invocation['cmd']);
            $this->assertIsString($query);
            if (str_contains($query, 'ALTER TABLE')) {
                $conversionQueries[] = $query;
            }
            if ($dryRun) {
                $this->assertStringNotContainsString('SET FOREIGN_KEY_CHECKS', $query);
            }
        }
        $this->assertCount($dryRun ? 0 : 1, $conversionQueries);
        if (!$dryRun) {
            $alter = $columnOnly
                ? 'ALTER TABLE `shipment` MODIFY COLUMN `scheme_type` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL'
                : 'ALTER TABLE `shipment` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci';
            $expected = $disableChecks === false ? $alter : 'SET FOREIGN_KEY_CHECKS=0; ' . $alter . '; SET FOREIGN_KEY_CHECKS=1;';
            // One invocation proves the session setting and conversion cannot use separate connections.
            $this->assertSame($expected, $conversionQueries[0]);
        }
    }

    private static function options(): array
    {
        return ['database' => 'testdb', 'host' => 'localhost', 'user' => 'root'];
    }

    public function testRecommendsUniversalCollationForMariaDb(): void
    {
        $runner = new FakeProcessRunner();
        // runQuery does not skip column names, so the header precedes the value.
        $runner->scriptedOutput = ["VERSION()\n11.4.8-MariaDB-log"];
        $service = new CollationService($runner);

        $this->assertSame('utf8mb4_unicode_ci', $service->getRecommendedCollation(self::options()));
    }

    public function testRecommendsModernCollationForMysql8(): void
    {
        $runner = new FakeProcessRunner();
        $runner->scriptedOutput = ["VERSION()\n8.0.36"];
        $service = new CollationService($runner);

        $this->assertSame('utf8mb4_0900_ai_ci', $service->getRecommendedCollation(self::options()));
    }

    public function testMariaDbIsNotReportedAsMysql8(): void
    {
        $runner = new FakeProcessRunner();
        $runner->scriptedOutput = ["VERSION()\n11.4.8-MariaDB-log"];
        $service = new CollationService($runner);

        $this->assertFalse($service->isMysql8OrHigher(self::options()));
    }

    public function testServerVersionIsQueriedOnlyOnce(): void
    {
        $runner = new FakeProcessRunner();
        $runner->scriptedOutput = ["VERSION()\n10.11.6-MariaDB"];
        $service = new CollationService($runner);

        $service->getRecommendedCollation(self::options());
        $service->getRecommendedCollation(self::options());

        // A second query would consume the empty queue and parse as 0.0.0.
        $this->assertSame('MariaDB 10.11.6', $service->serverVersion(self::options())->describe());
        $this->assertCount(1, $runner->invocations);
    }

    public function testUnreachableServerDoesNotRecommendVersionSpecificCollation(): void
    {
        $runner = new FakeProcessRunner();
        $service = new CollationService($runner);

        $this->assertSame('utf8mb4_unicode_ci', $service->getRecommendedCollation(self::options()));
    }
}
