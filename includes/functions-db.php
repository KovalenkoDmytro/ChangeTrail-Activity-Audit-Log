<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/*
 * Data access for the activity log table ({prefix}activity_logs). The table name is kept from earlier
 * versions so existing logs stay in place.
 *
 * Every query in this file runs against the plugin's own table, which has no core API; reads are live
 * audit data and are intentionally not cached. All dynamic parts use $wpdb->prepare() placeholders.
 */

const KOVALENKO_ADMIN_CHANGE_AUDIT_MAX_VALUE_BYTES = 2048;

function kovalenko_admin_change_audit_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'activity_logs';
}

function kovalenko_admin_change_audit_install(): void
{
    global $wpdb;

    $table_name = kovalenko_admin_change_audit_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        user_login varchar(60) NOT NULL DEFAULT '',
        event varchar(64) NOT NULL DEFAULT '',
        object_type varchar(32) NOT NULL DEFAULT '',
        object_id bigint(20) unsigned NOT NULL DEFAULT 0,
        object_label varchar(255) NOT NULL DEFAULT '',
        activity text NOT NULL,
        details longtext NULL,
        ip_address varchar(45) NOT NULL,
        session_hash varchar(16) NOT NULL DEFAULT '',
        device_id varchar(32) NOT NULL DEFAULT '',
        user_agent varchar(255) NOT NULL DEFAULT '',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY created_at (created_at),
        KEY event (event),
        KEY object (object_type,object_id),
        KEY device_id (device_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    Kovalenko_Admin_Change_Audit_Devices::install_table();
}

function kovalenko_admin_change_audit_client_ip(): string
{
    $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
    $ip_address = (string) apply_filters('kovalenko_admin_change_audit_ip_address', $ip_address);

    return filter_var($ip_address, FILTER_VALIDATE_IP) !== false ? $ip_address : '';
}

/**
 * Cuts a value to the maximum stored size (2 KB) on a UTF-8 boundary.
 */
function kovalenko_admin_change_audit_truncate(string $value, int $max_bytes = KOVALENKO_ADMIN_CHANGE_AUDIT_MAX_VALUE_BYTES): string
{
    $value = wp_check_invalid_utf8($value);
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

    if (strlen($value) <= $max_bytes) {
        return $value;
    }

    $cut = function_exists('mb_strcut') ? mb_strcut($value, 0, $max_bytes, 'UTF-8') : substr($value, 0, $max_bytes);

    return rtrim($cut) . ' […]';
}

/**
 * Writes one event row.
 *
 * @param array{object_type?: string, object_id?: int, object_label?: string, details?: list<array{label: string, before: string, after: string}>, user_id?: int|null, user_login?: string, device_id?: string, summary_second?: string} $args
 */
function kovalenko_admin_change_audit_record_event(string $event, array $args = []): void
{
    global $wpdb;

    if (! Kovalenko_Admin_Change_Audit_Events::exists($event)) {
        return;
    }

    $user_id = array_key_exists('user_id', $args) && $args['user_id'] !== null ? (int) $args['user_id'] : get_current_user_id();
    $user_id = max(0, $user_id);
    $user_login = (string) ($args['user_login'] ?? '');
    if ($user_login === '' && $user_id > 0) {
        $user = get_userdata($user_id);
        $user_login = $user instanceof WP_User ? $user->user_login : '';
    }

    $object_label = kovalenko_admin_change_audit_truncate((string) ($args['object_label'] ?? ''), 250);
    $details = kovalenko_admin_change_audit_encode_details($args['details'] ?? []);

    $ip_address = kovalenko_admin_change_audit_client_ip();
    $device_id = (string) ($args['device_id'] ?? Kovalenko_Admin_Change_Audit_Devices::current_id($user_id > 0));
    $is_browser = $device_id !== '';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writing to the plugin's own log table.
    $wpdb->insert(
        kovalenko_admin_change_audit_table(),
        [
            'user_id' => $user_id,
            'user_login' => substr($user_login, 0, 60),
            'event' => $event,
            'object_type' => substr((string) ($args['object_type'] ?? ''), 0, 32),
            'object_id' => max(0, (int) ($args['object_id'] ?? 0)),
            'object_label' => $object_label,
            'activity' => Kovalenko_Admin_Change_Audit_Events::summary($event, $object_label, (string) ($args['summary_second'] ?? '')),
            'details' => $details,
            'ip_address' => $ip_address,
            'session_hash' => $is_browser ? Kovalenko_Admin_Change_Audit_Devices::session_hash() : '',
            'device_id' => $device_id,
            'user_agent' => $is_browser || $user_id === 0 ? Kovalenko_Admin_Change_Audit_Devices::user_agent() : '',
            'created_at' => current_time('mysql', true),
        ],
        ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );

    // Keep the device registry current; this also logs "New device" the first time a user is seen on it.
    if ($user_id > 0 && $device_id !== '' && ! str_starts_with($event, 'device_')) {
        Kovalenko_Admin_Change_Audit_Devices::touch($user_id, $device_id);
    }
}

/**
 * @param mixed $details
 */
function kovalenko_admin_change_audit_encode_details($details): string
{
    $normalized = kovalenko_admin_change_audit_normalize_details(is_array($details) ? $details : []);
    if ($normalized === []) {
        return '';
    }

    $encoded = wp_json_encode($normalized);

    return is_string($encoded) ? $encoded : '';
}

/**
 * @return list<array{label: string, before: string, after: string}>
 */
function kovalenko_admin_change_audit_decode_details(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? kovalenko_admin_change_audit_normalize_details($decoded) : [];
}

/**
 * @param array<mixed> $details
 *
 * @return list<array{label: string, before: string, after: string}>
 */
function kovalenko_admin_change_audit_normalize_details(array $details): array
{
    $normalized = [];

    foreach ($details as $detail) {
        if (! is_array($detail)) {
            continue;
        }

        $label = trim((string) ($detail['label'] ?? ''));
        if ($label === '') {
            continue;
        }

        $normalized[] = [
            'label' => kovalenko_admin_change_audit_truncate($label, 200),
            'before' => kovalenko_admin_change_audit_truncate((string) ($detail['before'] ?? '')),
            'after' => kovalenko_admin_change_audit_truncate((string) ($detail['after'] ?? '')),
        ];
    }

    return $normalized;
}

/**
 * Normalizes list filters from a request array (already unslashed).
 *
 * @param array<string, mixed> $source
 *
 * @return array{group: string, event: string, user_id: int, device: string, object_type: string, object_id: int, start_date: string, end_date: string, search: string, orderby: string, order: string, paged: int, per_page: int}
 */
function kovalenko_admin_change_audit_normalize_filters(array $source): array
{
    $group = sanitize_key((string) ($source['group'] ?? ''));
    if (! isset(Kovalenko_Admin_Change_Audit_Events::groups()[$group])) {
        $group = '';
    }

    $event = sanitize_key((string) ($source['event'] ?? ''));
    if (! Kovalenko_Admin_Change_Audit_Events::exists($event) && $event !== Kovalenko_Admin_Change_Audit_Events::LEGACY) {
        $event = '';
    }

    $date = static function (string $value): string {
        $value = sanitize_text_field($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    };

    $orderby = sanitize_key((string) ($source['orderby'] ?? 'date'));
    $order = strtolower(sanitize_key((string) ($source['order'] ?? 'desc')));

    return [
        'group' => $group,
        'event' => $event,
        'user_id' => isset($source['user']) && $source['user'] !== '' ? absint($source['user']) : -1,
        'device' => Kovalenko_Admin_Change_Audit_Devices::is_valid_id(sanitize_key((string) ($source['device'] ?? ''))) ? sanitize_key((string) $source['device']) : '',
        'object_type' => sanitize_key((string) ($source['object_type'] ?? '')),
        'object_id' => absint($source['object_id'] ?? 0),
        'start_date' => $date((string) ($source['start_date'] ?? '')),
        'end_date' => $date((string) ($source['end_date'] ?? '')),
        'search' => sanitize_text_field((string) ($source['s'] ?? '')),
        'orderby' => in_array($orderby, ['date', 'user'], true) ? $orderby : 'date',
        'order' => $order === 'asc' ? 'asc' : 'desc',
        'paged' => max(1, absint($source['paged'] ?? 1)),
        'per_page' => max(1, min(500, absint($source['per_page'] ?? 20))),
    ];
}

/**
 * Builds a prepared WHERE clause for the given filters.
 *
 * @param array<string, mixed> $filters
 */
function kovalenko_admin_change_audit_where_sql(array $filters): string
{
    global $wpdb;

    $clauses = ['1=1'];

    if (($filters['event'] ?? '') !== '') {
        $clauses[] = $wpdb->prepare('event = %s', $filters['event']);
    } elseif (($filters['group'] ?? '') !== '') {
        $events = Kovalenko_Admin_Change_Audit_Events::in_group((string) $filters['group']);
        $clauses[] = $wpdb->prepare('event IN (' . implode(',', array_fill(0, count($events), '%s')) . ')', ...$events);
    }

    if (($filters['user_id'] ?? -1) >= 0) {
        $clauses[] = $wpdb->prepare('user_id = %d', $filters['user_id']);
    }

    if (($filters['device'] ?? '') !== '') {
        $clauses[] = $wpdb->prepare('device_id = %s', $filters['device']);
    }

    if (($filters['object_type'] ?? '') !== '') {
        $clauses[] = $wpdb->prepare('object_type = %s AND object_id = %d', $filters['object_type'], $filters['object_id'] ?? 0);
    }

    $start = kovalenko_admin_change_audit_utc_boundary((string) ($filters['start_date'] ?? ''), false);
    if ($start !== null) {
        $clauses[] = $wpdb->prepare('created_at >= %s', $start);
    }

    $end = kovalenko_admin_change_audit_utc_boundary((string) ($filters['end_date'] ?? ''), true);
    if ($end !== null) {
        $clauses[] = $wpdb->prepare('created_at <= %s', $end);
    }

    if (($filters['search'] ?? '') !== '') {
        $like = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
        $clauses[] = $wpdb->prepare('(activity LIKE %s OR object_label LIKE %s OR user_login LIKE %s OR ip_address LIKE %s OR user_agent LIKE %s)', $like, $like, $like, $like, $like);
    }

    return implode(' AND ', $clauses);
}

/**
 * @param array<string, mixed> $filters
 *
 * @return array{items: list<object>, total: int}
 */
function kovalenko_admin_change_audit_query_logs(array $filters, bool $with_total = true): array
{
    global $wpdb;

    $table = kovalenko_admin_change_audit_table();
    $where = kovalenko_admin_change_audit_where_sql($filters);
    $direction = ($filters['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $order_sql = ($filters['orderby'] ?? 'date') === 'user'
        ? "user_login {$direction}, id {$direction}"
        : "created_at {$direction}, id {$direction}";
    $per_page = (int) ($filters['per_page'] ?? 20);
    $offset = (max(1, (int) ($filters['paged'] ?? 1)) - 1) * $per_page;

    // $where is assembled only from $wpdb->prepare() fragments above; $order_sql is a fixed whitelist.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d", $table, $per_page, $offset));

    $total = 0;
    if ($with_total) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", $table));
    }

    return [
        'items' => is_array($items) ? $items : [],
        'total' => $total,
    ];
}

/**
 * Number of rows per event, used for the view counts.
 *
 * @return array<string, int>
 */
function kovalenko_admin_change_audit_event_counts(): array
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results($wpdb->prepare('SELECT event, COUNT(*) AS total FROM %i GROUP BY event', kovalenko_admin_change_audit_table()));

    $counts = [];
    foreach ((array) $rows as $row) {
        $counts[(string) $row->event] = (int) $row->total;
    }

    return $counts;
}

/**
 * Users that appear in the log, for the user filter.
 *
 * @return array<int, string>
 */
function kovalenko_admin_change_audit_logged_users(): array
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results($wpdb->prepare('SELECT user_id, MAX(user_login) AS user_login FROM %i GROUP BY user_id ORDER BY user_login LIMIT 300', kovalenko_admin_change_audit_table()));

    $users = [];
    foreach ((array) $rows as $row) {
        $user_id = (int) $row->user_id;
        $users[$user_id] = $user_id === 0
            ? __('System / visitors', 'kovalenko-admin-change-audit')
            : ((string) $row->user_login !== '' ? (string) $row->user_login : sprintf('#%d', $user_id));
    }

    return $users;
}

function kovalenko_admin_change_audit_delete_expired_logs(int $retention_days): void
{
    global $wpdb;

    if ($retention_days <= 0) {
        return;
    }

    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));

    // Delete in chunks so a large backlog never locks the table for long.
    for ($i = 0; $i < 50; $i++) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s LIMIT 5000', kovalenko_admin_change_audit_table(), $cutoff));
        if (! is_int($deleted) || $deleted < 5000) {
            break;
        }
    }
}

