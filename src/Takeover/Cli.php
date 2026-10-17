<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Switch a store from the official UPayments plugin to SUPCheckout (Sub-project 0b).
 *
 * Registered as `wp supcheckout takeover` from Plugin::boot() and, while the official
 * plugin is active and SUPCheckout is paused, from PausedBoot::boot(). The behaviour and
 * the exit codes live in CliCommands; this class only binds them to WP-CLI. `rollback`
 * and `finalize` arrive with Sub-project 0b Tasks 10 and 11.
 *
 * Exit codes: 0 ok, 1 error or declined, 2 blocked by preflight, 3 verification
 * mismatch, 4 busy (retry later), 5 stale billing claims, 6 state does not allow it,
 * 7 cut over but the official plugin is still active.
 */
final class Cli {
    public const COMMAND = 'supcheckout takeover';

    /**
     * Register the command when running under WP-CLI. Registers nothing otherwise.
     *
     * @return void
     */
    public static function register() {
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command(self::COMMAND, self::class);
        }
    }

    /**
     * Read-only preflight report.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * @param array<int, string>    $args       Positional arguments.
     * @param array<string, string> $assoc_args Options.
     * @return void
     */
    public function preflight($args, $assoc_args) {
        self::finish(self::commands()->preflight(self::is_json($assoc_args)));
    }

    /**
     * Copy, verify and cut over. Rerunnable after a crash at any step.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * [--deactivate-official]
     * : Deactivate the official UPayments plugin right after the cutover.
     *
     * @param array<int, string>    $args       Positional arguments.
     * @param array<string, string> $assoc_args Options.
     * @return void
     */
    public function run($args, $assoc_args) {
        self::finish(self::commands()->run(isset($assoc_args['yes']), isset($assoc_args['deactivate-official'])));
    }

    /**
     * Takeover state, progress, journal summary, warnings and stale billing claims.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * @param array<int, string>    $args       Positional arguments.
     * @param array<string, string> $assoc_args Options.
     * @return void
     */
    public function status($args, $assoc_args) {
        self::finish(self::commands()->status(self::is_json($assoc_args)));
    }

    /**
     * Release billing claims stuck in "claimed" for over 600 seconds (no provider request
     * was sent for them), so the cutover can proceed.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @subcommand release-stale-claims
     *
     * @param array<int, string>    $args       Positional arguments.
     * @param array<string, string> $assoc_args Options.
     * @return void
     */
    public function release_stale_claims($args, $assoc_args) {
        self::finish(self::commands()->release_stale_claims(isset($assoc_args['yes'])));
    }

    /** @return CliCommands */
    private static function commands() {
        return new CliCommands(
            array(Preflight::class, 'run'),
            new WordPressCopyStore(),
            new WordPressCutoverSystem(),
            new WordPressStaleClaimStore(),
            static function ($line) {
                \WP_CLI::line($line);
            },
            static function ($question) {
                // WP_CLI::confirm() exits the process unless the answer is yes.
                \WP_CLI::confirm($question);
                return true;
            }
        );
    }

    /**
     * @param array<string, string> $assoc_args Options.
     * @return bool
     */
    private static function is_json($assoc_args) {
        return isset($assoc_args['format']) && $assoc_args['format'] === 'json';
    }

    /**
     * @param int $code Exit code.
     * @return void
     */
    private static function finish($code) {
        if ($code !== CliCommands::OK) {
            \WP_CLI::halt($code);
        }
    }
}
