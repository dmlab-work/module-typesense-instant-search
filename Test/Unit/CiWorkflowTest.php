<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit;

use PHPUnit\Framework\TestCase;

class CiWorkflowTest extends TestCase
{
    /**
     * @var string
     */
    private string $workflow;

    protected function setUp(): void
    {
        $this->workflow = (string)file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        self::assertNotSame('', $this->workflow);
    }

    public function testTestMatrixCoversPhp82To85(): void
    {
        self::assertMatchesRegularExpression(
            "/^\s+php: \['8\.2', '8\.3', '8\.4', '8\.5'\]$/m",
            $this->workflow
        );
    }

    public function testCoverageStaysOnPhp84(): void
    {
        self::assertStringContainsString("coverage: \${{ matrix.php == '8.4' && 'pcov' || 'none' }}", $this->workflow);
        self::assertStringContainsString("if: matrix.php == '8.4'", $this->workflow);
        self::assertStringContainsString(
            "\${{ matrix.php == '8.4' && '--coverage-clover coverage.xml' || '' }}",
            $this->workflow
        );
    }

    public function testDownloadNotificationIsDisabledBeforeComposerInstall(): void
    {
        $configure = strpos($this->workflow, 'name: Configure Magento packages');
        $notify = preg_match(
            '/^\s+- run: composer config notify-on-install false$/m',
            $this->workflow,
            $match,
            PREG_OFFSET_CAPTURE
        ) === 1 ? $match[0][1] : false;
        $install = strpos($this->workflow, 'uses: ramsey/composer-install@');

        self::assertNotFalse($configure);
        self::assertNotFalse($notify);
        self::assertNotFalse($install);
        self::assertGreaterThan($configure, $notify);
        self::assertGreaterThan($notify, $install);
        self::assertSame(1, substr_count($this->workflow, 'uses: ramsey/composer-install@'));
    }

    public function testNoOtherStepRunsComposerInstall(): void
    {
        self::assertDoesNotMatchRegularExpression('/composer (install|update)\b/', $this->workflow);
    }
}
