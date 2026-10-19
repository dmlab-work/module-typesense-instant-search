<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit;

use PHPUnit\Framework\TestCase;

class I18nTest extends TestCase
{
    private const SOURCE_DIRS = ['Block', 'Console', 'Controller', 'Model', 'Observer', 'ViewModel', 'view'];

    public function testCsvHasTwoColumnsPerRow(): void
    {
        foreach ($this->csvRows() as $row) {
            self::assertCount(2, $row, implode(',', $row));
        }
    }

    public function testEverySystemXmlTranslatableNodeIsInCsv(): void
    {
        $xml = simplexml_load_file($this->root() . '/etc/adminhtml/system.xml');
        self::assertNotFalse($xml);

        $phrases = [];
        foreach ($xml->xpath('//*[@translate]') as $element) {
            foreach (explode(' ', (string)$element['translate']) as $child) {
                if (isset($element->{$child})) {
                    $phrases[] = trim((string)$element->{$child});
                }
            }
        }

        self::assertContains('Query Mode', $phrases);
        self::assertSame([], array_values(array_diff($phrases, $this->csvPhrases())));
    }

    public function testEveryCodeLiteralIsInCsv(): void
    {
        $phrases = [];
        foreach ($this->sourceFiles() as $file) {
            $pattern = str_ends_with($file, '.js')
                ? "/\\\$t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/"
                : "/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/";
            preg_match_all($pattern, (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $literal) {
                $phrases[] = stripslashes($literal);
            }
        }

        self::assertContains('Proxy (Typesense stays private)', $phrases);
        self::assertContains('Search suggestions', $phrases);
        self::assertSame([], array_values(array_diff($phrases, $this->csvPhrases())));
    }

    /**
     * @return list<string>
     */
    private function csvPhrases(): array
    {
        return array_map(static fn (array $row): string => (string)$row[0], $this->csvRows());
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function csvRows(): array
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'rb');
        self::assertNotFalse($handle);

        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (self::SOURCE_DIRS as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), ['php', 'phtml', 'js'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
