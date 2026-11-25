<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin screens and actions: menu, assets, notices, settings, export and delete-all.
 */
final class Kovalenko_Admin_Change_Audit_Admin
{
    public const PAGE_SLUG = 'changetrail';
    public const OPTION_LEGACY_SLUG = 'kovalenko_admin_change_audit_access_slug';
    public const PER_PAGE_OPTION = 'kovalenko_admin_change_audit_per_page';

    private const ACTION_UNLOCK = 'kovalenko_admin_change_audit_unlock';
    private const ACTION_SAVE = 'kovalenko_admin_change_audit_save_settings';
    private const ACTION_EXPORT = 'kovalenko_admin_change_audit_export';
    private const ACTION_DELETE_ALL = 'kovalenko_admin_change_audit_delete_all';
    private const ACTION_SAVE_DEVICES = 'kovalenko_admin_change_audit_save_devices';
    private const ACTION_FORGET_DEVICE = 'kovalenko_admin_change_audit_forget_device';

    private string $hook_suffix = '';

    private ?Kovalenko_Admin_Change_Audit_List_Table $list_table = null;

    public function __construct(
        private readonly string $plugin_file
    ) {}

    public function register_hooks(): void
    {
        add_action('admin_init', [Kovalenko_Admin_Change_Audit_Access::class, 'ensure_owner']);
        // An unknown ?page= is rejected in wp-admin/menu.php before admin_init, so hook into that rejection.
        add_action('admin_page_access_denied', [$this, 'redirect_old_page_slug']);
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_bar_menu', [$this, 'admin_bar_link'], 90);
        add_action('admin_notices', [$this, 'render_global_notices']);
        add_action('admin_post_' . self::ACTION_UNLOCK, [$this, 'handle_unlock']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handle_save_settings']);
        add_action('admin_post_' . self::ACTION_EXPORT, [$this, 'handle_export']);
        add_action('admin_post_' . self::ACTION_DELETE_ALL, [$this, 'handle_delete_all']);
        add_action('admin_post_' . self::ACTION_SAVE_DEVICES, [$this, 'handle_save_devices']);
        add_action('admin_post_' . self::ACTION_FORGET_DEVICE, [$this, 'handle_forget_device']);
        add_filter('set-screen-option', [$this, 'save_screen_option'], 10, 3);
        add_filter('set_screen_option_' . self::PER_PAGE_OPTION, [$this, 'save_screen_option'], 10, 3);
        add_filter('plugin_action_links_' . plugin_basename($this->plugin_file), [$this, 'plugin_action_links']);
    }

    public function page_url(string $tab = ''): string
    {
        $args = ['page' => self::PAGE_SLUG];
        if ($tab !== '') {
            $args['tab'] = $tab;
        }

        return add_query_arg($args, admin_url('tools.php'));
    }

    public function current_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        return in_array($tab, ['settings', 'devices'], true) ? $tab : 'log';
    }

    /**
     * Bookmarks of the old private address (random slug) keep working.
     */
    public function redirect_old_page_slug(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect of an old admin URL.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === '' || $page === self::PAGE_SLUG) {
            return;
        }

        $old_slug = (string) get_option(self::OPTION_LEGACY_SLUG, '');
        if ($page !== $old_slug && preg_match('/^(kovalenko-activity-logs|wp-activity-logs)-[a-z0-9]{6,}$/', $page) !== 1) {
            return;
        }

