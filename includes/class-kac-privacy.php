<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Integrates the log with the core privacy tools (Tools > Export/Erase Personal Data) and the privacy policy guide.
 */
final class Kovalenko_Admin_Change_Audit_Privacy
{
    private const PAGE_SIZE = 500;

    public function register_hooks(): void
    {
        add_action('admin_init', [$this, 'add_policy_content']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
    }

    public function add_policy_content(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p class="privacy-policy-tutorial">' . esc_html__('Suggested text for sites that use ChangeTrail:', 'kovalenko-admin-change-audit') . '</p>'
            . '<p>' . esc_html__('When you log in to this site, or change content or settings while logged in, we keep an activity log entry with your username, the action, the date and time, and your IP address. Failed login attempts are logged with the username that was tried and the IP address. To tell apart computers that share one account, each entry also records a random device cookie (kovalenko_admin_change_audit_device, kept for two years), a short one-way hash of the login session and the browser user agent. Unless the site owner turns it off, a script in the dashboard also reads device characteristics (screen size, colour depth, timezone, language, CPU cores, memory, graphics card, touch support and platform) and stores them with a hash as a device fingerprint. This log is used to keep the site secure and to understand changes. It is stored only on this site and is deleted automatically after the retention period set by the site owner.', 'kovalenko-admin-change-audit') . '</p>';

        wp_add_privacy_policy_content('ChangeTrail – Activity & Audit Log', wp_kses_post($content));
    }

    /**
     * @param array<string, mixed> $exporters
     *
     * @return array<string, mixed>
     */
    public function register_exporter(array $exporters): array
    {
        $exporters['kovalenko-admin-change-audit'] = [
            'exporter_friendly_name' => __('ChangeTrail activity log', 'kovalenko-admin-change-audit'),
            'callback' => [$this, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     *
     * @return array<string, mixed>
     */
    public function register_eraser(array $erasers): array
    {
        $erasers['kovalenko-admin-change-audit'] = [
            'eraser_friendly_name' => __('ChangeTrail activity log', 'kovalenko-admin-change-audit'),
            'callback' => [$this, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        global $wpdb;

        $user = get_user_by('email', $email);
        if (! $user instanceof WP_User) {
            return ['data' => [], 'done' => true];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, activity, ip_address, created_at, device_id, session_hash, user_agent FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
            kovalenko_admin_change_audit_table(),
            (int) $user->ID,
            self::PAGE_SIZE,
            (max(1, $page) - 1) * self::PAGE_SIZE
        ));

        $data = [];
        foreach ((array) $rows as $row) {
            $data[] = [
                'group_id' => 'changetrail-activity-log',
                'group_label' => __('Activity log', 'kovalenko-admin-change-audit'),
                'item_id' => 'changetrail-' . (int) $row->id,
                'data' => [
                    ['name' => __('Date (UTC)', 'kovalenko-admin-change-audit'), 'value' => (string) $row->created_at],
                    ['name' => __('Activity', 'kovalenko-admin-change-audit'), 'value' => (string) $row->activity],
                    ['name' => __('IP address', 'kovalenko-admin-change-audit'), 'value' => (string) $row->ip_address],
                    ['name' => __('Device ID', 'kovalenko-admin-change-audit'), 'value' => (string) $row->device_id],
                    ['name' => __('Session', 'kovalenko-admin-change-audit'), 'value' => (string) $row->session_hash],
                    ['name' => __('User agent', 'kovalenko-admin-change-audit'), 'value' => (string) $row->user_agent],
                ],
            ];
        }

        $done = count($data) < self::PAGE_SIZE;
        if ($done) {
            foreach ($this->devices_for((int) $user->ID) as $device) {
                $item = [
                    ['name' => __('Device ID', 'kovalenko-admin-change-audit'), 'value' => (string) $device->device_id],
                    ['name' => __('Name', 'kovalenko-admin-change-audit'), 'value' => (string) $device->label],
                    ['name' => __('First seen (UTC)', 'kovalenko-admin-change-audit'), 'value' => (string) $device->first_seen],
                    ['name' => __('Last seen (UTC)', 'kovalenko-admin-change-audit'), 'value' => (string) $device->last_seen],
                    ['name' => __('Last user agent', 'kovalenko-admin-change-audit'), 'value' => (string) $device->last_user_agent],
                    ['name' => __('Last IP address', 'kovalenko-admin-change-audit'), 'value' => (string) $device->last_ip],
                    ['name' => __('Fingerprint', 'kovalenko-admin-change-audit'), 'value' => (string) $device->fingerprint],
                ];
                foreach (Kovalenko_Admin_Change_Audit_Devices::fingerprint_components($device) as $key => $value) {
                    $item[] = ['name' => $key, 'value' => $value];
                }
                $data[] = [
                    'group_id' => 'changetrail-devices',
                    'group_label' => __('Devices', 'kovalenko-admin-change-audit'),
                    'item_id' => 'changetrail-device-' . (string) $device->device_id,
                    'data' => $item,
                ];
            }
        }

        return ['data' => $data, 'done' => $done];
    }

    /**
     * @return list<object>
     */
    private function devices_for(int $user_id): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE users LIKE %s',
            Kovalenko_Admin_Change_Audit_Devices::table(),
            '%,' . $wpdb->esc_like((string) $user_id) . ',%'
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Anonymizes the person's log entries: the entries stay (the site's audit trail), but the account link,
     * username and IP address are removed.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        global $wpdb;

        unset($page);
        $user = get_user_by('email', $email);
        if (! $user instanceof WP_User) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $table = kovalenko_admin_change_audit_table();
        $anonymous = wp_privacy_anonymize_data('text');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET activity = REPLACE(activity, %s, %s), user_id = 0, user_login = %s, ip_address = %s, device_id = %s, session_hash = %s, user_agent = %s WHERE user_id = %d LIMIT %d',
            $table,
            $user->user_login,
            $anonymous,
            $anonymous,
            '',
            '',
            '',
            '',
            (int) $user->ID,
            self::PAGE_SIZE
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET object_label = %s, activity = REPLACE(activity, %s, %s) WHERE object_type = 'user' AND object_id = %d",
            $table,
            $anonymous,
            $user->user_login,
            $anonymous,
            (int) $user->ID
        ));

        $updated = is_int($updated) ? $updated : 0;

        // Remove the person from the device registry; devices nobody else used are deleted.
        $devices_removed = false;
        foreach ($this->devices_for((int) $user->ID) as $device) {
            $remaining = array_values(array_diff(Kovalenko_Admin_Change_Audit_Devices::user_ids($device), [(int) $user->ID]));
            if ($remaining === []) {
                Kovalenko_Admin_Change_Audit_Devices::forget((string) $device->device_id);
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(Kovalenko_Admin_Change_Audit_Devices::table(), ['users' => ',' . implode(',', $remaining) . ','], ['device_id' => (string) $device->device_id], ['%s'], ['%s']);
            }
            $devices_removed = true;
        }

        return [
            'items_removed' => $updated > 0 || $devices_removed,
            'items_retained' => false,
            'messages' => [],
            'done' => $updated < self::PAGE_SIZE,
        ];
    }
}
