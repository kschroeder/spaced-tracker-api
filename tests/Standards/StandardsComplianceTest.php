<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Standards;

use PHPUnit\Framework\TestCase;

final class StandardsComplianceTest extends TestCase
{
    public function testSourcesDeclareStrictTypesAndMatchPsr4(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $count = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $count++;
            $contents = (string) file_get_contents($file->getPathname());
            self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);", $contents, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\b(eval|var_dump|var_export|print_r)\s*\(|\b(dump|dd)\s*\(/', $contents, $file->getPathname());
            if (preg_match('/^namespace ([^;]+);/m', $contents, $namespace) !== 1) {
                self::fail('Missing namespace in ' . $file->getPathname());
            }
            if (preg_match('/^(?:final |readonly |abstract )*(class|enum|interface) ([A-Za-z0-9_]+)/m', $contents, $type) !== 1) {
                self::fail('Missing type in ' . $file->getPathname());
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $expected = 'SpacedTracker\\Api\\' . str_replace('/', '\\', $relative);
            self::assertSame($expected, $namespace[1] . '\\' . $type[2], $file->getPathname());
        }

        self::assertGreaterThan(10, $count);
    }
}
