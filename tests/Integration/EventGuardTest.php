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
        $postId = $this->scheduledElsewhere();
        $time = time() + HOUR_IN_SECONDS;
        $this->writeElsewhere($this->withPublishEvent(_get_cron_array(), $time, $postId));

        wp_schedule_single_event(time() + DAY_IN_SECONDS, 'zw_stipt_test_other');

        $this->assertSame($time, $this->timeInDb(EventGuard::HOOK, [$postId]));
        $timestamps = array_filter(array_keys($this->cronInDb()), 'is_int');
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps, 'wp-cron.php needs timestamps in order');
    }

    /** @dataProvider unscheduledStatuses */
    public function test_does_not_restore_event_of_unscheduled_post(string $status): void
    {
        $postId = self::factory()->post->create(['post_status' => $status]);
        $scheduledId = $this->scheduledElsewhere();
        $cron = $this->withPublishEvent(_get_cron_array(), time() - MINUTE_IN_SECONDS, $postId);
        $this->writeElsewhere($this->withPublishEvent($cron, time() + HOUR_IN_SECONDS, $scheduledId));

        wp_schedule_single_event(time() + DAY_IN_SECONDS, 'zw_stipt_test_other');

        $this->assertNull($this->timeInDb(EventGuard::HOOK, [$postId]));
        $this->assertNotNull($this->timeInDb(EventGuard::HOOK, [$scheduledId]), 'A skipped event must not stop the restore');
    }

    /** @return array<string, array{string}> */
    public static function unscheduledStatuses(): array
    {
        return ['published' => ['publish'], 'trashed' => ['trash']];
    }

    public function test_does_not_undo_an_intentional_unschedule(): void
    {
        $postId = $this->scheduledElsewhere();
        $time = time() + HOUR_IN_SECONDS;
        wp_schedule_single_event($time, EventGuard::HOOK, [$postId]);

        wp_unschedule_event($time, EventGuard::HOOK, [$postId]);

        $this->assertNull($this->timeInDb(EventGuard::HOOK, [$postId]));
    }

    public function test_does_not_turn_a_no_op_into_a_stale_write(): void
    {
        $cron = $this->withPublishEvent(_get_cron_array(), time() + HOUR_IN_SECONDS, $this->scheduledElsewhere());
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
     * A post that another request scheduled, while this request still has it cached as published.
     */
    private function scheduledElsewhere(): int
    {
        global $wpdb;
        $postId = self::factory()->post->create();
        $wpdb->update($wpdb->posts, ['post_status' => 'future'], ['ID' => $postId]);
        return $postId;
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