        wp_safe_redirect($this->page_url());
        exit;
    }

    public function register_page(): void
    {
        if (! Kovalenko_Admin_Change_Audit_Access::is_owner()) {
            return;
        }

        $hook = add_management_page(
            __('ChangeTrail – Activity & Audit Log', 'kovalenko-admin-change-audit'),
            __('ChangeTrail', 'kovalenko-admin-change-audit'),
            Kovalenko_Admin_Change_Audit_Access::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );

        if (is_string($hook)) {
            $this->hook_suffix = $hook;
            add_action('load-' . $hook, [$this, 'on_load_page']);
        }
    }

    public function on_load_page(): void
    {
        if (! Kovalenko_Admin_Change_Audit_Access::can_view() || $this->current_tab() !== 'log') {
            return;
        }

        // Like core list screens: drop the list table's nonce/referer fields from filter URLs.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only removes query args; no action is taken.
        if (! empty($_GET['_wp_http_referer']) && isset($_SERVER['REQUEST_URI'])) {
            wp_safe_redirect(remove_query_arg(['_wp_http_referer', '_wpnonce', 'filter_action'], esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))));
            exit;
        }

        add_screen_option('per_page', [
            'default' => 20,
            'option' => self::PER_PAGE_OPTION,
        ]);

        require_once __DIR__ . '/class-kac-list-table.php';
        $this->list_table = new Kovalenko_Admin_Change_Audit_List_Table();
    }

    /**
     * @param mixed  $status
     * @param string $option
     * @param mixed  $value
     *
     * @return mixed
     */
    public function save_screen_option($status, $option, $value)
    {
        if ($option === self::PER_PAGE_OPTION) {
            return max(1, min(500, (int) $value));
        }

        return $status;
    }

    public function list_table(): ?Kovalenko_Admin_Change_Audit_List_Table
    {
        return $this->list_table;
    }

    public function render_page(): void
    {
        kovalenko_admin_change_audit_render_page($this);
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if ($this->hook_suffix === '' || $hook_suffix !== $this->hook_suffix) {
            return;
        }

        $base = plugin_dir_path($this->plugin_file);
        wp_enqueue_style('revisions');
        wp_enqueue_style(
            'kovalenko-admin-change-audit',
            plugins_url('assets/admin.css', $this->plugin_file),
            ['revisions'],
            (string) filemtime($base . 'assets/admin.css')
        );
        wp_enqueue_script(
            'kovalenko-admin-change-audit',
            plugins_url('assets/admin.js', $this->plugin_file),
            [],
            (string) filemtime($base . 'assets/admin.js'),
            ['in_footer' => true]
        );
    }

    /**
     * Owner-only "Activity logs" toolbar link, in wp-admin and on the front end.
     */
    public function admin_bar_link(WP_Admin_Bar $admin_bar): void
    {
        if (! is_admin_bar_showing() || ! Kovalenko_Admin_Change_Audit_Settings::get('admin_bar') || ! Kovalenko_Admin_Change_Audit_Access::is_owner()) {
            return;
        }

        $admin_bar->add_node([
            'id' => 'kovalenko-admin-change-audit',
            'title' => '<span class="ab-icon dashicons dashicons-list-view" aria-hidden="true"></span><span class="ab-label">' . esc_html__('Activity logs', 'kovalenko-admin-change-audit') . '</span>',
            'href' => $this->page_url(),
            'meta' => ['title' => __('Activity logs', 'kovalenko-admin-change-audit')],
        ]);
    }

    /**
     * @param array<string, string> $links
     *
     * @return array<string, string>
     */
    public function plugin_action_links(array $links): array
    {
        if (! Kovalenko_Admin_Change_Audit_Access::is_owner()) {
            return $links;
        }

        return array_merge([
            'settings' => '<a href="' . esc_url($this->page_url('settings')) . '">' . esc_html__('Settings', 'kovalenko-admin-change-audit') . '</a>',
        ], $links);
    }

    public function render_global_notices(): void
    {
        $transferred = get_transient('kovalenko_admin_change_audit_transferred_' . get_current_user_id());
        if (is_string($transferred) && $transferred !== '') {
            delete_transient('kovalenko_admin_change_audit_transferred_' . get_current_user_id());
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %s: username of the new owner */
                __('The ChangeTrail activity log now belongs to %s. Only that account can open it.', 'kovalenko-admin-change-audit'),
                $transferred
            )) . '</p></div>';
        }
    }

    /* ---------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------- */

    public function unlock_action(): string
    {
        return self::ACTION_UNLOCK;
    }

    public function save_action(): string
    {
        return self::ACTION_SAVE;
    }

    public function delete_all_action(): string
    {
        return self::ACTION_DELETE_ALL;
    }

    public function save_devices_action(): string
    {
        return self::ACTION_SAVE_DEVICES;
    }

    public function forget_device_url(string $device_id): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => self::ACTION_FORGET_DEVICE, 'device' => $device_id], admin_url('admin-post.php')),
            self::ACTION_FORGET_DEVICE . '_' . $device_id
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function export_url(array $filters): string
    {
        $args = array_filter([
            'action' => self::ACTION_EXPORT,
            'group' => $filters['group'] ?? '',
            'event' => $filters['event'] ?? '',
            'user' => isset($filters['user_id']) && $filters['user_id'] >= 0 ? (string) $filters['user_id'] : '',
            'device' => $filters['device'] ?? '',
            'object_type' => $filters['object_type'] ?? '',
            'object_id' => ! empty($filters['object_id']) ? (string) $filters['object_id'] : '',
            'start_date' => $filters['start_date'] ?? '',
            'end_date' => $filters['end_date'] ?? '',
            's' => $filters['search'] ?? '',
        ], static fn ($value): bool => $value !== '' && $value !== null);

        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), self::ACTION_EXPORT);
    }

    private function require_owner(bool $require_unlocked = true): void
    {
        if (! Kovalenko_Admin_Change_Audit_Access::is_owner() || ($require_unlocked && ! Kovalenko_Admin_Change_Audit_Access::is_unlocked())) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'kovalenko-admin-change-audit'), '', ['response' => 403]);
        }
    }

    public function handle_unlock(): void
    {
        $this->require_owner(false);
        check_admin_referer(self::ACTION_UNLOCK);

        if (Kovalenko_Admin_Change_Audit_Access::lockout_remaining() > 0) {
            wp_safe_redirect(add_query_arg('locked', '1', $this->page_url()));
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords are compared raw; sanitizing would change them.
        $password = isset($_POST['kac_password']) ? (string) wp_unslash($_POST['kac_password']) : '';

        if (Kovalenko_Admin_Change_Audit_Access::password_matches($password)) {
            Kovalenko_Admin_Change_Audit_Access::clear_attempts();
            Kovalenko_Admin_Change_Audit_Access::unlock();
            wp_safe_redirect($this->page_url());
            exit;
        }

        Kovalenko_Admin_Change_Audit_Access::register_failed_attempt();
        kovalenko_admin_change_audit_record_event('changetrail_unlock_failed', [
            'object_type' => 'changetrail',
            'object_label' => 'ChangeTrail',
        ]);

        wp_safe_redirect(add_query_arg('access_error', '1', $this->page_url()));
        exit;
    }

    public function handle_save_settings(): void
    {
        $this->require_owner();
        check_admin_referer(self::ACTION_SAVE);

        $old = Kovalenko_Admin_Change_Audit_Settings::all();
        $old_timezone = Kovalenko_Admin_Change_Audit_Settings::stored_timezone();

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is sanitized by Settings::sanitize() or validated against a whitelist below.
        $input = [
            'retention_days' => isset($_POST['retention_days']) ? absint($_POST['retention_days']) : 30,
            'admin_bar' => ! empty($_POST['admin_bar']),
            'groups' => isset($_POST['groups']) ? array_map('sanitize_key', (array) wp_unslash($_POST['groups'])) : [],
            'excluded_post_types' => isset($_POST['excluded_post_types']) ? array_map('sanitize_key', (array) wp_unslash($_POST['excluded_post_types'])) : [],
            'excluded_meta_keys' => isset($_POST['excluded_meta_keys']) ? sanitize_textarea_field(wp_unslash($_POST['excluded_meta_keys'])) : '',
            'unlock_hours' => isset($_POST['unlock_hours']) ? absint($_POST['unlock_hours']) : 12,
            'keep_data' => ! empty($_POST['keep_data']),
            'fingerprinting' => ! empty($_POST['fingerprinting']),
        ];
        $timezone = isset($_POST['timezone']) ? sanitize_text_field(wp_unslash($_POST['timezone'])) : '';
        $new_owner = isset($_POST['owner']) ? absint($_POST['owner']) : 0;
        $new_password = isset($_POST['kac_new_password']) ? trim((string) wp_unslash($_POST['kac_new_password'])) : '';
        $confirm_password = isset($_POST['kac_confirm_password']) ? trim((string) wp_unslash($_POST['kac_confirm_password'])) : '';
        $remove_password = ! empty($_POST['kac_remove_password']);
        // phpcs:enable

        $new = Kovalenko_Admin_Change_Audit_Settings::sanitize($input);
        Kovalenko_Admin_Change_Audit_Settings::save($new);

        if ($timezone === '' || Kovalenko_Admin_Change_Audit_Settings::is_valid_timezone($timezone)) {
            update_option(Kovalenko_Admin_Change_Audit_Settings::OPTION_TIMEZONE, $timezone, false);
        } else {
            $timezone = $old_timezone;
        }

        $changes = $this->describe_settings_changes($old, $new, $old_timezone, $timezone);
        if ($changes !== []) {
            kovalenko_admin_change_audit_record_event('changetrail_settings_updated', [
                'object_type' => 'changetrail',
                'object_label' => 'ChangeTrail',
                'details' => $changes,
            ]);
        }

        if ($old_timezone !== $timezone) {
            wp_clear_scheduled_hook(Kovalenko_Admin_Change_Audit_Plugin::CRON_MAINTENANCE);
            kovalenko_admin_change_audit()->schedule_maintenance();
        }

        $error = '';
        if (! Kovalenko_Admin_Change_Audit_Access::is_password_from_constant()) {
            if ($remove_password && Kovalenko_Admin_Change_Audit_Access::saved_hash() !== '') {
                Kovalenko_Admin_Change_Audit_Access::remove_password();
                $this->log_password_change(__('Removed', 'kovalenko-admin-change-audit'));
            } elseif ($new_password !== '' || $confirm_password !== '') {
                if ($new_password !== $confirm_password) {
                    $error = 'password_mismatch';
                } elseif (strlen($new_password) < 8) {
                    $error = 'password_short';
                } else {
                    $had_password = Kovalenko_Admin_Change_Audit_Access::saved_hash() !== '';
                    Kovalenko_Admin_Change_Audit_Access::set_password($new_password);
                    Kovalenko_Admin_Change_Audit_Access::unlock();
                    $this->log_password_change($had_password ? __('Changed', 'kovalenko-admin-change-audit') : __('Added', 'kovalenko-admin-change-audit'));
                }
            }
        }

        $current_user_id = get_current_user_id();
        if ($new_owner > 0 && $new_owner !== Kovalenko_Admin_Change_Audit_Access::owner_id() && user_can($new_owner, Kovalenko_Admin_Change_Audit_Access::CAPABILITY)) {
            $owner = get_userdata($new_owner);
            if ($owner instanceof WP_User) {
                Kovalenko_Admin_Change_Audit_Access::set_owner($new_owner);
                Kovalenko_Admin_Change_Audit_Access::lock($current_user_id);
                kovalenko_admin_change_audit_record_event('changetrail_owner_changed', [
                    'object_type' => 'user',
                    'object_id' => $new_owner,
                    'object_label' => $owner->user_login,
                ]);

                if ($new_owner !== $current_user_id) {
                    set_transient('kovalenko_admin_change_audit_transferred_' . $current_user_id, $owner->user_login, MINUTE_IN_SECONDS * 5);
                    wp_safe_redirect(admin_url());
                    exit;
                }
            }
        }

        $args = $error !== '' ? ['settings_error' => $error] : ['settings-updated' => '1'];
        wp_safe_redirect(add_query_arg($args, $this->page_url('settings')));
        exit;
    }

    private function log_password_change(string $what): void
    {
        kovalenko_admin_change_audit_record_event('changetrail_password_changed', [
            'object_type' => 'changetrail',
            'object_label' => 'ChangeTrail',
            'details' => [[
                'label' => __('Access password', 'kovalenko-admin-change-audit'),
                'before' => '',
                'after' => $what,
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     *
     * @return list<array{label: string, before: string, after: string}>
     */
    private function describe_settings_changes(array $old, array $new, string $old_timezone, string $new_timezone): array
    {
        $yes_no = static fn ($value): string => $value ? __('Yes', 'kovalenko-admin-change-audit') : __('No', 'kovalenko-admin-change-audit');
        $list = static fn ($value): string => implode(', ', (array) $value);
        $retention = static fn ($value): string => (int) $value === 0
            ? __('Forever', 'kovalenko-admin-change-audit')
            /* translators: %d: number of days */
            : sprintf(_n('%d day', '%d days', (int) $value, 'kovalenko-admin-change-audit'), (int) $value);

        $fields = [
            'retention_days' => [__('Keep log entries for', 'kovalenko-admin-change-audit'), $retention],
            'groups' => [__('What to log', 'kovalenko-admin-change-audit'), $list],
            'excluded_post_types' => [__('Ignored post types', 'kovalenko-admin-change-audit'), $list],
            'excluded_meta_keys' => [__('Ignored custom fields', 'kovalenko-admin-change-audit'), $list],
            'unlock_hours' => [__('Stay unlocked for (hours)', 'kovalenko-admin-change-audit'), static fn ($value): string => (string) $value],
            'admin_bar' => [__('Toolbar link', 'kovalenko-admin-change-audit'), $yes_no],
            'keep_data' => [__('Keep data on uninstall', 'kovalenko-admin-change-audit'), $yes_no],
            'fingerprinting' => [__('Device fingerprint', 'kovalenko-admin-change-audit'), $yes_no],
        ];

        $changes = [];
        foreach ($fields as $key => [$label, $format]) {
            $before = $format($old[$key] ?? '');
            $after = $format($new[$key] ?? '');
            if ($before !== $after) {
                $changes[] = ['label' => $label, 'before' => $before, 'after' => $after];
            }
        }

        if ($old_timezone !== $new_timezone) {
            $site = __('Site timezone', 'kovalenko-admin-change-audit');
            $changes[] = [
                'label' => __('Timezone', 'kovalenko-admin-change-audit'),
                'before' => $old_timezone !== '' ? $old_timezone : $site,
                'after' => $new_timezone !== '' ? $new_timezone : $site,
            ];
        }

        return $changes;
    }

    public function handle_delete_all(): void
    {
        $this->require_owner();
        check_admin_referer(self::ACTION_DELETE_ALL);

        kovalenko_admin_change_audit_delete_all_logs();
        kovalenko_admin_change_audit_record_event('changetrail_logs_deleted', [
            'object_type' => 'changetrail',
            'object_label' => 'ChangeTrail',
        ]);

        wp_safe_redirect(add_query_arg('logs-deleted', '1', $this->page_url()));
        exit;
    }

    public function handle_save_devices(): void
    {
        $this->require_owner();
        check_admin_referer(self::ACTION_SAVE_DEVICES);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys are validated and values sanitized below.
        $labels = isset($_POST['labels']) && is_array($_POST['labels']) ? wp_unslash($_POST['labels']) : [];
        foreach ($labels as $device_id => $label) {
            $device_id = sanitize_key((string) $device_id);
            $device = Kovalenko_Admin_Change_Audit_Devices::get($device_id);
            if ($device === null) {
                continue;
            }

            $label = substr(sanitize_text_field((string) $label), 0, 100);
            if ($label === (string) $device->label) {
                continue;
            }

            Kovalenko_Admin_Change_Audit_Devices::rename($device_id, $label);
            kovalenko_admin_change_audit_record_event('changetrail_device_updated', [
                'object_type' => 'device',
                'object_label' => Kovalenko_Admin_Change_Audit_Devices::code($device_id),
                'details' => [['label' => __('Name', 'kovalenko-admin-change-audit'), 'before' => (string) $device->label, 'after' => $label]],
            ]);
        }

        wp_safe_redirect(add_query_arg('devices-updated', '1', $this->page_url('devices')));
        exit;
    }

    public function handle_forget_device(): void
    {
        $this->require_owner();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() right below.
        $device_id = isset($_GET['device']) ? sanitize_key(wp_unslash($_GET['device'])) : '';
        check_admin_referer(self::ACTION_FORGET_DEVICE . '_' . $device_id);

        $device = Kovalenko_Admin_Change_Audit_Devices::get($device_id);
        if ($device !== null) {
            Kovalenko_Admin_Change_Audit_Devices::forget($device_id);
            kovalenko_admin_change_audit_record_event('changetrail_device_updated', [
                'object_type' => 'device',
                'object_label' => Kovalenko_Admin_Change_Audit_Devices::code($device_id),
                'details' => [['label' => __('Forgotten device', 'kovalenko-admin-change-audit'), 'before' => Kovalenko_Admin_Change_Audit_Devices::label($device), 'after' => '']],
            ]);
        }

        wp_safe_redirect(add_query_arg('device-forgotten', '1', $this->page_url('devices')));
        exit;
    }

    public function handle_export(): void
    {
        $this->require_owner();
        check_admin_referer(self::ACTION_EXPORT);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() above.
        $filters = kovalenko_admin_change_audit_normalize_filters(wp_unslash($_GET));
        $filters['per_page'] = 1000;
        $filters['orderby'] = 'date';
        $filters['order'] = 'desc';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="changetrail-' . gmdate('Y-m-d-His') . '.csv"');

        $events = Kovalenko_Admin_Change_Audit_Events::all();

        // UTF-8 BOM so spreadsheet apps detect the encoding.
        echo "\xEF\xBB\xBF";
        $this->csv_line([
            __('ID', 'kovalenko-admin-change-audit'),
            /* translators: %s: timezone name */
            sprintf(__('Date (%s)', 'kovalenko-admin-change-audit'), Kovalenko_Admin_Change_Audit_Settings::timezone_name()),
            __('Date (UTC)', 'kovalenko-admin-change-audit'),
            __('User', 'kovalenko-admin-change-audit'),
            __('Type', 'kovalenko-admin-change-audit'),
            __('Activity', 'kovalenko-admin-change-audit'),
            __('Object type', 'kovalenko-admin-change-audit'),
            __('Object ID', 'kovalenko-admin-change-audit'),
            __('IP address', 'kovalenko-admin-change-audit'),
            __('Device', 'kovalenko-admin-change-audit'),
            __('Device ID', 'kovalenko-admin-change-audit'),
            __('Browser', 'kovalenko-admin-change-audit'),
            __('Operating system', 'kovalenko-admin-change-audit'),
            __('Session', 'kovalenko-admin-change-audit'),
            __('User agent', 'kovalenko-admin-change-audit'),
            __('Changes', 'kovalenko-admin-change-audit'),
        ]);

        for ($page = 1; $page <= 1000; $page++) {
            $filters['paged'] = $page;
            $rows = kovalenko_admin_change_audit_query_logs($filters, false)['items'];

            $devices = Kovalenko_Admin_Change_Audit_Devices::get_many(array_map(static fn (object $row): string => (string) $row->device_id, $rows));

            foreach ($rows as $row) {
                $device_id = (string) $row->device_id;
                $agent = Kovalenko_Admin_Change_Audit_Devices::parse_user_agent((string) $row->user_agent);
                $changes = array_map(
                    static fn (array $detail): string => sprintf('%s: %s → %s', $detail['label'], $detail['before'], $detail['after']),
                    kovalenko_admin_change_audit_decode_details(is_string($row->details) ? $row->details : null)
                );

                $this->csv_line([
                    (string) $row->id,
                    kovalenko_admin_change_audit_format_timestamp((string) $row->created_at),
                    (string) $row->created_at,
                    (string) $row->user_login !== '' ? (string) $row->user_login : ((int) $row->user_id === 0 ? __('System', 'kovalenko-admin-change-audit') : '#' . (int) $row->user_id),
                    $events[(string) $row->event]['name'] ?? (string) $row->event,
                    (string) $row->activity,
                    (string) $row->object_type,
                    (int) $row->object_id > 0 ? (string) $row->object_id : '',
                    (string) $row->ip_address,
                    Kovalenko_Admin_Change_Audit_Devices::label($devices[$device_id] ?? null, $device_id),
                    $device_id,
                    $agent['browser'],
                    $agent['os'],
                    (string) $row->session_hash,
                    (string) $row->user_agent,
                    implode("\n", $changes),
                ]);
            }

            if (count($rows) < $filters['per_page']) {
                break;
            }
        }

        exit;
    }

    /**
     * @param list<string> $fields
     */
    private function csv_line(array $fields): void
    {
        $cells = array_map(
            static function (string $value): string {
                // Neutralize spreadsheet formulas.
                if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $value = "'" . $value;
                }

                return '"' . str_replace('"', '""', $value) . '"';
            },
            $fields
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, not HTML; every cell is quoted above.
        echo implode(',', $cells) . "\r\n";
    }
}
