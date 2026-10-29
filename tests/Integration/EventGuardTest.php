<?php
declare(strict_types=1);

namespace ZWStipt\Tests\Integration;

use ZWStipt\EventGuard;

final class EventGuardTest extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        _set_cron_array([]);
    }

    public function test_restores_publish_event_added_by_another_request(): void
    {
        $time = time() + HOUR_IN_SECONDS;
        $this->writeElsewhere($this->withPublishEvent(_get_cron_array(), $time, 123));

        wp_schedule_single_event(time() + DAY_IN_SECONDS, 'zw_stipt_test_other');

        $this->assertSame($time, $this->timeInDb(EventGuard::HOOK, [123]));
        $timestamps = array_filter(array_keys($this->cronInDb()), 'is_int');
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps, 'wp-cron.php needs timestamps in order');
    }

    public function test_does_not_undo_an_intentional_unschedule(): void
    {
        $time = time() + HOUR_IN_SECONDS;
        wp_schedule_single_event($time, EventGuard::HOOK, [123]);

        wp_unschedule_event($time, EventGuard::HOOK, [123]);

        $this->assertNull($this->timeInDb(EventGuard::HOOK, [123]));
    }

    public function test_does_not_turn_a_no_op_into_a_stale_write(): void
    {
        $cron = $this->withPublishEvent(_get_cron_array(), time() + HOUR_IN_SECONDS, 123);
        $cron[time() + DAY_IN_SECONDS]['zw_stipt_test_other'][md5(serialize([]))] = ['schedule' => false, 'args' => []];
        $this->writeElsewhere($cron);

        wp_unschedule_event(time() + WEEK_IN_SECONDS, 'zw_stipt_test_missing');

        $this->assertNotNull($this->timeInDb('zw_stipt_test_other', []));
    }

    /**
     * @param array<int, mixed> $cron
     * @return array<int, mixed>
     */
    private function withPublishEvent(array $cron, int $time, int $postId): array
    {
        $cron[$time][EventGuard::HOOK][md5(serialize([$postId]))] = ['schedule' => false, 'args' => [$postId]];
        ksort($cron);
        return $cron;
    }

    /**
     * Write the cron option behind this request's cached copy, as a concurrent request would.
     *
     * @param array<int, mixed> $cron
     */
    private function writeElsewhere(array $cron): void
    {
        global $wpdb;
        $cron['version'] = 2;
        $wpdb->update($wpdb->options, ['option_value' => serialize($cron)], ['option_name' => 'cron']);
    }

    /** @return array<int|string, mixed> */
    private function cronInDb(): array
    {
        global $wpdb;
        return (array) maybe_unserialize($wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron'"));
    }

    /** @param array<int, mixed> $args */
    private function timeInDb(string $hook, array $args): ?int
    {
        foreach ($this->cronInDb() as $time => $hooks) {
            if (is_int($time) && isset($hooks[$hook][md5(serialize($args))])) {
                return $time;
            }
        }
        return null;
    }
}
