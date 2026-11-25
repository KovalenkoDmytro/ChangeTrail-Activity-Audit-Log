<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/*
 * Markup for the Tools > ChangeTrail screen. Uses only core admin components (wrap, nav-tab, list table,
 * form-table, notices, card) so it blends in with the rest of wp-admin.
 */

function kovalenko_admin_change_audit_render_page(Kovalenko_Admin_Change_Audit_Admin $admin): void
{
    if (! Kovalenko_Admin_Change_Audit_Access::is_owner()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'kovalenko-admin-change-audit'), '', ['response' => 403]);
    }

    echo '<div class="wrap kac-wrap">';

    if (! Kovalenko_Admin_Change_Audit_Access::is_unlocked()) {
        echo '<h1>' . esc_html__('ChangeTrail', 'kovalenko-admin-change-audit') . '</h1>';
        kovalenko_admin_change_audit_render_lock_screen($admin);
        echo '</div>';

        return;
    }

    $tab = $admin->current_tab();
    $table = $admin->list_table();

    echo '<h1 class="wp-heading-inline">' . esc_html__('ChangeTrail', 'kovalenko-admin-change-audit') . '</h1>';
    if ($tab === 'log' && $table !== null) {
        echo ' <a href="' . esc_url($admin->export_url($table->get_filters())) . '" class="page-title-action">' . esc_html__('Export CSV', 'kovalenko-admin-change-audit') . '</a>';
    }
    echo '<hr class="wp-header-end">';

    echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__('ChangeTrail sections', 'kovalenko-admin-change-audit') . '">';
    $tabs = [
        'log' => __('Activity log', 'kovalenko-admin-change-audit'),
        'devices' => __('Devices', 'kovalenko-admin-change-audit'),
        'settings' => __('Settings', 'kovalenko-admin-change-audit'),
    ];
    foreach ($tabs as $key => $label) {
        $url = $admin->page_url($key === 'log' ? '' : $key);
        $current = $tab === $key;
        echo '<a href="' . esc_url($url) . '" class="nav-tab' . ($current ? ' nav-tab-active' : '') . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
    }
    echo '</nav>';

    if ($tab === 'settings') {
        kovalenko_admin_change_audit_render_settings($admin);
    } elseif ($tab === 'devices') {
        kovalenko_admin_change_audit_render_devices($admin);
    } elseif ($table !== null) {
        kovalenko_admin_change_audit_render_log($admin, $table);
    }

    echo '</div>';
}

