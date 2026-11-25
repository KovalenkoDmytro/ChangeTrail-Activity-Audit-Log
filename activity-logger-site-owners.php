<?php
/**
 * Plugin Name: ChangeTrail – Activity & Audit Log
 * Plugin URI: https://github.com/KovalenkoDmytro/ChangeTrail-Activity-Audit-Log
 * Description: See who changed what and when: logins, failed logins, content, users, plugins, themes and key settings, in an owner-only log under Tools > ChangeTrail.
 * Version: 3.0.0
 * Author: Dmytro Kovalenko
 * Author URI: https://dmytro-kovalenko.ca
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Text Domain: kovalenko-admin-change-audit
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>' . esc_html__('ChangeTrail requires PHP 8.1 or newer.', 'kovalenko-admin-change-audit') . '</p></div>';
        }
    );

    return;
}

require_once __DIR__ . '/includes/class-kac-events.php';
require_once __DIR__ . '/includes/class-kac-settings.php';
require_once __DIR__ . '/includes/functions-db.php';
require_once __DIR__ . '/includes/class-kac-access.php';
require_once __DIR__ . '/includes/class-kac-devices.php';
require_once __DIR__ . '/includes/class-kac-event-logger.php';
require_once __DIR__ . '/includes/class-kac-privacy.php';
require_once __DIR__ . '/includes/class-kac-admin.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/class-kac-plugin.php';

function kovalenko_admin_change_audit(): Kovalenko_Admin_Change_Audit_Plugin
{
    static $plugin = null;

    if (! $plugin instanceof Kovalenko_Admin_Change_Audit_Plugin) {
        $plugin = new Kovalenko_Admin_Change_Audit_Plugin(__FILE__);
        $plugin->register_hooks();
    }

    return $plugin;
}

kovalenko_admin_change_audit();
