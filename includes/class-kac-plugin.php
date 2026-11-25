<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap: wires the components together and owns install, upgrade and cron.
 */
final class Kovalenko_Admin_Change_Audit_Plugin
{
    public const VERSION = '3.0.0';
    public const SCHEMA_VERSION = '3.0.0.2';
    public const OPTION_SCHEMA_VERSION = 'kovalenko_admin_change_audit_schema_version';
    public const CRON_MAINTENANCE = 'kovalenko_admin_change_audit_nightly_maintenance';
    public const CRON_BACKFILL = 'kovalenko_admin_change_audit_backfill';

    /** Before 3.0 an unset timezone option meant this zone; upgrades keep it so times do not shift. */
    private const PRE_3_DEFAULT_TIMEZONE = 'America/Edmonton';

    private readonly Kovalenko_Admin_Change_Audit_Admin $admin;
    private readonly Kovalenko_Admin_Change_Audit_Event_Logger $logger;
    private readonly Kovalenko_Admin_Change_Audit_Privacy $privacy;
    private readonly Kovalenko_Admin_Change_Audit_Devices $devices;

    public function __construct(
        private readonly string $plugin_file
    ) {
        $this->admin = new Kovalenko_Admin_Change_Audit_Admin($plugin_file);
        $this->logger = new Kovalenko_Admin_Change_Audit_Event_Logger();
        $this->privacy = new Kovalenko_Admin_Change_Audit_Privacy();
        $this->devices = new Kovalenko_Admin_Change_Audit_Devices();
    }

    public function register_hooks(): void
    {
        register_activation_hook($this->plugin_file, [$this, 'activate']);
        register_deactivation_hook($this->plugin_file, [$this, 'deactivate']);

        add_action('init', [$this, 'maybe_upgrade'], 1);
        add_action('init', [$this, 'schedule_maintenance']);
        add_action(self::CRON_MAINTENANCE, [$this, 'run_maintenance']);
        add_action(self::CRON_BACKFILL, [$this, 'run_backfill']);

        $this->logger->register_hooks();
        $this->privacy->register_hooks();
        $this->devices->register_hooks();

        if (is_admin()) {
            $this->admin->register_hooks();
        } else {
            add_action('admin_bar_menu', [$this->admin, 'admin_bar_link'], 90);
        }
    }

    public function admin(): Kovalenko_Admin_Change_Audit_Admin
    {
        return $this->admin;
    }

    public function activate(): void
    {
        $this->upgrade();

        if (! Kovalenko_Admin_Change_Audit_Access::has_valid_owner()) {
            Kovalenko_Admin_Change_Audit_Access::set_owner(get_current_user_id());
        }

        $this->schedule_maintenance();
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_MAINTENANCE);
        wp_clear_scheduled_hook(self::CRON_BACKFILL);
    }

    public function maybe_upgrade(): void
    {
        if (get_option(self::OPTION_SCHEMA_VERSION, '') !== self::SCHEMA_VERSION) {
            $this->upgrade();
        }
    }

    private function upgrade(): void
    {
        $previous = (string) get_option(self::OPTION_SCHEMA_VERSION, '');

        kovalenko_admin_change_audit_install();
        add_option(Kovalenko_Admin_Change_Audit_Settings::OPTION, Kovalenko_Admin_Change_Audit_Settings::defaults(), '', false);

        if ($previous !== '' && version_compare($previous, '3.0.0', '<')) {
            if ((string) get_option(Kovalenko_Admin_Change_Audit_Settings::OPTION_TIMEZONE, '') === '') {
                update_option(Kovalenko_Admin_Change_Audit_Settings::OPTION_TIMEZONE, self::PRE_3_DEFAULT_TIMEZONE, false);
            }

            // Leftovers of the removed import from the earlier "WP Activity Logger" plugin and of old cron hooks.
            delete_option('kovalenko_admin_change_audit_legacy_migrated');
            wp_clear_scheduled_hook('kovalenko_admin_change_audit_nightly_update');
        }

        if ($previous !== '' && version_compare($previous, '3.0.0.2', '<')) {
            // The owner-only "Activity logs" toolbar link is now on by default; turn it on for existing sites once.
            $settings = Kovalenko_Admin_Change_Audit_Settings::all();
            $settings['admin_bar'] = true;
            Kovalenko_Admin_Change_Audit_Settings::save($settings);
        }

        // The one-time activation notice was removed; drop its leftover flag.
        delete_option('kovalenko_admin_change_audit_show_access_notice');

        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false);

        // Map older free-text rows to structured events: one batch now, the rest in the background.
        if (kovalenko_admin_change_audit_pending_backfill_count() > 0) {
            kovalenko_admin_change_audit_backfill_batch();
            $this->schedule_backfill();
        }
    }

    public function schedule_maintenance(): void
    {
        if (wp_next_scheduled(self::CRON_MAINTENANCE) === false) {
            wp_schedule_event(kovalenko_admin_change_audit_next_maintenance_timestamp(), 'daily', self::CRON_MAINTENANCE);
        }
    }

    private function schedule_backfill(): void
    {
        if (kovalenko_admin_change_audit_pending_backfill_count() > 0 && wp_next_scheduled(self::CRON_BACKFILL) === false) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CRON_BACKFILL);
        }
    }

    public function run_maintenance(): void
    {
        if (wp_installing()) {
            return;
        }

        kovalenko_admin_change_audit_delete_expired_logs(Kovalenko_Admin_Change_Audit_Settings::retention_days());
    }

    public function run_backfill(): void
    {
        for ($i = 0; $i < 10; $i++) {
            if (kovalenko_admin_change_audit_backfill_batch() < 500) {
                break;
            }
        }

        $this->schedule_backfill();
    }
}