function kovalenko_admin_change_audit_render_log(Kovalenko_Admin_Change_Audit_Admin $admin, Kovalenko_Admin_Change_Audit_List_Table $table): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag set by our own redirect.
    if (isset($_GET['logs-deleted'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All log entries were deleted.', 'kovalenko-admin-change-audit') . '</p></div>';
    }

    $pending = kovalenko_admin_change_audit_pending_backfill_count();
    if ($pending > 0) {
        echo '<div class="notice notice-info"><p>' . esc_html(sprintf(
            /* translators: %s: number of log entries */
            _n('ChangeTrail is updating %s older log entry in the background. Filters may miss it until this finishes.', 'ChangeTrail is updating %s older log entries in the background. Filters may miss them until this finishes.', $pending, 'kovalenko-admin-change-audit'),
            number_format_i18n($pending)
        )) . '</p></div>';
    }

    $table->prepare_items();
    $table->views();

    echo '<form method="get" action="' . esc_url(admin_url('tools.php')) . '">';
    echo '<input type="hidden" name="page" value="' . esc_attr(Kovalenko_Admin_Change_Audit_Admin::PAGE_SLUG) . '">';

    $filters = $table->get_filters();
    if ($filters['group'] !== '') {
        echo '<input type="hidden" name="group" value="' . esc_attr($filters['group']) . '">';
    }
    if ($filters['object_type'] !== '') {
        echo '<input type="hidden" name="object_type" value="' . esc_attr($filters['object_type']) . '">';
        echo '<input type="hidden" name="object_id" value="' . esc_attr((string) $filters['object_id']) . '">';
    }

    $table->search_box(__('Search log', 'kovalenko-admin-change-audit'), 'kac-search');
    $table->display();
    echo '</form>';

    echo '<p class="description">' . esc_html(sprintf(
        /* translators: %s: timezone name */
        __('Times are shown in %s.', 'kovalenko-admin-change-audit'),
        Kovalenko_Admin_Change_Audit_Settings::timezone_name()
    )) . ' <a href="' . esc_url($admin->page_url('settings')) . '">' . esc_html__('Change', 'kovalenko-admin-change-audit') . '</a></p>';
}

function kovalenko_admin_change_audit_render_lock_screen(Kovalenko_Admin_Change_Audit_Admin $admin): void
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flags set by our own redirect.
    $has_error = isset($_GET['access_error']);
    // phpcs:enable

    $remaining = Kovalenko_Admin_Change_Audit_Access::lockout_remaining();

    if ($remaining > 0) {
        echo '<div class="notice notice-error"><p>' . esc_html(sprintf(
            /* translators: %d: minutes */
            _n('Too many wrong passwords. Try again in %d minute.', 'Too many wrong passwords. Try again in %d minutes.', (int) ceil($remaining / 60), 'kovalenko-admin-change-audit'),
            (int) ceil($remaining / 60)
        )) . '</p></div>';
    } elseif ($has_error) {
        echo '<div class="notice notice-error"><p>' . esc_html__('That password is not correct.', 'kovalenko-admin-change-audit') . '</p></div>';
    }

    echo '<div class="card">';
    echo '<h2 class="title">' . esc_html__('Enter the access password', 'kovalenko-admin-change-audit') . '</h2>';
    echo '<p>' . esc_html__('The activity log is protected with an extra password.', 'kovalenko-admin-change-audit') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr($admin->unlock_action()) . '">';
    wp_nonce_field($admin->unlock_action());
    echo '<p><label for="kac-password">' . esc_html__('Access password', 'kovalenko-admin-change-audit') . '</label><br>';
    echo '<input type="password" id="kac-password" name="kac_password" class="regular-text" autocomplete="current-password" required' . ($remaining > 0 ? ' disabled' : '') . '></p>';
    submit_button(__('Unlock', 'kovalenko-admin-change-audit'), 'primary', 'submit', true, $remaining > 0 ? ['disabled' => 'disabled'] : []);
    echo '</form>';
    echo '</div>';
}

