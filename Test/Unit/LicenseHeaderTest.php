<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit;

use PHPUnit\Framework\TestCase;

class LicenseHeaderTest extends TestCase
{
    private const HEADER = " * Copyright © DMLab. All rights reserved.\n"
        . " * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.\n";

    private const EXTENSIONS = ['php', 'xml', 'phtml', 'js', 'css'];

    public function testEverySourceFileCarriesTheOslHeader(): void
    {
        $missing = [];
        foreach ($this->sourceFiles() as $file) {
            if (!str_contains((string)file_get_contents($file), self::HEADER)) {
                $missing[] = $file;
            }
        }

        self::assertSame([], $missing);
    }

    public function testScanFindsEveryExtension(): void
    {
        $found = array_values(array_unique(array_map(
            static fn (string $file): string => pathinfo($file, PATHINFO_EXTENSION),
            $this->sourceFiles()
        )));

        self::assertEqualsCanonicalizing(self::EXTENSIONS, $found);
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!in_array($file->getExtension(), self::EXTENSIONS, true)
                || preg_match('#(^|/)(vendor|node_modules|\.[^/]+)/#', $relative)
            ) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
