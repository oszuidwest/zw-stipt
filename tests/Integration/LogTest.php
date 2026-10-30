<?php
declare(strict_types=1);

namespace ZWStipt\Tests\Integration;

use ZWStipt\Log;

final class LogTest extends \WP_UnitTestCase
{
    public function test_falls_back_to_the_php_error_log_until_the_log_dir_can_be_created(): void
    {
        $dir = dirname(ZW_STIPT_LOG);
        $errorLog = (string) tempnam(sys_get_temp_dir(), 'zw-stipt');
        $previous = (string) ini_set('error_log', $errorLog);
        wp_delete_file(ZW_STIPT_LOG);
        if (is_dir($dir)) {
            rmdir($dir);
        }
        touch($dir);

        Log::write('test_event', ['post_id' => 123]);

        unlink($dir);
        Log::write('test_event', ['post_id' => 456]);

        ini_set('error_log', $previous);
        $this->assertStringContainsString('test_event {"post_id":123,', (string) file_get_contents($errorLog));
        $this->assertStringContainsString('test_event {"post_id":456,', (string) file_get_contents(ZW_STIPT_LOG));
        unlink($errorLog);
    }
}
