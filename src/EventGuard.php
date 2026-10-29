<?php
/**
 * Restores publish events that a stale cron write would drop.
 *
 * @package ZW_Stipt
 */

declare(strict_types=1);

namespace ZWStipt;

/**
 * WordPress stores all cron events in one option. A request changes its in-memory copy and writes the whole array
 * back, so an event that another request added in the meantime is silently dropped. That is how scheduled posts
 * end up as "Missed schedule".
 *
 * Before each write this compares the database with the writer's copy. A publish_future_post event that is in the
 * database but in neither the writer's old nor new value was never seen by the writer, so it is put back.
 * This narrows the race to the moment between this read and core's UPDATE; Sweep covers what remains.
 */
final class EventGuard
{
    public const HOOK = 'publish_future_post';

    /**
     * Filter for pre_update_option_cron.
     *
     * @param mixed $value    The cron array about to be written.
     * @param mixed $oldValue The writer's (possibly stale) copy.
     * @return mixed
     */
    public static function preserve(mixed $value, mixed $oldValue): mixed
    {
        // Keep core's no-op: forcing a write here would store the stale copy and drop other events.
        if (!is_array($value) || $value === $oldValue || maybe_serialize($value) === maybe_serialize($oldValue)) {
            return $value;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass the request's stale options cache.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'cron'));
        if (!is_string($raw)) {
            return $value;
        }

        $inDb = self::events(maybe_unserialize($raw));
        $lost = array_diff_key($inDb, self::events($oldValue), self::events($value));
        if ($lost === []) {
            return $value;
        }

        foreach ($lost as [$timestamp, $key, $event]) {
            $value[$timestamp][self::HOOK][$key] = $event;
            Log::write('event_restored', [
                'post_id'     => (int) ($event['args'][0] ?? 0),
                'post_status' => get_post_status((int) ($event['args'][0] ?? 0)),
                'event_time'  => wp_date('Y-m-d H:i:s', (int) $timestamp),
            ]);
        }

        // wp-cron.php stops at the first future timestamp, so keep the order core uses.
        uksort($value, 'strnatcasecmp');

        return $value;
    }

    /**
     * Index publish events by timestamp and event key, the way core stores them.
     *
     * @param mixed $cron A cron array.
     * @return array<string, array{int|string, string, array<string, mixed>}>
     */
    private static function events(mixed $cron): array
    {
        $events = [];
        if (!is_array($cron)) {
            return $events;
        }
        foreach ($cron as $timestamp => $hooks) {
            if (!is_numeric($timestamp) || !is_array($hooks) || empty($hooks[self::HOOK]) || !is_array($hooks[self::HOOK])) {
                continue;
            }
            foreach ($hooks[self::HOOK] as $key => $event) {
                $events[$timestamp . ':' . $key] = [$timestamp, (string) $key, (array) $event];
            }
        }
        return $events;
    }
}
