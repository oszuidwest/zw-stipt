<?php
declare(strict_types=1);

namespace ZWStipt\Tests\Integration;

use ZWStipt\Sweep;

final class SweepTest extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_option(Sweep::OPTION);
        add_filter('wp_doing_cron', '__return_true');
    }

    public function test_publishes_an_overdue_post_without_event(): void
    {
        $id = $this->futurePostDue(-Sweep::GRACE - 60);

        Sweep::maybeRun();

        $this->assertSame('publish', get_post_status($id));
    }

    public function test_leaves_a_post_within_the_grace_period_to_core(): void
    {
        $id = $this->futurePostDue(-10);

        Sweep::maybeRun();

        $this->assertSame('future', get_post_status($id));
    }

    public function test_does_not_run_on_admin_requests(): void
    {
        $id = $this->futurePostDue(-Sweep::GRACE - 60);
        add_filter('wp_doing_cron', '__return_false');
        set_current_screen('dashboard');

        Sweep::maybeRun();

        $this->assertSame('future', get_post_status($id));
    }

    public function test_skips_when_another_request_swept_this_interval(): void
    {
        $id = $this->futurePostDue(-Sweep::GRACE - 60);
        add_option(Sweep::OPTION, (string) (time() - 10), '', false);

        Sweep::maybeRun();

        $this->assertSame('future', get_post_status($id));
    }

    /** Create a scheduled post, then move its date without rescheduling, as if its event was lost. */
    private function futurePostDue(int $offset): int
    {
        global $wpdb;
        $id = (int) self::factory()->post->create(['post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
        wp_clear_scheduled_hook('publish_future_post', [$id]);
        $date = gmdate('Y-m-d H:i:s', time() + $offset);
        $wpdb->update($wpdb->posts, ['post_date' => $date, 'post_date_gmt' => $date], ['ID' => $id]);
        clean_post_cache($id);
        return $id;
    }
}
