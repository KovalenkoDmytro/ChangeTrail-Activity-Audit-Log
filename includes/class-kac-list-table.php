<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The activity log table, built on the core list table so it looks and behaves like Posts or Users.
 */
final class Kovalenko_Admin_Change_Audit_List_Table extends WP_List_Table
{
    /** @var array{group: string, event: string, user_id: int, device: string, object_type: string, object_id: int, start_date: string, end_date: string, search: string, orderby: string, order: string, paged: int, per_page: int} */
    private array $filters;

    /** @var array<string, int> */
    private array $event_counts = [];

    /** @var array<string, object> */
    private array $devices = [];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'activity',
            'plural' => 'activities',
            'ajax' => false,
            'screen' => get_current_screen(),
        ]);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters from the URL.
        $this->filters = kovalenko_admin_change_audit_normalize_filters(wp_unslash($_GET));
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'event' => __('Activity', 'kovalenko-admin-change-audit'),
            'user' => __('User', 'kovalenko-admin-change-audit'),
            'device' => __('Device', 'kovalenko-admin-change-audit'),
            'ip' => __('IP address', 'kovalenko-admin-change-audit'),
            'date' => __('Date', 'kovalenko-admin-change-audit'),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    protected function get_sortable_columns(): array
    {
        return [
            'user' => ['user', false],
            'date' => ['date', true],
        ];
    }

    protected function get_default_primary_column_name(): string
    {
        return 'event';
    }

    public function prepare_items(): void
    {
        $this->filters['per_page'] = $this->get_items_per_page(Kovalenko_Admin_Change_Audit_Admin::PER_PAGE_OPTION, 20);
        $this->event_counts = kovalenko_admin_change_audit_event_counts();

        $result = kovalenko_admin_change_audit_query_logs($this->filters);
        $this->items = $result['items'];
        $this->devices = Kovalenko_Admin_Change_Audit_Devices::get_many(array_map(static fn (object $item): string => (string) $item->device_id, $this->items));

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page' => $this->filters['per_page'],
            'total_pages' => (int) ceil($result['total'] / max(1, $this->filters['per_page'])),
        ]);

        $this->_column_headers = [$this->get_columns(), get_hidden_columns($this->screen), $this->get_sortable_columns(), 'event'];
    }

    public function no_items(): void
    {
        esc_html_e('No activity found.', 'kovalenko-admin-change-audit');
    }

    /**
     * @return array<string, string>
     */
    protected function get_views(): array
    {
        $base = kovalenko_admin_change_audit()->admin()->page_url();
        $current = $this->filters['group'];
        $total = array_sum($this->event_counts);

        $views = [
            'all' => sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url($base),
                $current === '' ? ' class="current" aria-current="page"' : '',
                esc_html__('All', 'kovalenko-admin-change-audit'),
                esc_html(number_format_i18n($total))
            ),
        ];

        foreach (Kovalenko_Admin_Change_Audit_Events::groups() as $group => $definition) {
            $count = 0;
            foreach (Kovalenko_Admin_Change_Audit_Events::in_group($group) as $event) {
                $count += $this->event_counts[$event] ?? 0;
            }

            $views[$group] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url(add_query_arg('group', $group, $base)),
                $current === $group ? ' class="current" aria-current="page"' : '',
                esc_html($definition['label']),
                esc_html(number_format_i18n($count))
            );
        }

        return $views;
    }

    /**
     * @param string $which
     */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        echo '<div class="alignleft actions">';

        echo '<label for="kac-filter-user" class="screen-reader-text">' . esc_html__('Filter by user', 'kovalenko-admin-change-audit') . '</label>';
        echo '<select name="user" id="kac-filter-user">';
        echo '<option value="">' . esc_html__('All users', 'kovalenko-admin-change-audit') . '</option>';
        foreach (kovalenko_admin_change_audit_logged_users() as $user_id => $label) {
            echo '<option value="' . esc_attr((string) $user_id) . '"' . selected($this->filters['user_id'], $user_id, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        $devices = Kovalenko_Admin_Change_Audit_Devices::all();
        if ($devices !== [] || $this->filters['device'] !== '') {
            echo '<label for="kac-filter-device" class="screen-reader-text">' . esc_html__('Filter by device', 'kovalenko-admin-change-audit') . '</label>';
            echo '<select name="device" id="kac-filter-device">';
            echo '<option value="">' . esc_html__('All devices', 'kovalenko-admin-change-audit') . '</option>';
            $listed = false;
            foreach ($devices as $device) {
                $device_id = (string) $device->device_id;
                $listed = $listed || $device_id === $this->filters['device'];
                $agent = Kovalenko_Admin_Change_Audit_Devices::parse_user_agent((string) $device->last_user_agent);
                $text = Kovalenko_Admin_Change_Audit_Devices::label($device) . ($agent['browser'] !== '' ? ' – ' . implode(', ', array_filter([$agent['browser'], $agent['os']])) : '');
                echo '<option value="' . esc_attr($device_id) . '"' . selected($this->filters['device'], $device_id, false) . '>' . esc_html($text) . '</option>';
            }
            if (! $listed && $this->filters['device'] !== '') {
                echo '<option value="' . esc_attr($this->filters['device']) . '" selected>' . esc_html(Kovalenko_Admin_Change_Audit_Devices::code($this->filters['device'])) . '</option>';
            }
            echo '</select>';
        }

        echo '<label for="kac-filter-event" class="screen-reader-text">' . esc_html__('Filter by activity type', 'kovalenko-admin-change-audit') . '</label>';
        echo '<select name="event" id="kac-filter-event">';
        echo '<option value="">' . esc_html__('All activity types', 'kovalenko-admin-change-audit') . '</option>';
        $events = Kovalenko_Admin_Change_Audit_Events::all();
        foreach (Kovalenko_Admin_Change_Audit_Events::groups() as $group => $definition) {
            echo '<optgroup label="' . esc_attr($definition['label']) . '">';
            foreach ($events as $event => $event_definition) {
                if ($event_definition['group'] === $group) {
                    echo '<option value="' . esc_attr($event) . '"' . selected($this->filters['event'], $event, false) . '>' . esc_html($event_definition['name']) . '</option>';
                }
            }
            echo '</optgroup>';
        }
        if (($this->event_counts[Kovalenko_Admin_Change_Audit_Events::LEGACY] ?? 0) > 0) {
            echo '<option value="' . esc_attr(Kovalenko_Admin_Change_Audit_Events::LEGACY) . '"' . selected($this->filters['event'], Kovalenko_Admin_Change_Audit_Events::LEGACY, false) . '>' . esc_html__('Older entries (before 3.0)', 'kovalenko-admin-change-audit') . '</option>';
        }
        echo '</select>';

        echo '<label for="kac-filter-from" class="screen-reader-text">' . esc_html__('From date', 'kovalenko-admin-change-audit') . '</label>';
        echo '<input type="date" id="kac-filter-from" name="start_date" value="' . esc_attr($this->filters['start_date']) . '" title="' . esc_attr__('From date', 'kovalenko-admin-change-audit') . '">';
        echo '<label for="kac-filter-to" class="screen-reader-text">' . esc_html__('To date', 'kovalenko-admin-change-audit') . '</label>';
        echo '<input type="date" id="kac-filter-to" name="end_date" value="' . esc_attr($this->filters['end_date']) . '" title="' . esc_attr__('To date', 'kovalenko-admin-change-audit') . '">';

        submit_button(__('Filter', 'kovalenko-admin-change-audit'), '', 'filter_action', false, ['id' => 'kac-query-submit']);

        if ($this->has_active_filters()) {
            echo ' <a class="button-link" href="' . esc_url(kovalenko_admin_change_audit()->admin()->page_url()) . '">' . esc_html__('Clear filters', 'kovalenko-admin-change-audit') . '</a>';
        }

        echo '</div>';
    }

    private function has_active_filters(): bool
    {
        return $this->filters['group'] !== ''
            || $this->filters['event'] !== ''
            || $this->filters['user_id'] >= 0
            || $this->filters['device'] !== ''
            || $this->filters['object_type'] !== ''
            || $this->filters['start_date'] !== ''
            || $this->filters['end_date'] !== ''
            || $this->filters['search'] !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function get_filters(): array
    {
        return $this->filters;
    }

    /**
     * @param object $item
     */
    protected function column_event($item): string
    {
        $event = (string) $item->event;
        $output = '<span class="dashicons ' . esc_attr(Kovalenko_Admin_Change_Audit_Events::icon($event)) . '" aria-hidden="true"></span> ';
        $output .= '<strong class="kac-summary">' . kovalenko_admin_change_audit_render_summary($item) . '</strong>';

        $details = kovalenko_admin_change_audit_decode_details(is_string($item->details) ? $item->details : null);
        if ($details !== []) {
            $labels = array_unique(array_column($details, 'label'));
            $output .= '<details class="kac-details"><summary>';
            $output .= esc_html(sprintf(
                /* translators: %s: comma-separated list of changed fields */
                __('Changed: %s', 'kovalenko-admin-change-audit'),
                implode(', ', $labels)
            ));
            $output .= '</summary>' . kovalenko_admin_change_audit_render_details($details) . '</details>';
        }

        $output .= $this->device_details($item);

        return $output . $this->row_actions($this->get_row_actions($item));
    }

    /**
     * @param object $item
     *
     * @return array<string, string>
     */
    private function get_row_actions($item): array
    {
        $actions = [];
        $object_type = (string) $item->object_type;
        $object_id = (int) $item->object_id;

        if ($object_type === 'post' && $object_id > 0 && get_post($object_id) instanceof WP_Post) {
            $edit = get_edit_post_link($object_id);
            if (is_string($edit) && $edit !== '') {
                $actions['edit'] = '<a href="' . esc_url($edit) . '">' . esc_html__('Edit', 'kovalenko-admin-change-audit') . '</a>';
            }
        } elseif ($object_type === 'user' && $object_id > 0 && get_userdata($object_id) instanceof WP_User) {
            $actions['edit'] = '<a href="' . esc_url(get_edit_user_link($object_id)) . '">' . esc_html__('Edit user', 'kovalenko-admin-change-audit') . '</a>';
        }

        if ($object_type !== '' && $object_id > 0) {
            $url = add_query_arg(
                ['object_type' => $object_type, 'object_id' => $object_id],
                kovalenko_admin_change_audit()->admin()->page_url()
            );
            $actions['history'] = '<a href="' . esc_url($url) . '">' . esc_html__('Full history', 'kovalenko-admin-change-audit') . '</a>';
        }

        return $actions;
    }

    /**
     * @param object $item
     */
    protected function column_user($item): string
    {
        $user_id = (int) $item->user_id;
        $filter_url = add_query_arg('user', $user_id, kovalenko_admin_change_audit()->admin()->page_url());

        if ($user_id === 0) {
            $label = (string) $item->event === 'user_login_failed'
                ? __('Unknown visitor', 'kovalenko-admin-change-audit')
                : __('System', 'kovalenko-admin-change-audit');

            return '<span class="kac-user">' . get_avatar(0, 32, 'mystery', '', ['force_default' => true]) . '<a href="' . esc_url($filter_url) . '">' . esc_html($label) . '</a></span>';
        }

        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            $login = (string) $item->user_login !== '' ? (string) $item->user_login : sprintf('#%d', $user_id);

            return '<span class="kac-user">' . get_avatar(0, 32, 'mystery', '', ['force_default' => true])
                . '<a href="' . esc_url($filter_url) . '">' . esc_html($login) . '</a><br><span class="description">' . esc_html__('Deleted user', 'kovalenko-admin-change-audit') . '</span></span>';
        }

        $roles = wp_roles()->get_names();
        $role = $user->roles !== [] && isset($roles[reset($user->roles)]) ? translate_user_role($roles[reset($user->roles)]) : '';

        return '<span class="kac-user">' . get_avatar($user, 32)
            . '<a href="' . esc_url($filter_url) . '">' . esc_html($user->display_name) . '</a>'
            . ($role !== '' ? '<br><span class="description">' . esc_html($role) . '</span>' : '')
            . '</span>';
    }

    /**
     * @param object $item
     */
    protected function column_device($item): string
    {
        $device_id = (string) ($item->device_id ?? '');
        $agent = (string) ($item->user_agent ?? '');
        $none = '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Unknown', 'kovalenko-admin-change-audit') . '</span>';

        if ($device_id === '' && $agent === '') {
            return $none;
        }

        $device = $this->devices[$device_id] ?? null;
        $output = '';
        if ($device_id !== '') {
            $name = $device !== null && (string) $device->label !== '' ? (string) $device->label : Kovalenko_Admin_Change_Audit_Devices::code($device_id);
            $url = add_query_arg('device', $device_id, kovalenko_admin_change_audit()->admin()->page_url());
            $output .= '<a href="' . esc_url($url) . '" class="kac-device-name">' . esc_html($name) . '</a>';
            if ($device !== null && (string) $device->label !== '') {
                $output .= ' <code>' . esc_html(Kovalenko_Admin_Change_Audit_Devices::code($device_id)) . '</code>';
            }
        }

        $browser = Kovalenko_Admin_Change_Audit_Devices::describe_user_agent($agent);
        if ($browser !== '') {
            $output .= ($output !== '' ? '<br>' : '') . '<span class="description">' . esc_html($browser) . '</span>';
        }

        if ($device !== null && (string) $device->same_as !== '') {
            $same = Kovalenko_Admin_Change_Audit_Devices::get((string) $device->same_as);
            $output .= '<br><span class="description kac-same-as">' . esc_html(sprintf(
                /* translators: %s: device name or code */
                __('Probably the same as %s', 'kovalenko-admin-change-audit'),
                Kovalenko_Admin_Change_Audit_Devices::label($same, (string) $device->same_as)
            )) . '</span>';
        }

        return $output;
    }

    /**
     * Session, device ID, fingerprint and user agent, shown in the row details under the activity.
     *
     * @param object $item
     */
    private function device_details($item): string
    {
        $device_id = (string) ($item->device_id ?? '');
        $agent = (string) ($item->user_agent ?? '');
        $device = $this->devices[$device_id] ?? null;

        $rows = [];
        if ((string) ($item->session_hash ?? '') !== '') {
            $rows[__('Session', 'kovalenko-admin-change-audit')] = (string) $item->session_hash;
        }
        if ($device_id !== '') {
            $rows[__('Device ID', 'kovalenko-admin-change-audit')] = $device_id;
        }
        if ($device !== null && (string) $device->fingerprint !== '') {
            $rows[__('Fingerprint', 'kovalenko-admin-change-audit')] = substr((string) $device->fingerprint, 0, 12) . ' (' . Kovalenko_Admin_Change_Audit_Devices::fingerprint_summary($device) . ')';
        }
        if ($agent !== '') {
            $rows[__('User agent', 'kovalenko-admin-change-audit')] = $agent;
        }

        if ($rows === []) {
            return '';
        }

        $output = '<details class="kac-details kac-device-details"><summary>' . esc_html__('Device and session', 'kovalenko-admin-change-audit') . '</summary><table class="widefat striped kac-changes"><tbody>';
        foreach ($rows as $label => $value) {
            $output .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }

        return $output . '</tbody></table></details>';
    }

    /**
     * @param object $item
     */
    protected function column_ip($item): string
    {
        $ip = (string) $item->ip_address;
        if ($ip === '' || $ip === 'Unknown') {
            return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Unknown', 'kovalenko-admin-change-audit') . '</span>';
        }

        return '<a href="' . esc_url(add_query_arg('s', $ip, kovalenko_admin_change_audit()->admin()->page_url())) . '"><code>' . esc_html($ip) . '</code></a>';
    }

    /**
     * @param object $item
     */
    protected function column_date($item): string
    {
        $created = (string) $item->created_at;
        $timestamp = strtotime($created . ' UTC');
        if ($timestamp === false) {
            return esc_html($created);
        }

        return '<time datetime="' . esc_attr(gmdate('c', $timestamp)) . '">' . esc_html(kovalenko_admin_change_audit_format_timestamp($created)) . '</time>'
            . '<br><span class="description">' . esc_html(sprintf(
                /* translators: %s: human-readable time difference, e.g. "5 mins" */
                __('%s ago', 'kovalenko-admin-change-audit'),
                human_time_diff($timestamp)
            )) . '</span>';
    }
}
