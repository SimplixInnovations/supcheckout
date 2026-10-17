<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Cli;

/**
 * 0b Task 9 review: WP_CLI::confirm() exits 0 on a decline or without a TTY, so a
 * declined or unattended `run` looked successful. Cli reads the answer itself: only an
 * explicit yes from an interactive terminal confirms; CliCommands turns anything else
 * into exit 1 "aborted; nothing changed".
 */
final class CliConfirmTest extends TestCase {
    /** @return array{0: bool, 1: string} */
    private static function ask(string $typed, bool $interactive): array {
        $input = fopen('php://memory', 'w+');
        $output = fopen('php://memory', 'w+');
        fwrite($input, $typed);
        rewind($input);
        $answer = Cli::confirm('Switch the store?', $input, $output, $interactive);
        rewind($output);
        return array($answer, (string) stream_get_contents($output));
    }

    public function test_only_an_explicit_yes_on_a_terminal_confirms(): void {
        $cases = array("y\n" => true, "Y\n" => true, "yes\n" => true, " yes \n" => true, "n\n" => false, "no\n" => false, "\n" => false, '' => false, "yess\n" => false);
        foreach ($cases as $typed => $expected) {
            list($answer, $prompt) = self::ask((string) $typed, true);
            self::assertSame($expected, $answer, (string) json_encode($typed));
            self::assertStringContainsString('Switch the store? [y/n]', $prompt);
        }
    }

    public function test_without_a_terminal_nothing_is_read_and_the_answer_is_no(): void {
        list($answer, $prompt) = self::ask("y\n", false);
        self::assertFalse($answer, 'an unattended run without --yes never proceeds');
        self::assertStringContainsString('--yes', $prompt);
    }
}
