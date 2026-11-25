<?php
/**
 * Removes ChangeTrail data when the plugin is deleted from the Plugins screen, unless the owner chose to keep it.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function kovalenko_admin_change_audit_uninstall_site(): void
{
    global $wpdb;

    $settings = get_option('kovalenko_admin_change_audit_settings', []);
    if (is_array($settings) && ! empty($settings['keep_data'])) {
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table on uninstall.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'activity_logs'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table on uninstall.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'changetrail_devices'));

    foreach (
        [
            'kovalenko_admin_change_audit_settings',
            'kovalenko_admin_change_audit_timezone',
            'kovalenko_admin_change_audit_owner_user_id',
            'kovalenko_admin_change_audit_access_password_hash',
            'kovalenko_admin_change_audit_access_slug',
            'kovalenko_admin_change_audit_schema_version',
            'kovalenko_admin_change_audit_legacy_migrated',
        ] as $option
    ) {
        delete_option($option);
    }

    wp_clear_scheduled_hook('kovalenko_admin_change_audit_nightly_maintenance');
    wp_clear_scheduled_hook('kovalenko_admin_change_audit_backfill');
}

kovalenko_admin_change_audit_uninstall_site();

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $kovalenko_admin_change_audit_site_id) {
        switch_to_blog((int) $kovalenko_admin_change_audit_site_id);
        kovalenko_admin_change_audit_uninstall_site();
        restore_current_blog();
    }
}

// User meta is network-wide.
delete_metadata('user', 0, 'kovalenko_admin_change_audit_unlocked_until', '', true);
delete_metadata('user', 0, 'kovalenko_admin_change_audit_per_page', '', true);
