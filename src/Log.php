<?php
/**
 * Append-only log file with request context.
 *
 * @package ZW_Stipt
 */

declare(strict_types=1);

namespace ZWStipt;

/**
 * Writes one JSON line per event. The default location is outside the web root; define ZW_STIPT_LOG to change it.
 */
final class Log
{
    /** Rotate to .1 once the file is this large. */
    private const MAX_BYTES = 5 * MB_IN_BYTES;

    /**
     * @param string               $event Event name.
     * @param array<string, mixed> $data  Event data.
     */
    public static function write(string $event, array $data): void
    {
        $file = defined('ZW_STIPT_LOG') ? (string) constant('ZW_STIPT_LOG') : dirname(ABSPATH) . '/zw-logs/zw-stipt.log';
        // The write below is tried even if this fails, and falls back to the PHP error log.
        wp_mkdir_p(dirname($file));

        if (is_file($file) && (int) filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.1');
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Set by PHP, cast to float.
        $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $data['request'] = [
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON-encoded into the log, never output.
            'uri'        => substr((string) wp_unslash($_SERVER['REQUEST_URI'] ?? (defined('WP_CLI') && WP_CLI ? 'wp-cli' : '')), 0, 200),
            'method'     => sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')),
            'cron'       => wp_doing_cron(),
            // Cron can write before pluggable.php is loaded.
            'user'       => function_exists('get_current_user_id') ? get_current_user_id() : 0,
            'pid'        => getmypid(),
            'started'    => wp_date('H:i:s', (int) $start) . sprintf('.%03d', ($start - floor($start)) * 1000),
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
        ];

        $line = wp_date('Y-m-d H:i:s') . ' ' . $event . ' ' . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('zw-stipt: ' . rtrim($line)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Keep the event when the log file is not writable.
        }
    }
}
