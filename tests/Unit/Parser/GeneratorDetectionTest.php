<?php

declare(strict_types=1);

namespace LegacyAnalyzer\Tests\Unit\Parser;

use LegacyAnalyzer\Model\Codebase;
use LegacyAnalyzer\Parser\ProjectParser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GeneratorDetectionTest extends TestCase
{
    private static Codebase $codebase;

    public static function setUpBeforeClass(): void
    {
        $fixture = dirname(__DIR__, 2) . '/fixtures/generator-project';
        self::$codebase = (new ProjectParser())->parse($fixture)->codebase;
    }

    public function testMethodWithYieldIsGenerator(): void
    {
        $methods = [];
        foreach (self::$codebase->classLike('Gen\Numbers')->methods as $m) {
            $methods[$m->name] = $m->isGenerator;
        }

        self::assertSame(['getIterator' => true, 'plain' => false], $methods);
    }

    public function testFunctionsAreFlagged(): void
    {
        self::assertTrue(self::$codebase->function('Gen\countUp')->isGenerator);
        self::assertFalse(self::$codebase->function('Gen\notAGenerator')->isGenerator);
    }
}
