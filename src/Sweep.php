<?php
/**
 * Publishes scheduled posts whose time has passed.
 *
 * @package ZW_Stipt
 */

declare(strict_types=1);

namespace ZWStipt;

/**
 * Backstop that finds posts without relying on the cron option: it reads overdue `future` posts from the posts table
 * and schedules a publish event for them, so core publishes them. Runs at most once a minute, in cron requests.
 */
final class Sweep
{
    public const OPTION = 'zw_stipt_last_sweep';

    /** Minimum seconds between sweeps. */
    public const INTERVAL = 60;

    /** Seconds a post must be overdue before the sweep steps in, so core's own event goes first. */
    public const GRACE = 60;

    /** Posts handled per sweep. */
    private const LIMIT = 20;

    /**
     * Run the sweep when this request may and no other request swept in the last interval.
     */
    public static function maybeRun(): void
    {
        if (wp_doing_cron() && self::claim()) {
            self::run();
        }
    }

    /**
     * Throttle: only the request that moves the stored timestamp forward may sweep. This prevents two requests
     * from sweeping in the same interval; it is not a mutex with core's own publishing.
     */
    private static function claim(): bool
    {
        global $wpdb;

        $now = time();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set on the throttle row.
        $last = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));

        if ($last === null) {
            $claimed = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::OPTION, $now));
        } elseif ((int) $last > $now - self::INTERVAL) {
            $claimed = 0;
        } else {
            $claimed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, self::OPTION, $last));
        }
        // phpcs:enable

        return (bool) $claimed;
    }

    /**
     * Schedule a publish event for overdue posts that lost theirs, and log each one.
     */
    private static function run(): void
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::GRACE);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the current status, not a cached one.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'future' AND post_date_gmt <= %s ORDER BY post_date_gmt ASC LIMIT %d", $cutoff, self::LIMIT));

        foreach ($ids as $id) {
            $id = (int) $id;
            clean_post_cache($id);
            $post = get_post($id);
            if (!$post || $post->post_status !== 'future') {
                continue;
            }

            $context = [
                'post_id'       => $id,
                'post_type'     => $post->post_type,
                'title'         => wp_html_excerpt($post->post_title, 80),
                'post_date'     => $post->post_date,
                'overdue_s'     => time() - (int) strtotime($post->post_date_gmt . ' GMT'),
                'last_modified' => $post->post_modified,
                'edit_last'     => (int) get_post_meta($id, '_edit_last', true),
            ];

            // Publishing here could race a cron run that already took the event, so leave that to core's cron.
            // Core refuses this when the post already has an event that is due or due within 10 minutes.
            if (wp_schedule_single_event(time(), EventGuard::HOOK, [$id])) {
                Log::write('missed_post_rescheduled', $context);
            }
        }
    }
}
