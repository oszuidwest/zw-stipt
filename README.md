# ZuidWest Stipt

WordPress plugin that keeps scheduled posts from ending up as "Missed schedule".

WordPress keeps all cron events in one option. A cron run that started before an editor scheduled a post can write its older copy of that option back. The post's publish event is then gone and WordPress won't publish the post by itself. The investigation is in [oszuidwest/streekomroep-wp#256](https://github.com/oszuidwest/streekomroep-wp/issues/256).

## How it works

When the cron option is saved, the plugin compares it with the database. A `publish_future_post` event that is in the database, but that the writer never saw, is put back. Other events are left alone.

As a fallback it looks for posts that are still scheduled 60 seconds or more after their publish time. It gives them a new publish event, unless one is already due, and WordPress cron publishes them. This runs at most once a minute, on cron requests.

The first check reads the database just before WordPress writes, so a write in between can still slip through. The fallback catches those. It also can't see cron writes made before the plugin itself has loaded.

There are no settings.

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/oszuidwest/zw-stipt/releases)
2. Upload the ZIP file via WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate the plugin

## Log

The plugin logs to `zw-logs/zw-stipt.log`, one directory above the WordPress root. Each line has a timestamp, the event and the details as JSON.

- `event_restored`: a publish event was put back before saving
- `missed_post_rescheduled`: the fallback gave an overdue post a new publish event

The file is moved to `zw-stipt.log.1` when it grows past 5 MB. To log somewhere else, set this in `wp-config.php`:

```php
define('ZW_STIPT_LOG', '/path/to/zw-stipt.log');
```

## Development

```bash
composer install
composer quality   # PHPCS and PHPStan

npm ci
npm run env:start  # WordPress 7.1 in wp-env
npm run test:php   # Integration tests
```

## License

GPL-2.0-or-later
