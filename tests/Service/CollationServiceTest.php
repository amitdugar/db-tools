<?php

declare(strict_types=1);

namespace DbTools\Tests\Service;

use DbTools\Service\CollationService;
use DbTools\Tests\Helper\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

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
}
