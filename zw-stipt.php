<?php
/**
 * Plugin Name:       ZuidWest Stipt
 * Plugin URI:        https://github.com/oszuidwest/zw-stipt
 * Description:       Keeps scheduled posts from ending up as "Missed schedule": restores publish events lost to stale cron writes and publishes overdue posts.
 * Version:           0.0.1
 * Requires at least: 7.1
 * Requires PHP:      8.4
 * Author:            Streekomroep ZuidWest
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zw-stipt
 *
 * @package ZW_Stipt
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'ZWStipt\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Register right away: other plugins can write the cron option before plugins_loaded.
add_filter('pre_update_option_cron', [\ZWStipt\EventGuard::class, 'preserve'], 10, 2);
add_action('init', [\ZWStipt\Sweep::class, 'maybeRun'], 99);
