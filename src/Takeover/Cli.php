<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Switch a store from the official UPayments plugin to SUPCheckout (Sub-project 0b).
 *
 * Registered as `wp supcheckout takeover` from Plugin::boot() and, while the official
 * plugin is active and SUPCheckout is paused, from PausedBoot::boot(). The behaviour and
 * the exit codes live in CliCommands; this class only binds them to WP-CLI. `finalize`
 * arrives with Sub-project 0b Task 11.
 *
 * Exit codes: 0 ok, 1 error or declined, 2 blocked by preflight, 3 verification
 * mismatch, 4 busy (retry later), 5 stale billing claims, 6 state does not allow it,
 * 7 already cut over but the official plugin is active again, 8 rolled back or aborted
 * with later edits kept (conflicts), 9 rolled back or aborted but the official plugin was
 * not reactivated.
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
     * : Deactivate the official UPayments plugin once the copy verifies, before the cutover. Without it, an active official plugin refuses the cutover (exit 2).
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

    /**
     * Roll a cut-over store back to the UPayments identities: back-sync new to legacy,
     * replay the journal (later edits are kept and reported, exit 8), switch back, then
     * remove the SUPCheckout copies. Orders paid meanwhile stay paid. Rerunnable.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * [--deactivate-official]
     * : Deactivate an active official UPayments plugin first (this site only). Without it, an active official plugin refuses the rollback (exit 2): it writes the same legacy data the rollback back-syncs.
     *
     * [--reactivate-official]
     * : Once rolled back, reactivate the official UPayments install the takeover deactivated (this site only; it boots on the next request and SUPCheckout pauses itself).
     *
     * @param array<int, string>    $args       Positional arguments.
     * @param array<string, string> $assoc_args Options.
     * @return void
     */
    public function rollback($args, $assoc_args) {
        self::finish(self::commands()->rollback(isset($assoc_args['yes']), isset($assoc_args['reactivate-official']), isset($assoc_args['deactivate-official'])));
    }

    /**
     * Abort a verified (not yet cut over) takeover: undo any partial cutover writes and
     * remove the SUPCheckout copies. The legacy data was authoritative throughout. Rerunnable.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * [--reactivate-official]
     * : Reactivate the official UPayments install `run` deactivated (this site only; it boots on the next request and SUPCheckout pauses itself).
     *
     * @param array<int, string>    $args       Positional arguments.
     * @param array<string, string> $assoc_args Options.
     * @return void
     */
    public function abort($args, $assoc_args) {
        self::finish(self::commands()->abort(isset($assoc_args['yes']), isset($assoc_args['reactivate-official'])));
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
                // Not WP_CLI::confirm(): it exits 0 on a decline or without a TTY, which
                // would report an aborted run as a success.
                return self::confirm($question, STDIN, STDOUT, self::stdin_is_terminal());
            }
        );
    }

    /**
     * Ask a yes/no question. Only an explicit "y" or "yes" typed on an interactive terminal
     * confirms; a decline, an empty answer, end of input or no terminal at all is a no
     * (WP-CLI never treats static methods as subcommands).
     *
     * @param string   $question    Question.
     * @param resource $input       Where the answer is read from.
     * @param resource $output      Where the prompt is written.
     * @param bool     $interactive The input is a terminal.
     * @return bool
     */
    public static function confirm($question, $input, $output, $interactive) {
        if (!$interactive) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI terminal prompt on STDOUT, not a file.
            fwrite($output, $question . ' No terminal to confirm on: rerun with --yes.' . PHP_EOL);
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI terminal prompt on STDOUT, not a file.
        fwrite($output, $question . ' [y/n] ');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- The operator's answer on STDIN, not a file.
        $answer = fgets($input);
        return is_string($answer) && in_array(strtolower(trim($answer)), array('y', 'yes'), true);
    }

    /** @return bool */
    private static function stdin_is_terminal() {
        if (function_exists('stream_isatty')) {
            return stream_isatty(STDIN);
        }
        return function_exists('posix_isatty') && posix_isatty(STDIN);
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
