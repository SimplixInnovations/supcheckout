<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BlocksAccessibilityContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../../assets/js/upayments-block.js';
        $source = file_get_contents($path);

        self::assertIsString($source, 'Blocks checkout source must be readable.');
        $this->source = $source;
    }

    public function testToastIsAPoliteAtomicStatusRegion(): void
    {
        self::assertStringContainsString("role: 'status'", $this->source);
        self::assertStringContainsString("'aria-live': 'polite'", $this->source);
        self::assertStringContainsString("'aria-atomic': 'true'", $this->source);
    }

    public function testSaveCardPromptIsExplicitlyAssociatedWithCheckbox(): void
    {
        self::assertStringContainsString("htmlFor: 'chkSaveCard'", $this->source);
        self::assertStringContainsString(
            "createElement('span', {\n                                    className: 'switch'",
            $this->source
        );
        self::assertStringNotContainsString(
            "createElement('label', {\n                                    className: 'switch'",
            $this->source
        );
    }

    public function testPaymentIconImagesAreExplicitlyDecorative(): void
    {
        preg_match_all("/createElement\\('img',\\s*\\{(.*?)\\}\\)/s", $this->source, $matches);

        self::assertNotEmpty($matches[1], 'Expected Blocks payment icon images.');
        foreach ($matches[1] as $imageProps) {
            self::assertStringContainsString("alt: ''", $imageProps);
        }
    }

    public function testBlocksSourceEndsWithNewline(): void
    {
        self::assertStringEndsWith("\n", $this->source);
    }
}
