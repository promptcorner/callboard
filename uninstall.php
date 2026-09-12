<?php
/**
 * Runs when the plugin is deleted: removes Callboard's roles and capabilities.
 *
 * Posts, attachments, options and uploaded files are kept.
 *
 * @package Callboard
 * @since   2.3.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-roles.php';

Callboard\Roles::uninstall();
