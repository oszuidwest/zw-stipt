<?php
/**
 * Remove plugin-owned data on uninstall.
 *
 * @package ZW_Stipt
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('zw_stipt_last_sweep');