function kovalenko_admin_change_audit_delete_all_logs(): void
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', kovalenko_admin_change_audit_table()));
}

function kovalenko_admin_change_audit_pending_backfill_count(): int
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE event = ''", kovalenko_admin_change_audit_table()));
}

/**
 * Maps a batch of pre-3.0 rows (free text only) to structured events. Returns the number of rows handled.
 */
function kovalenko_admin_change_audit_backfill_batch(int $limit = 500): int
{
    global $wpdb;

    $table = kovalenko_admin_change_audit_table();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, user_id, user_login, activity FROM %i WHERE event = '' ORDER BY id ASC LIMIT %d", $table, $limit));

    if (! is_array($rows) || $rows === []) {
        return 0;
    }

    foreach ($rows as $row) {
        $parsed = kovalenko_admin_change_audit_parse_legacy_activity((string) $row->activity);
        $user_login = (string) $row->user_login;
        if ($user_login === '') {
            $user = (int) $row->user_id > 0 ? get_userdata((int) $row->user_id) : false;
            if ($user instanceof WP_User) {
                $user_login = $user->user_login;
            } elseif (preg_match("/^User '([^']+)'/", (string) $row->activity, $matches) === 1) {
                $user_login = $matches[1];
            }
        }

        if ($parsed['object_type'] === 'user' && $parsed['object_id'] === 0) {
            $parsed['object_id'] = (int) $row->user_id;
            $parsed['object_label'] = $user_login;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            [
                'event' => $parsed['event'],
                'object_type' => $parsed['object_type'],
                'object_id' => $parsed['object_id'],
                'object_label' => substr($parsed['object_label'], 0, 250),
                'user_login' => substr($user_login, 0, 60),
            ],
            ['id' => (int) $row->id],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    return count($rows);
}

/**
 * @return array{event: string, object_type: string, object_id: int, object_label: string}
 */
function kovalenko_admin_change_audit_parse_legacy_activity(string $activity): array
{
    $result = static fn (string $event, string $type = '', int $id = 0, string $label = ''): array => [
        'event' => $event,
        'object_type' => $type,
        'object_id' => $id,
        'object_label' => $label,
    ];

    $post_label = static function (int $post_id, string $fallback): string {
        $post = get_post($post_id);
        $title = $post instanceof WP_Post ? $post->post_title : '';
        $title = $title !== '' ? $title : ($fallback !== '' ? $fallback : sprintf('#%d', $post_id));
        $type = $post instanceof WP_Post ? get_post_type_object($post->post_type) : null;

        return $type instanceof WP_Post_Type ? sprintf('%s “%s”', $type->labels->singular_name, $title) : $title;
    };

    if (preg_match('/ logged in\.$/', $activity) === 1) {
        return $result('user_login', 'user');
    }
    if (preg_match('/ logged out\.$/', $activity) === 1) {
        return $result('user_logout', 'user');
    }
    if (preg_match("/ deactivated plugin '([^']+)'/", $activity, $m) === 1) {
        return $result('plugin_deactivated', 'plugin', 0, $m[1]);
    }
    if (preg_match("/ activated plugin '([^']+)'/", $activity, $m) === 1) {
        return $result('plugin_activated', 'plugin', 0, $m[1]);
    }
    if (preg_match('/ deleted plugin\(s\): (.+)\.$/', $activity, $m) === 1) {
        return $result('plugin_deleted', 'plugin', 0, $m[1]);
    }
    if (preg_match("/ created post ID (\\d+) \\(.*?\\) with title '(.*)'\\.$/s", $activity, $m) === 1) {
        return $result('post_created', 'post', (int) $m[1], $post_label((int) $m[1], $m[2]));
    }
    if (preg_match("/ moved post ID (\\d+) with title '(.*)' to the trash/s", $activity, $m) === 1) {
        return $result('post_trashed', 'post', (int) $m[1], $post_label((int) $m[1], $m[2]));
    }
    if (preg_match("/ permanently deleted post ID (\\d+) \\(.*?\\) with title '(.*)'/s", $activity, $m) === 1) {
        return $result('post_deleted', 'post', (int) $m[1], $m[2] !== '' ? $m[2] : sprintf('#%d', (int) $m[1]));
    }
    if (preg_match('/ updated post ID (\d+) \(.*?\): (?:updated post meta|post metadata updated)/s', $activity, $m) === 1) {
        return $result('post_meta_updated', 'post', (int) $m[1], $post_label((int) $m[1], ''));
    }
    if (preg_match("/ updated post ID (\\d+) \\(.*?\\) [^:]+: from '/s", $activity, $m) === 1) {
        return $result('post_terms_updated', 'post', (int) $m[1], $post_label((int) $m[1], ''));
    }
    if (preg_match('/ updated post ID (\d+)/', $activity, $m) === 1) {
        return $result('post_updated', 'post', (int) $m[1], $post_label((int) $m[1], ''));
    }

    return $result(Kovalenko_Admin_Change_Audit_Events::LEGACY);
}

function kovalenko_admin_change_audit_format_timestamp(string $utc_mysql): string
{
    try {
        $date = new DateTimeImmutable($utc_mysql, new DateTimeZone('UTC'));
    } catch (Exception) {
        return $utc_mysql;
    }

    $format = trim(get_option('date_format') . ' ' . get_option('time_format'));

    return wp_date($format, $date->getTimestamp(), Kovalenko_Admin_Change_Audit_Settings::timezone()) ?: $utc_mysql;
}

function kovalenko_admin_change_audit_utc_boundary(string $date, bool $end_of_day): ?string
{
    if ($date === '') {
        return null;
    }

    try {
        $local = new DateTimeImmutable($date . ($end_of_day ? ' 23:59:59' : ' 00:00:00'), Kovalenko_Admin_Change_Audit_Settings::timezone());
    } catch (Exception) {
        return null;
    }

    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function kovalenko_admin_change_audit_next_maintenance_timestamp(): int
{
    $next = (new DateTimeImmutable('now', Kovalenko_Admin_Change_Audit_Settings::timezone()))->setTime(2, 0);
    if ($next->getTimestamp() <= time()) {
        $next = $next->modify('+1 day');
    }

    return $next->getTimestamp();
}