function kovalenko_admin_change_audit_render_settings(Kovalenko_Admin_Change_Audit_Admin $admin): void
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flags set by our own redirect.
    $updated = isset($_GET['settings-updated']);
    $error = isset($_GET['settings_error']) ? sanitize_key(wp_unslash($_GET['settings_error'])) : '';
    // phpcs:enable

    if ($updated) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'kovalenko-admin-change-audit') . '</p></div>';
    } elseif ($error === 'password_mismatch') {
        echo '<div class="notice notice-error"><p>' . esc_html__('Other settings were saved, but the access password was not changed: the two passwords do not match.', 'kovalenko-admin-change-audit') . '</p></div>';
    } elseif ($error === 'password_short') {
        echo '<div class="notice notice-error"><p>' . esc_html__('Other settings were saved, but the access password was not changed: use at least 8 characters.', 'kovalenko-admin-change-audit') . '</p></div>';
    }

    $settings = Kovalenko_Admin_Change_Audit_Settings::all();
    $stored_timezone = Kovalenko_Admin_Change_Audit_Settings::stored_timezone();

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="kac-settings-form">';
    echo '<input type="hidden" name="action" value="' . esc_attr($admin->save_action()) . '">';
    wp_nonce_field($admin->save_action());

    // General.
    echo '<h2>' . esc_html__('General', 'kovalenko-admin-change-audit') . '</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="kac-retention">' . esc_html__('Keep log entries for', 'kovalenko-admin-change-audit') . '</label></th><td>';
    echo '<select id="kac-retention" name="retention_days">';
    foreach (Kovalenko_Admin_Change_Audit_Settings::RETENTION_CHOICES as $days) {
        $label = $days === 0
            ? __('Forever', 'kovalenko-admin-change-audit')
            /* translators: %d: number of days */
            : sprintf(_n('%d day', '%d days', $days, 'kovalenko-admin-change-audit'), $days);
        echo '<option value="' . esc_attr((string) $days) . '"' . selected($settings['retention_days'], $days, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__('Older entries are deleted automatically once a day. Lowering this deletes older entries at the next cleanup.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="kac-timezone">' . esc_html__('Timezone', 'kovalenko-admin-change-audit') . '</label></th><td>';
    echo '<select id="kac-timezone" name="timezone">';
    echo '<option value=""' . selected($stored_timezone, '', false) . '>' . esc_html(sprintf(
        /* translators: %s: site timezone */
        __('Same as the site (%s)', 'kovalenko-admin-change-audit'),
        wp_timezone_string()
    )) . '</option>';
    $current_group = '';
    foreach (timezone_identifiers_list() as $identifier) {
        $group = str_contains($identifier, '/') ? strstr($identifier, '/', true) : $identifier;
        if ($group !== $current_group) {
            if ($current_group !== '') {
                echo '</optgroup>';
            }
            echo '<optgroup label="' . esc_attr((string) $group) . '">';
            $current_group = (string) $group;
        }
        echo '<option value="' . esc_attr($identifier) . '"' . selected($stored_timezone, $identifier, false) . '>' . esc_html(str_replace('_', ' ', $identifier)) . '</option>';
    }
    echo '</optgroup></select>';
    echo '<p class="description">' . esc_html__('Used for log times, date filters, exports and the nightly cleanup.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Toolbar', 'kovalenko-admin-change-audit') . '</th><td>';
    echo '<label for="kac-admin-bar"><input type="checkbox" id="kac-admin-bar" name="admin_bar" value="1"' . checked($settings['admin_bar'], true, false) . '> ' . esc_html__('Show a ChangeTrail link in the toolbar', 'kovalenko-admin-change-audit') . '</label>';
    echo '</td></tr>';
    echo '</tbody></table>';

    // What to log.
    echo '<h2>' . esc_html__('What to log', 'kovalenko-admin-change-audit') . '</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row">' . esc_html__('Activity types', 'kovalenko-admin-change-audit') . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__('Activity types', 'kovalenko-admin-change-audit') . '</legend>';
    $group_help = [
        Kovalenko_Admin_Change_Audit_Events::GROUP_CONTENT => __('Posts, pages and other content: created, edited, trashed, deleted, custom fields, categories and tags.', 'kovalenko-admin-change-audit'),
        Kovalenko_Admin_Change_Audit_Events::GROUP_USERS => __('Logins, logouts, failed logins, new and deleted users, role, email and password changes.', 'kovalenko-admin-change-audit'),
        Kovalenko_Admin_Change_Audit_Events::GROUP_EXTENSIONS => __('Plugins and themes installed, updated, activated, deactivated or deleted, and WordPress updates.', 'kovalenko-admin-change-audit'),
        Kovalenko_Admin_Change_Audit_Events::GROUP_SETTINGS => __('Key site settings such as site address, admin email, membership, default role and permalinks.', 'kovalenko-admin-change-audit'),
    ];
    foreach (Kovalenko_Admin_Change_Audit_Events::groups() as $group => $definition) {
        echo '<label for="kac-group-' . esc_attr($group) . '"><input type="checkbox" id="kac-group-' . esc_attr($group) . '" name="groups[]" value="' . esc_attr($group) . '"' . checked(in_array($group, $settings['groups'], true), true, false) . '> <strong>' . esc_html($definition['label']) . '</strong></label>';
        echo '<p class="description kac-checkbox-help">' . esc_html($group_help[$group] ?? '') . '</p>';
    }
    echo '<p class="description">' . esc_html__('Changes to ChangeTrail itself (settings, owner, password, wrong passwords, clearing the log) are always logged.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</fieldset></td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Device fingerprint', 'kovalenko-admin-change-audit') . '</th><td>';
    echo '<label for="kac-fingerprinting"><input type="checkbox" id="kac-fingerprinting" name="fingerprinting" value="1"' . checked($settings['fingerprinting'], true, false) . '> ' . esc_html__('Recognize computers by their hardware and browser details', 'kovalenko-admin-change-audit') . '</label>';
    echo '<p class="description">' . esc_html__('Every entry always records a device cookie, a short hash of the login session and the browser. With this on, a small script in wp-admin also reads the screen size, graphics card, CPU cores, memory, timezone and language once per session, so a computer is recognized even after its cookies are cleared. Helpful when several people share one account behind the same IP address.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Ignored post types', 'kovalenko-admin-change-audit') . '</th><td><fieldset class="kac-scroll-list"><legend class="screen-reader-text">' . esc_html__('Ignored post types', 'kovalenko-admin-change-audit') . '</legend>';
    $post_types = get_post_types([], 'objects');
    uasort($post_types, static fn ($a, $b): int => strcasecmp((string) $a->labels->name, (string) $b->labels->name));
    foreach ($post_types as $slug => $post_type) {
        if (in_array($slug, ['revision', 'wp_block_revision'], true)) {
            continue;
        }
        echo '<label><input type="checkbox" name="excluded_post_types[]" value="' . esc_attr($slug) . '"' . checked(in_array($slug, $settings['excluded_post_types'], true), true, false) . '> ' . esc_html((string) $post_type->labels->name) . ' <code>' . esc_html($slug) . '</code></label><br>';
    }
    echo '</fieldset><p class="description">' . esc_html__('Changes to checked post types are not logged.', 'kovalenko-admin-change-audit') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="kac-meta-keys">' . esc_html__('Ignored custom fields', 'kovalenko-admin-change-audit') . '</label></th><td>';
    echo '<textarea id="kac-meta-keys" name="excluded_meta_keys" rows="6" cols="40" class="code">' . esc_textarea(implode("\n", $settings['excluded_meta_keys'])) . '</textarea>';
    echo '<p class="description">' . esc_html__('One custom field (meta key) per line. End with * to match a prefix, for example _elementor_*. Values longer than 2 KB are always shortened, and fields that look like passwords or keys are hidden.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</td></tr>';
    echo '</tbody></table>';

    // Access.
    echo '<h2>' . esc_html__('Access', 'kovalenko-admin-change-audit') . '</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="kac-owner">' . esc_html__('Log owner', 'kovalenko-admin-change-audit') . '</label></th><td>';
    echo '<select id="kac-owner" name="owner" data-kac-current="' . esc_attr((string) Kovalenko_Admin_Change_Audit_Access::owner_id()) . '" data-kac-confirm="' . esc_attr__('Only the new owner will be able to open ChangeTrail, and you will lose access. Continue?', 'kovalenko-admin-change-audit') . '">';
    foreach (Kovalenko_Admin_Change_Audit_Access::eligible_owners() as $user) {
        echo '<option value="' . esc_attr((string) $user->ID) . '"' . selected(Kovalenko_Admin_Change_Audit_Access::owner_id(), $user->ID, false) . '>' . esc_html(sprintf('%s (%s)', $user->display_name, $user->user_login)) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__('Only this administrator can see the log and these settings. Choosing someone else hands the log over to them.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__('Access password', 'kovalenko-admin-change-audit') . '</th><td>';
    if (Kovalenko_Admin_Change_Audit_Access::is_password_from_constant()) {
        echo '<p>' . esc_html__('The password is set in wp-config.php (KOVALENKO_ADMIN_CHANGE_AUDIT_ACCESS_PASSWORD) and cannot be changed here.', 'kovalenko-admin-change-audit') . '</p>';
    } else {
        $has_password = Kovalenko_Admin_Change_Audit_Access::saved_hash() !== '';
        echo '<p>' . esc_html($has_password ? __('An extra password is set.', 'kovalenko-admin-change-audit') : __('No extra password. Your WordPress login is enough.', 'kovalenko-admin-change-audit')) . '</p>';
        echo '<p><label for="kac-new-password">' . esc_html($has_password ? __('New password', 'kovalenko-admin-change-audit') : __('Password', 'kovalenko-admin-change-audit')) . '</label><br>';
        echo '<input type="password" id="kac-new-password" name="kac_new_password" class="regular-text" autocomplete="new-password" minlength="8"></p>';
        echo '<p><label for="kac-confirm-password">' . esc_html__('Repeat password', 'kovalenko-admin-change-audit') . '</label><br>';
        echo '<input type="password" id="kac-confirm-password" name="kac_confirm_password" class="regular-text" autocomplete="new-password" minlength="8"></p>';
        if ($has_password) {
            echo '<p><label for="kac-remove-password"><input type="checkbox" id="kac-remove-password" name="kac_remove_password" value="1"> ' . esc_html__('Remove the extra password', 'kovalenko-admin-change-audit') . '</label></p>';
        }
        echo '<p class="description">' . esc_html__('Optional. Asked before the log opens, on top of your WordPress login. After 5 wrong tries the screen locks for 15 minutes.', 'kovalenko-admin-change-audit') . '</p>';
    }
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="kac-unlock-hours">' . esc_html__('Stay unlocked for', 'kovalenko-admin-change-audit') . '</label></th><td>';
    echo '<select id="kac-unlock-hours" name="unlock_hours">';
    foreach (Kovalenko_Admin_Change_Audit_Settings::UNLOCK_CHOICES as $hours) {
        /* translators: %d: number of hours */
        echo '<option value="' . esc_attr((string) $hours) . '"' . selected($settings['unlock_hours'], $hours, false) . '>' . esc_html(sprintf(_n('%d hour', '%d hours', $hours, 'kovalenko-admin-change-audit'), $hours)) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__('Only for the current login session. Logging out locks the log again.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</td></tr>';
    echo '</tbody></table>';

    // Uninstall.
    echo '<h2>' . esc_html__('Uninstall', 'kovalenko-admin-change-audit') . '</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('When the plugin is deleted', 'kovalenko-admin-change-audit') . '</th><td>';
    echo '<label for="kac-keep-data"><input type="checkbox" id="kac-keep-data" name="keep_data" value="1"' . checked($settings['keep_data'], true, false) . '> ' . esc_html__('Keep the log and settings in the database', 'kovalenko-admin-change-audit') . '</label>';
    echo '<p class="description">' . esc_html__('By default, deleting ChangeTrail from the Plugins screen removes its log table and settings.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</td></tr>';
    echo '</tbody></table>';

    submit_button();
    echo '</form>';

    // Tools.
    echo '<hr>';
    echo '<h2>' . esc_html__('Log data', 'kovalenko-admin-change-audit') . '</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('Export', 'kovalenko-admin-change-audit') . '</th><td>';
    echo '<a class="button" href="' . esc_url($admin->export_url([])) . '">' . esc_html__('Download all entries (CSV)', 'kovalenko-admin-change-audit') . '</a>';
    echo '<p class="description">' . esc_html__('To export only some entries, filter the activity log first and use Export CSV there.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Delete', 'kovalenko-admin-change-audit') . '</th><td>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr($admin->delete_all_action()) . '">';
    wp_nonce_field($admin->delete_all_action());
    submit_button(__('Delete all log entries', 'kovalenko-admin-change-audit'), 'delete', 'kac-delete-all', false, [
        'data-kac-confirm' => __('Delete every ChangeTrail log entry? This cannot be undone.', 'kovalenko-admin-change-audit'),
    ]);
    echo '<p class="description">' . esc_html__('Removes every entry permanently. A new entry records that the log was cleared.', 'kovalenko-admin-change-audit') . '</p>';
    echo '</form>';
    echo '</td></tr>';
    echo '</tbody></table>';
}

/**
 * Summary sentence for one log row, with the object name linked when it still exists.
 */
function kovalenko_admin_change_audit_render_summary(object $item): string
{
    $event = (string) $item->event;
    $events = Kovalenko_Admin_Change_Audit_Events::all();

    if (! isset($events[$event])) {
        return esc_html((string) $item->activity);
    }

    $label = (string) $item->object_label;
    $object_id = (int) $item->object_id;
    $url = '';

    if ((string) $item->object_type === 'post' && $object_id > 0 && get_post($object_id) instanceof WP_Post) {
        $url = (string) get_edit_post_link($object_id);
    } elseif ((string) $item->object_type === 'user' && $object_id > 0 && get_userdata($object_id) instanceof WP_User) {
        $url = get_edit_user_link($object_id);
    }

    $object_html = $url !== '' ? '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>' : esc_html($label);

    $second = $event === 'device_matched' ? Kovalenko_Admin_Change_Audit_Devices::matched_label_for_entry($item) : '';

    return sprintf(esc_html($events[$event]['summary']), $object_html, esc_html($second));
}

/**
 * Before/after view of the changed fields. Long text uses the core revisions diff.
 *
 * @param list<array{label: string, before: string, after: string}> $details
 */
function kovalenko_admin_change_audit_render_details(array $details): string
{
    $empty = '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Empty', 'kovalenko-admin-change-audit') . '</span>';
    $rows = '';
    $diffs = '';

    foreach ($details as $detail) {
        $is_long = strlen($detail['before']) > 120 || strlen($detail['after']) > 120 || str_contains($detail['before'] . $detail['after'], "\n");

        if ($is_long) {
            $diff = wp_text_diff($detail['before'], $detail['after'], [
                'title' => $detail['label'],
                'title_left' => __('Before', 'kovalenko-admin-change-audit'),
                'title_right' => __('After', 'kovalenko-admin-change-audit'),
                'show_split_view' => true,
            ]);
            if ($diff !== '') {
                $diffs .= '<div class="kac-diff">' . $diff . '</div>';
                continue;
            }
        }

        $rows .= '<tr><th scope="row">' . esc_html($detail['label']) . '</th>'
            . '<td>' . ($detail['before'] !== '' ? esc_html($detail['before']) : $empty) . '</td>'
            . '<td>' . ($detail['after'] !== '' ? esc_html($detail['after']) : $empty) . '</td></tr>';
    }

    $output = '';
    if ($rows !== '') {
        $output .= '<table class="widefat striped kac-changes"><thead><tr>'
            . '<th scope="col">' . esc_html__('Field', 'kovalenko-admin-change-audit') . '</th>'
            . '<th scope="col">' . esc_html__('Before', 'kovalenko-admin-change-audit') . '</th>'
            . '<th scope="col">' . esc_html__('After', 'kovalenko-admin-change-audit') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    return $output . $diffs;
}

function kovalenko_admin_change_audit_render_devices(Kovalenko_Admin_Change_Audit_Admin $admin): void
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flags set by our own redirect.
    if (isset($_GET['devices-updated'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Device names saved.', 'kovalenko-admin-change-audit') . '</p></div>';
    } elseif (isset($_GET['device-forgotten'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The device was forgotten. Its old log entries keep their device code.', 'kovalenko-admin-change-audit') . '</p></div>';
    }
    // phpcs:enable

    echo '<p>' . esc_html__('Computers and browsers that signed in to this site. Give each one a name (for example "Reception PC") so the log shows who sat where, even when everyone uses the same account and IP address.', 'kovalenko-admin-change-audit') . '</p>';

    $devices = Kovalenko_Admin_Change_Audit_Devices::all();
    if ($devices === []) {
        echo '<p><em>' . esc_html__('No devices recorded yet.', 'kovalenko-admin-change-audit') . '</em></p>';

        return;
    }

    $current = Kovalenko_Admin_Change_Audit_Devices::current_id();
    $by_id = [];
    foreach ($devices as $device) {
        $by_id[(string) $device->device_id] = $device;
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr($admin->save_devices_action()) . '">';
    wp_nonce_field($admin->save_devices_action());

    echo '<table class="wp-list-table widefat fixed striped kac-devices"><thead><tr>';
    echo '<th scope="col" class="column-primary">' . esc_html__('Device', 'kovalenko-admin-change-audit') . '</th>';
    echo '<th scope="col">' . esc_html__('Browser', 'kovalenko-admin-change-audit') . '</th>';
    echo '<th scope="col">' . esc_html__('Users', 'kovalenko-admin-change-audit') . '</th>';
    echo '<th scope="col">' . esc_html__('Last seen', 'kovalenko-admin-change-audit') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($devices as $device) {
        $device_id = (string) $device->device_id;
        $code = Kovalenko_Admin_Change_Audit_Devices::code($device_id);
        $field_id = 'kac-device-' . $device_id;

        echo '<tr><td class="column-primary" data-colname="' . esc_attr__('Device', 'kovalenko-admin-change-audit') . '">';
        echo '<label for="' . esc_attr($field_id) . '"><strong>' . esc_html($code) . '</strong></label>';
        if ($device_id === $current) {
            echo ' <span class="kac-badge">' . esc_html__('This browser', 'kovalenko-admin-change-audit') . '</span>';
        }
        echo '<br><input type="text" id="' . esc_attr($field_id) . '" name="labels[' . esc_attr($device_id) . ']" value="' . esc_attr((string) $device->label) . '" class="kac-device-label" maxlength="100" placeholder="' . esc_attr__('Name this device', 'kovalenko-admin-change-audit') . '">';

        $same_as = (string) $device->same_as;
        if ($same_as !== '') {
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: device name or code */
                __('Probably the same computer as %s (same fingerprint).', 'kovalenko-admin-change-audit'),
                Kovalenko_Admin_Change_Audit_Devices::label($by_id[$same_as] ?? Kovalenko_Admin_Change_Audit_Devices::get($same_as), $same_as)
            )) . '</p>';
        }

        $components = Kovalenko_Admin_Change_Audit_Devices::fingerprint_components($device);
        if ($components !== []) {
            echo '<details class="kac-device-details"><summary>' . esc_html__('Fingerprint', 'kovalenko-admin-change-audit') . ' <code>' . esc_html(substr((string) $device->fingerprint, 0, 12)) . '</code></summary><table class="widefat striped kac-changes"><tbody>';
            foreach ($components as $key => $value) {
                echo '<tr><th scope="row">' . esc_html($key) . '</th><td>' . esc_html($value) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }

        echo '<div class="row-actions visible">';
        echo '<span><a href="' . esc_url(add_query_arg('device', $device_id, $admin->page_url())) . '">' . esc_html__('Show activity', 'kovalenko-admin-change-audit') . '</a> | </span>';
        echo '<span class="delete"><a href="' . esc_url($admin->forget_device_url($device_id)) . '" class="submitdelete" data-kac-confirm="' . esc_attr__('Forget this device? Its name and fingerprint are removed. If it is used again it shows up as a new device.', 'kovalenko-admin-change-audit') . '">' . esc_html__('Forget', 'kovalenko-admin-change-audit') . '</a></span>';
        echo '</div>';
        echo '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__('Show more details', 'kovalenko-admin-change-audit') . '</span></button>';
        echo '</td>';

        echo '<td data-colname="' . esc_attr__('Browser', 'kovalenko-admin-change-audit') . '">' . esc_html(Kovalenko_Admin_Change_Audit_Devices::describe_user_agent((string) $device->last_user_agent));
        $summary = Kovalenko_Admin_Change_Audit_Devices::fingerprint_summary($device);
        if ($summary !== '') {
            echo '<br><span class="description">' . esc_html($summary) . '</span>';
        }
        echo '</td>';

        $names = [];
        foreach (Kovalenko_Admin_Change_Audit_Devices::user_ids($device) as $user_id) {
            $user = get_userdata($user_id);
            $names[] = $user instanceof WP_User ? $user->user_login : '#' . $user_id;
        }
        echo '<td data-colname="' . esc_attr__('Users', 'kovalenko-admin-change-audit') . '">' . esc_html(implode(', ', $names)) . '</td>';

        echo '<td data-colname="' . esc_attr__('Last seen', 'kovalenko-admin-change-audit') . '">' . esc_html(kovalenko_admin_change_audit_format_timestamp((string) $device->last_seen));
        echo '<br><span class="description">' . esc_html(sprintf(
            /* translators: 1: date, 2: IP address */
            __('First seen %1$s · IP %2$s', 'kovalenko-admin-change-audit'),
            kovalenko_admin_change_audit_format_timestamp((string) $device->first_seen),
            (string) $device->last_ip !== '' ? (string) $device->last_ip : '—'
        )) . '</span></td></tr>';
    }

    echo '</tbody></table>';
    submit_button(__('Save device names', 'kovalenko-admin-change-audit'));
    echo '</form>';
}
