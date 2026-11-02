<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Kovalenko_Admin_Change_Audit_Plugin
{
    public const VERSION = '2.8.1';
    public const SCHEMA_VERSION = '2.8.1';
    public const VIEW_CAPABILITY = 'manage_options';
    public const NIGHTLY_MAINTENANCE_HOOK = 'kovalenko_admin_change_audit_nightly_maintenance';
    public const LOG_RETENTION_DAYS = 30;
    public const OPTION_ACCESS_SLUG = 'kovalenko_admin_change_audit_access_slug';
    public const OPTION_OWNER_USER_ID = 'kovalenko_admin_change_audit_owner_user_id';
    public const OPTION_SHOW_ACCESS_NOTICE = 'kovalenko_admin_change_audit_show_access_notice';
    public const OPTION_ACCESS_PASSWORD_HASH = 'kovalenko_admin_change_audit_access_password_hash';
    public const OPTION_SCHEMA_VERSION = 'kovalenko_admin_change_audit_schema_version';
    public const OPTION_TIMEZONE = 'kovalenko_admin_change_audit_timezone';
    public const USER_META_UNLOCKED_UNTIL = 'kovalenko_admin_change_audit_unlocked_until';
    public const ACCESS_PASSWORD_CONST = 'KOVALENKO_ADMIN_CHANGE_AUDIT_ACCESS_PASSWORD';
    public const OPTION_LEGACY_MIGRATED = 'kovalenko_admin_change_audit_legacy_migrated';

    // Keys written by the earlier "WP Activity Logger" plugin (<= 2.6.2). They are only read once to import
    // settings and to clean up leftovers; this plugin never writes to the legacy options.
    public const LEGACY_OPTION_ACCESS_SLUG = 'wp_activity_logger_access_slug';
    public const LEGACY_OPTION_OWNER_USER_ID = 'wp_activity_logger_owner_user_id';
    public const LEGACY_OPTION_SHOW_ACCESS_NOTICE = 'wp_activity_logger_show_access_notice';
    public const LEGACY_OPTION_ACCESS_PASSWORD_HASH = 'wp_activity_logger_access_password_hash';
    public const LEGACY_OPTION_TIMEZONE = 'wp_activity_logger_timezone';
    public const LEGACY_USER_META_UNLOCKED_UNTIL = 'wp_activity_logger_unlocked_until';
    public const LEGACY_ACCESS_PASSWORD_CONST = 'WP_ACTIVITY_LOGGER_ACCESS_PASSWORD';
    public const LEGACY_CRON_HOOKS = [
        'wp_activity_logger_nightly_update',
        'wp_activity_logger_nightly_maintenance',
    ];

    private readonly Kovalenko_Admin_Change_Audit_Admin_Service $admin_service;
    private readonly Kovalenko_Admin_Change_Audit_Event_Logger $event_logger;

    public function __construct(
        private readonly string $plugin_file
    ) {
        $this->admin_service = new Kovalenko_Admin_Change_Audit_Admin_Service(
            plugin_file: $this->plugin_file,
            schedule_maintenance: $this->schedule_nightly_maintenance(...)
        );
        $this->event_logger = new Kovalenko_Admin_Change_Audit_Event_Logger();
    }

    public function register_hooks(): void
    {
        register_activation_hook($this->plugin_file, [$this, 'install']);
        register_deactivation_hook($this->plugin_file, [$this, 'deactivate']);

        add_action('init', [$this, 'maybe_upgrade_schema'], 1);
        add_action('init', [$this, 'schedule_nightly_maintenance']);
        add_action(self::NIGHTLY_MAINTENANCE_HOOK, [$this, 'run_nightly_maintenance']);

        $this->admin_service->register_hooks();
        $this->event_logger->register_hooks();
    }

    public function install(): void
    {
        $this->upgrade_schema();
        $this->admin_service->setup_on_install();
    }

    public function maybe_upgrade_schema(): void
    {
        // Cheap in-memory check of the cached cron option; no writes unless a legacy hook is still scheduled.
        self::clear_legacy_cron_hooks();

        if (get_option(self::OPTION_SCHEMA_VERSION, '') === self::SCHEMA_VERSION) {
            return;
        }

        $this->upgrade_schema();
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::NIGHTLY_MAINTENANCE_HOOK);
        wp_clear_scheduled_hook('kovalenko_admin_change_audit_nightly_update');
    }

    public function schedule_nightly_maintenance(): void
    {
        if (wp_next_scheduled(self::NIGHTLY_MAINTENANCE_HOOK) !== false) {
            return;
        }

        wp_schedule_event(
            kovalenko_admin_change_audit_next_maintenance_timestamp(),
            'daily',
            self::NIGHTLY_MAINTENANCE_HOOK
        );
    }

    public function run_nightly_maintenance(): void
    {
        if (wp_installing()) {
            return;
        }

        kovalenko_admin_change_audit_delete_expired_logs($this->get_log_retention_days());
    }

    public function can_view_logs(): bool
    {
        return $this->admin_service->can_view_logs();
    }

    public function is_screen_unlocked(): bool
    {
        return $this->admin_service->is_screen_unlocked();
    }

    public function get_admin_page_url(): string
    {
        return $this->admin_service->get_admin_page_url();
    }

    public function get_access_slug(): string
    {
        return $this->admin_service->get_access_slug();
    }

    public function is_password_required(): bool
    {
        return $this->admin_service->is_password_required();
    }

    public function has_saved_password(): bool
    {
        return $this->admin_service->has_saved_password();
    }

    public function get_timezone_name(): string
    {
        return $this->admin_service->get_timezone_name();
    }

    public static function is_legacy_plugin_active(): bool
    {
        return function_exists('wp_activity_logger')
            || class_exists('WPActivityLoggerPlugin', false);
    }

    /**
     * What the one-time legacy import actually did. Possible entries: slug, owner, hash, notice, timezone,
     * slug_skipped_legacy_active.
     *
     * @return list<string>
     */
    public static function get_legacy_import_summary(): array
    {
        $summary = get_option(self::OPTION_LEGACY_MIGRATED, []);

        return is_array($summary) ? array_values(array_filter($summary, 'is_string')) : [];
    }

    /**
     * Removes cron events left behind by the earlier plugin, but only once that plugin is no longer running
     * (otherwise it would lose its own schedule).
     */
    public static function clear_legacy_cron_hooks(): void
    {
        if (self::is_legacy_plugin_active()) {
            return;
        }

        foreach (self::LEGACY_CRON_HOOKS as $hook) {
            if (wp_next_scheduled($hook) !== false) {
                wp_clear_scheduled_hook($hook);
            }
        }
    }

    private function upgrade_schema(): void
    {
        kovalenko_admin_change_audit_install();
        $this->migrate_legacy_settings();
        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false);
    }

    /**
     * One-time, idempotent copy of settings from the earlier plugin. New values always win; legacy options
     * are left untouched.
     */
    private function migrate_legacy_settings(): void
    {
        if (get_option(self::OPTION_LEGACY_MIGRATED, '') !== '') {
            return;
        }

        $imported = [];

        // While the old plugin is still active it registers the same Tools submenu slug, so a copied slug would
        // collide. In that case this plugin keeps its own generated slug.
        $legacy_slug = sanitize_key((string) get_option(self::LEGACY_OPTION_ACCESS_SLUG, ''));
        if ($legacy_slug !== '' && (string) get_option(self::OPTION_ACCESS_SLUG, '') === '') {
            if (self::is_legacy_plugin_active()) {
                $imported[] = 'slug_skipped_legacy_active';
            } else {
                update_option(self::OPTION_ACCESS_SLUG, $legacy_slug, false);
                $imported[] = 'slug';
            }
        }

        $legacy_owner = (int) get_option(self::LEGACY_OPTION_OWNER_USER_ID, 0);
        $current_owner = (int) get_option(self::OPTION_OWNER_USER_ID, 0);
        if (
            $legacy_owner > 0
            && $current_owner <= 0
            && user_can($legacy_owner, self::VIEW_CAPABILITY)
        ) {
            update_option(self::OPTION_OWNER_USER_ID, $legacy_owner, false);
            $current_owner = $legacy_owner;
            $imported[] = 'owner';
        }

        // The old password was set by the legacy owner; importing it for anybody else could lock out the
        // effective owner of this plugin.
        $legacy_hash = get_option(self::LEGACY_OPTION_ACCESS_PASSWORD_HASH, '');
        if (
            is_string($legacy_hash)
            && self::is_valid_password_hash($legacy_hash)
            && $legacy_owner > 0
            && $legacy_owner === $current_owner
            && (string) get_option(self::OPTION_ACCESS_PASSWORD_HASH, '') === ''
        ) {
            update_option(self::OPTION_ACCESS_PASSWORD_HASH, $legacy_hash, false);
            $imported[] = 'hash';
        }

        if (in_array('slug', $imported, true)) {
            $legacy_notice = get_option(self::LEGACY_OPTION_SHOW_ACCESS_NOTICE, '');
            if (in_array($legacy_notice, ['yes', 'no'], true)) {
                update_option(self::OPTION_SHOW_ACCESS_NOTICE, $legacy_notice, false);
                $imported[] = 'notice';
            }
        }

        $legacy_timezone = get_option(self::LEGACY_OPTION_TIMEZONE, '');
        if (
            is_string($legacy_timezone)
            && kovalenko_admin_change_audit_is_valid_timezone($legacy_timezone)
            && (string) get_option(self::OPTION_TIMEZONE, '') === ''
        ) {
            update_option(self::OPTION_TIMEZONE, $legacy_timezone, false);
            $imported[] = 'timezone';
        }

        // Ephemeral unlock sessions of the old plugin are never migrated.
        delete_metadata('user', 0, self::LEGACY_USER_META_UNLOCKED_UNTIL, '', true);

        // Non-empty value (even an empty list) marks the migration as done.
        update_option(self::OPTION_LEGACY_MIGRATED, $imported, false);
    }

    private static function is_valid_password_hash(string $hash): bool
    {
        foreach (['$P$', '$wp$', '$2y$', '$argon2'] as $prefix) {
            if (str_starts_with($hash, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function get_log_retention_days(): int
    {
        $days = (int) apply_filters('kovalenko_admin_change_audit_retention_days', self::LOG_RETENTION_DAYS);

        return max(1, $days);
    }
}
