<?php
/**
 * Runs when Callboard is deleted from the Plugins screen.
 *
 * @package Callboard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/callboard.php';

Callboard\Plugin::uninstall();
