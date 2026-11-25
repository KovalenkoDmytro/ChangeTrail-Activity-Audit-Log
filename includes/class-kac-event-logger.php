<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hooks into WordPress and writes structured events to the log.
 */
final class Kovalenko_Admin_Change_Audit_Event_Logger
{
    /** Internal bookkeeping meta keys that never mean anything to a person reading the log. */
    private const IGNORED_META_KEYS = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_trash_meta_comments_status',
        '_wp_desired_post_slug',
        '_wp_attachment_metadata',
        '_wp_attachment_backup_sizes',
        '_pingme',
        '_encloseme',
        '_trackbackme',
    ];

    /** Meta keys whose values are replaced with a placeholder because they may hold secrets. */
    private const SECRET_META_PATTERN = '/(pass(word|wd)?|secret|token|api[_-]?key|private[_-]?key|license[_-]?key)/i';

    private const META_KEY_LABELS = [
        '_thumbnail_id' => 'Featured image',
        '_wp_page_template' => 'Page template',
        '_yoast_wpseo_title' => 'SEO title (Yoast)',
        '_yoast_wpseo_metadesc' => 'SEO description (Yoast)',
        '_yoast_wpseo_focuskw' => 'SEO focus keyword (Yoast)',
        '_yoast_wpseo_canonical' => 'SEO canonical URL (Yoast)',
        'rank_math_title' => 'SEO title (Rank Math)',
        'rank_math_description' => 'SEO description (Rank Math)',
        'rank_math_focus_keyword' => 'SEO focus keyword (Rank Math)',
        '_aioseo_title' => 'SEO title (AIOSEO)',
        '_aioseo_description' => 'SEO description (AIOSEO)',
    ];

    /** @var array<int, array<string, array{before: string, after: string}>> */
    private array $pending_meta = [];

    /** @var array<int, int> */
    private array $pending_meta_actor = [];

    /** @var array<string, string> Plugin/theme versions captured before an update. */
    private array $versions_before = [];

    /** @var array<string, string> Names captured before a plugin or theme is deleted. */
    private array $names_before_delete = [];

    private string $boot_wp_version = '';

    /** @var array<int, true> Posts created in this request; their initial fields/terms are not separate changes. */
    private array $created_in_request = [];

    private bool $inserting_new_post = false;

    public function register_hooks(): void
    {
        $this->boot_wp_version = (string) get_bloginfo('version');

        // Content.
        add_filter('wp_insert_post_data', [$this, 'mark_new_post_insert'], 10, 4);
        add_action('post_updated', [$this, 'on_post_updated'], 10, 3);
        add_action('wp_insert_post', [$this, 'on_post_inserted'], 10, 3);
        add_action('wp_trash_post', [$this, 'on_post_trashed']);
        add_action('untrashed_post', [$this, 'on_post_restored']);
        add_action('before_delete_post', [$this, 'on_post_deleted']);
        add_action('add_post_meta', [$this, 'on_meta_added'], 10, 3);
        add_action('update_post_meta', [$this, 'on_meta_updated'], 10, 4);
        add_action('delete_post_meta', [$this, 'on_meta_deleted'], 10, 4);
        add_action('set_object_terms', [$this, 'on_terms_set'], 10, 6);
        add_action('shutdown', [$this, 'flush_pending_meta']);

        // Users and logins.
        add_action('wp_login', [$this, 'on_login'], 10, 2);
        add_action('wp_logout', [$this, 'on_logout']);
        add_action('wp_login_failed', [$this, 'on_login_failed']);
        add_action('user_register', [$this, 'on_user_created']);
        add_action('delete_user', [$this, 'on_user_deleted'], 10, 3);
        add_action('set_user_role', [$this, 'on_role_changed'], 10, 3);
        add_action('profile_update', [$this, 'on_profile_updated'], 10, 2);
        add_action('after_password_reset', [$this, 'on_password_reset']);

        // Plugins, themes and core.
        add_action('activated_plugin', [$this, 'on_plugin_activated']);
        add_action('deactivated_plugin', [$this, 'on_plugin_deactivated']);
        add_action('delete_plugin', [$this, 'before_plugin_deleted']);
        add_action('deleted_plugin', [$this, 'on_plugin_deleted'], 10, 2);
        add_filter('upgrader_pre_install', [$this, 'capture_version_before_update'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'on_upgrader_complete'], 10, 2);
        add_action('switch_theme', [$this, 'on_theme_switched'], 10, 3);
        add_action('delete_theme', [$this, 'before_theme_deleted']);
        add_action('deleted_theme', [$this, 'on_theme_deleted'], 10, 2);
        add_action('_core_updated_successfully', [$this, 'on_core_updated']);

        // Site settings.
        add_action('updated_option', [$this, 'on_option_updated'], 10, 3);
    }

    /* ---------------------------------------------------------------------
     * Content
     * ------------------------------------------------------------------- */

    public function on_post_updated(int $post_id, WP_Post $post_after, WP_Post $post_before): void
    {
        if (! $this->should_log_post($post_after)) {
            return;
        }

        // Trash and restore have their own events.
        if ($post_after->post_status === 'trash' || $post_before->post_status === 'trash') {
            return;
        }

        // The block editor creates an auto-draft first; its first real save is the creation.
        if ($post_before->post_status === 'auto-draft') {
            $this->log_post('post_created', $post_after);

            return;
        }

        $details = $this->detect_post_changes($post_before, $post_after);
        if ($details === []) {
            return;
        }

        $this->log_post('post_updated', $post_after, $details);
    }

    /**
     * While a brand-new post is being inserted, its initial terms and fields are part of "created", not changes.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function mark_new_post_insert($data, $postarr = [], $unsanitized = [], $update = false)
    {
        unset($postarr, $unsanitized);
        $this->inserting_new_post = ! $update;

        return $data;
    }

    public function on_post_inserted(int $post_id, WP_Post $post, bool $update): void
    {
        $this->inserting_new_post = false;

        if ($update || ! $this->should_log_post($post)) {
            return;
        }

        $this->log_post('post_created', $post);
    }

    public function on_post_trashed(int $post_id): void
    {
        $post = get_post($post_id);
        if ($post instanceof WP_Post && $this->should_log_post($post)) {
            $this->log_post('post_trashed', $post);
        }
    }

    public function on_post_restored(int $post_id): void
    {
        $post = get_post($post_id);
        if ($post instanceof WP_Post && $this->should_log_post($post)) {
            $this->log_post('post_restored', $post);
        }
    }

    public function on_post_deleted(int $post_id): void
    {
        $post = get_post($post_id);
        if ($post instanceof WP_Post && $this->should_log_post($post)) {
            unset($this->pending_meta[$post_id]);
            $this->log_post('post_deleted', $post);
        }
    }

    public function on_meta_added(int $object_id, string $meta_key, mixed $meta_value): void
    {
        $this->buffer_meta_change($object_id, $meta_key, '', $meta_value);
    }

    public function on_meta_updated(int $meta_id, int $object_id, string $meta_key, mixed $meta_value): void
    {
        unset($meta_id);
        $this->buffer_meta_change($object_id, $meta_key, get_post_meta($object_id, $meta_key, true), $meta_value);
    }

    /**
     * @param array<int, int> $meta_ids
     */
    public function on_meta_deleted(array $meta_ids, int $object_id, string $meta_key, mixed $meta_value): void
    {
        unset($meta_ids, $meta_value);
        $this->buffer_meta_change($object_id, $meta_key, get_post_meta($object_id, $meta_key, true), '');
    }

    public function flush_pending_meta(): void
    {
        foreach ($this->pending_meta as $post_id => $changes) {
            $post = get_post($post_id);
            if (! $post instanceof WP_Post || $changes === []) {
                continue;
            }

            $details = [];
            foreach ($changes as $meta_key => $change) {
                $details[] = [
                    'label' => $this->meta_label($meta_key),
                    'before' => $change['before'],
                    'after' => $change['after'],
                ];
            }

            $this->log_post('post_meta_updated', $post, $details, $this->pending_meta_actor[$post_id] ?? null);
        }

        $this->pending_meta = [];
        $this->pending_meta_actor = [];
    }

    /**
     * @param array<int, int|string> $tt_ids
     * @param array<int, int|string> $old_tt_ids
     */
    public function on_terms_set(int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids): void
    {
        unset($terms, $append);

        $post = get_post($object_id);
        if ($this->inserting_new_post || ! $post instanceof WP_Post || isset($this->created_in_request[$object_id]) || ! $this->should_log_post($post) || ! $this->is_person_acting()) {
            return;
        }

        $before = $this->term_names($old_tt_ids, $taxonomy);
        $after = $this->term_names($tt_ids, $taxonomy);
        if ($before === $after) {
            return;
        }

        $taxonomy_object = get_taxonomy($taxonomy);
        $label = $taxonomy_object instanceof WP_Taxonomy ? (string) $taxonomy_object->labels->singular_name : ucfirst($taxonomy);

        $this->log_post('post_terms_updated', $post, [[
            'label' => $label,
            'before' => implode(', ', $before),
            'after' => implode(', ', $after),
        ]]);
    }

    /* ---------------------------------------------------------------------
     * Users and logins
     * ------------------------------------------------------------------- */

    public function on_login(string $user_login, WP_User $user): void
    {
        $this->record('user_login', [
            'object_type' => 'user',
            'object_id' => (int) $user->ID,
            'object_label' => $user_login,
            'user_id' => (int) $user->ID,
            'user_login' => $user_login,
        ]);
    }

    public function on_logout(int $user_id = 0): void
    {
        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            return;
        }

        $this->record('user_logout', [
            'object_type' => 'user',
            'object_id' => $user_id,
            'object_label' => $user->user_login,
            'user_id' => $user_id,
        ]);
    }

    public function on_login_failed(string $username): void
    {
        $user = get_user_by(is_email($username) ? 'email' : 'login', $username);

        $this->record('user_login_failed', [
            'object_type' => 'user',
            'object_id' => $user instanceof WP_User ? (int) $user->ID : 0,
            'object_label' => sanitize_user($username) !== '' ? sanitize_user($username) : '—',
            'user_id' => 0,
        ]);
    }

    public function on_user_created(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            return;
        }

        $this->record('user_created', [
            'object_type' => 'user',
            'object_id' => $user_id,
            'object_label' => $user->user_login,
            'details' => [[
                'label' => __('Role', 'kovalenko-admin-change-audit'),
                'before' => '',
                'after' => $this->role_names($user->roles),
            ]],
        ]);
    }

    public function on_user_deleted(int $user_id, ?int $reassign = null, ?WP_User $user = null): void
    {
        unset($reassign);
        $user = $user instanceof WP_User ? $user : get_userdata($user_id);

        $this->record('user_deleted', [
            'object_type' => 'user',
            'object_id' => $user_id,
            'object_label' => $user instanceof WP_User ? $user->user_login : sprintf('#%d', $user_id),
        ]);
    }

    /**
     * @param array<int, string> $old_roles
     */
    public function on_role_changed(int $user_id, string $role, array $old_roles): void
    {
        // A brand new user gets its first role here; that is part of "user created".
        if ($old_roles === [] || $old_roles === [$role]) {
            return;
        }

        $user = get_userdata($user_id);

        $this->record('user_role_changed', [
            'object_type' => 'user',
            'object_id' => $user_id,
            'object_label' => $user instanceof WP_User ? $user->user_login : sprintf('#%d', $user_id),
            'details' => [[
                'label' => __('Role', 'kovalenko-admin-change-audit'),
                'before' => $this->role_names($old_roles),
                'after' => $this->role_names($role !== '' ? [$role] : []),
            ]],
        ]);
    }

    public function on_profile_updated(int $user_id, WP_User $old_user): void
    {
        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            return;
        }

        if ($old_user->user_email !== $user->user_email) {
            $this->record('user_email_changed', [
                'object_type' => 'user',
                'object_id' => $user_id,
                'object_label' => $user->user_login,
                'details' => [[
                    'label' => __('Email', 'kovalenko-admin-change-audit'),
                    'before' => $old_user->user_email,
                    'after' => $user->user_email,
                ]],
            ]);
        }

        if ($old_user->user_pass !== $user->user_pass) {
            $this->record('user_password_changed', [
                'object_type' => 'user',
                'object_id' => $user_id,
                'object_label' => $user->user_login,
            ]);
        }
    }

    public function on_password_reset(WP_User $user): void
    {
        $this->record('user_password_changed', [
            'object_type' => 'user',
            'object_id' => (int) $user->ID,
            'object_label' => $user->user_login,
            'user_id' => (int) $user->ID,
        ]);
    }

    /* ---------------------------------------------------------------------
     * Plugins, themes and core
     * ------------------------------------------------------------------- */

    public function on_plugin_activated(string $plugin): void
    {
        $this->record('plugin_activated', $this->plugin_args($plugin));
    }

    public function on_plugin_deactivated(string $plugin): void
    {
        $this->record('plugin_deactivated', $this->plugin_args($plugin));
    }

    public function before_plugin_deleted(string $plugin): void
    {
        $this->names_before_delete['plugin:' . $plugin] = $this->plugin_args($plugin)['object_label'];
    }

    public function on_plugin_deleted(string $plugin, bool $deleted): void
    {
        if (! $deleted) {
            return;
        }

        $this->record('plugin_deleted', [
            'object_type' => 'plugin',
            'object_label' => $this->names_before_delete['plugin:' . $plugin] ?? $plugin,
        ]);
    }

    /**
     * Remembers the installed version of a plugin or theme just before it is replaced.
     *
     * @param mixed                $response
     * @param array<string, mixed> $hook_extra
     *
     * @return mixed
     */
    public function capture_version_before_update($response, $hook_extra)
    {
        if (! is_array($hook_extra)) {
            return $response;
        }

        if (! empty($hook_extra['plugin']) && is_string($hook_extra['plugin'])) {
            $this->versions_before['plugin:' . $hook_extra['plugin']] = $this->plugin_data($hook_extra['plugin'])['Version'];
        } elseif (! empty($hook_extra['theme']) && is_string($hook_extra['theme'])) {
            $this->versions_before['theme:' . $hook_extra['theme']] = (string) wp_get_theme($hook_extra['theme'])->get('Version');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function on_upgrader_complete(mixed $upgrader, array $options): void
    {
        $type = (string) ($options['type'] ?? '');
        $action = (string) ($options['action'] ?? '');

        if ($type === 'plugin' && $action === 'install' && is_object($upgrader)) {
            $data = property_exists($upgrader, 'new_plugin_data') && is_array($upgrader->new_plugin_data) ? $upgrader->new_plugin_data : [];
            $name = (string) ($data['Name'] ?? '');
            if ($name === '' && method_exists($upgrader, 'plugin_info')) {
                $file = $upgrader->plugin_info();
                $name = is_string($file) ? $this->plugin_data($file)['Name'] : '';
            }
            $this->record('plugin_installed', [
                'object_type' => 'plugin',
                'object_label' => $name !== '' ? $name : __('Unknown plugin', 'kovalenko-admin-change-audit'),
                'details' => $this->version_detail('', (string) ($data['Version'] ?? '')),
            ]);

            return;
        }

        if ($type === 'theme' && $action === 'install' && is_object($upgrader)) {
            $data = property_exists($upgrader, 'new_theme_data') && is_array($upgrader->new_theme_data) ? $upgrader->new_theme_data : [];
            $this->record('theme_installed', [
                'object_type' => 'theme',
                'object_label' => (string) ($data['Name'] ?? __('Unknown theme', 'kovalenko-admin-change-audit')),
                'details' => $this->version_detail('', (string) ($data['Version'] ?? '')),
            ]);

            return;
        }

        if ($action !== 'update') {
            return;
        }

        if ($type === 'plugin') {
            $plugins = isset($options['plugins']) ? (array) $options['plugins'] : (isset($options['plugin']) ? [$options['plugin']] : []);
            foreach ($plugins as $plugin) {
                $plugin = (string) $plugin;
                $data = $this->plugin_data($plugin);
                $this->record('plugin_updated', [
                    'object_type' => 'plugin',
                    'object_label' => $data['Name'],
                    'details' => $this->version_detail($this->versions_before['plugin:' . $plugin] ?? '', $data['Version']),
                ]);
            }
        } elseif ($type === 'theme') {
            $themes = isset($options['themes']) ? (array) $options['themes'] : (isset($options['theme']) ? [$options['theme']] : []);
            foreach ($themes as $stylesheet) {
                $theme = wp_get_theme((string) $stylesheet);
                $this->record('theme_updated', [
                    'object_type' => 'theme',
                    'object_label' => $theme->exists() ? (string) $theme->get('Name') : (string) $stylesheet,
                    'details' => $this->version_detail($this->versions_before['theme:' . $stylesheet] ?? '', (string) $theme->get('Version')),
                ]);
            }
        }
    }

    public function on_theme_switched(string $new_name, WP_Theme $new_theme, WP_Theme $old_theme): void
    {
        $this->record('theme_switched', [
            'object_type' => 'theme',
            'object_label' => $new_name,
            'details' => [[
                'label' => __('Theme', 'kovalenko-admin-change-audit'),
                'before' => (string) $old_theme->get('Name'),
                'after' => (string) $new_theme->get('Name'),
            ]],
        ]);
    }

    public function before_theme_deleted(string $stylesheet): void
    {
        $theme = wp_get_theme($stylesheet);
        $this->names_before_delete['theme:' . $stylesheet] = $theme->exists() ? (string) $theme->get('Name') : $stylesheet;
    }

    public function on_theme_deleted(string $stylesheet, bool $deleted): void
    {
        if (! $deleted) {
            return;
        }

        $this->record('theme_deleted', [
            'object_type' => 'theme',
            'object_label' => $this->names_before_delete['theme:' . $stylesheet] ?? $stylesheet,
        ]);
    }

    public function on_core_updated(string $new_version): void
    {
        $this->record('core_updated', [
            'object_type' => 'core',
            'object_label' => $new_version,
            'details' => $this->version_detail($this->boot_wp_version, $new_version),
        ]);
    }

    /* ---------------------------------------------------------------------
     * Site settings
     * ------------------------------------------------------------------- */

    /**
     * @return array<string, string>
     */
    public static function watched_options(): array
    {
        $options = [
            'blogname' => __('Site Title', 'kovalenko-admin-change-audit'),
            'blogdescription' => __('Tagline', 'kovalenko-admin-change-audit'),
            'siteurl' => __('WordPress Address (URL)', 'kovalenko-admin-change-audit'),
            'home' => __('Site Address (URL)', 'kovalenko-admin-change-audit'),
            'admin_email' => __('Administration Email Address', 'kovalenko-admin-change-audit'),
            'users_can_register' => __('Anyone can register', 'kovalenko-admin-change-audit'),
            'default_role' => __('New User Default Role', 'kovalenko-admin-change-audit'),
            'WPLANG' => __('Site Language', 'kovalenko-admin-change-audit'),
            'timezone_string' => __('Timezone', 'kovalenko-admin-change-audit'),
            'permalink_structure' => __('Permalink structure', 'kovalenko-admin-change-audit'),
            'blog_public' => __('Search engine visibility', 'kovalenko-admin-change-audit'),
            'show_on_front' => __('Your homepage displays', 'kovalenko-admin-change-audit'),
            'page_on_front' => __('Homepage', 'kovalenko-admin-change-audit'),
            'page_for_posts' => __('Posts page', 'kovalenko-admin-change-audit'),
            'default_comment_status' => __('Allow comments on new posts', 'kovalenko-admin-change-audit'),
        ];

        return (array) apply_filters('kovalenko_admin_change_audit_watched_options', $options);
    }

    public function on_option_updated(string $option, mixed $old_value, mixed $value): void
    {
        $watched = self::watched_options();
        if (! isset($watched[$option]) || wp_installing()) {
            return;
        }

        $before = $this->option_value_label($option, $old_value);
        $after = $this->option_value_label($option, $value);
        if ($before === $after) {
            return;
        }

        $this->record('option_updated', [
            'object_type' => 'option',
            'object_label' => (string) $watched[$option],
            'details' => [[
                'label' => (string) $watched[$option],
                'before' => $before,
                'after' => $after,
            ]],
        ]);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $args
     */
    private function record(string $event, array $args): void
    {
        $group = Kovalenko_Admin_Change_Audit_Events::group_of($event);
        if (! str_starts_with($event, 'changetrail_') && ! Kovalenko_Admin_Change_Audit_Settings::is_group_enabled($group)) {
            return;
        }

        kovalenko_admin_change_audit_record_event($event, $args);
    }

    /**
     * @param list<array{label: string, before: string, after: string}> $details
     */
    private function log_post(string $event, WP_Post $post, array $details = [], ?int $user_id = null): void
    {
        if ($event === 'post_created') {
            if (isset($this->created_in_request[(int) $post->ID])) {
                return;
            }
            $this->created_in_request[(int) $post->ID] = true;
        }

        $title = $post->post_title !== '' ? $post->post_title : __('(no title)', 'kovalenko-admin-change-audit');
        $type_object = get_post_type_object($post->post_type);
        $type_label = $type_object instanceof WP_Post_Type ? (string) $type_object->labels->singular_name : $post->post_type;

        $this->record($event, [
            'object_type' => 'post',
            'object_id' => (int) $post->ID,
            'object_label' => sprintf('%s “%s”', $type_label, $title),
            'details' => $details,
            'user_id' => $user_id,
        ]);
    }

    private function should_log_post(WP_Post $post): bool
    {
        if (
            $post->post_status === 'auto-draft'
            || $post->post_type === 'revision'
            || wp_is_post_revision($post) !== false
            || wp_is_post_autosave($post) !== false
        ) {
            return false;
        }

        $excluded = (array) Kovalenko_Admin_Change_Audit_Settings::get('excluded_post_types');

        return ! in_array($post->post_type, $excluded, true);
    }

    /** True for logged-in users and WP-CLI; false for anonymous front-end requests. */
    private function is_person_acting(): bool
    {
        return is_user_logged_in() || (defined('WP_CLI') && WP_CLI);
    }

    private function buffer_meta_change(int $post_id, string $meta_key, mixed $before, mixed $after): void
    {
        if ($this->inserting_new_post || isset($this->created_in_request[$post_id]) || ! $this->is_person_acting() || ! $this->should_log_meta_key($meta_key)) {
            return;
        }

        $post = get_post($post_id);
        if (! $post instanceof WP_Post || ! $this->should_log_post($post)) {
            return;
        }

        $before_value = $this->meta_value_label($meta_key, $before);
        $after_value = $this->meta_value_label($meta_key, $after);

        if (! isset($this->pending_meta[$post_id][$meta_key]) && $before_value === $after_value) {
            return;
        }

        if (! isset($this->pending_meta[$post_id])) {
            $this->pending_meta[$post_id] = [];
            $this->pending_meta_actor[$post_id] = get_current_user_id();
        }

        $this->pending_meta[$post_id][$meta_key] = [
            'before' => $this->pending_meta[$post_id][$meta_key]['before'] ?? $before_value,
            'after' => $after_value,
        ];

        if ($this->pending_meta[$post_id][$meta_key]['before'] === $after_value) {
            unset($this->pending_meta[$post_id][$meta_key]);
        }
    }

    private function should_log_meta_key(string $meta_key): bool
    {
        if (in_array($meta_key, self::IGNORED_META_KEYS, true) || str_starts_with($meta_key, '_oembed_')) {
            return false;
        }

        foreach ((array) Kovalenko_Admin_Change_Audit_Settings::get('excluded_meta_keys') as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $meta_key || (str_ends_with($pattern, '*') && str_starts_with($meta_key, rtrim($pattern, '*')))) {
                return false;
            }
        }

        return true;
    }

    private function meta_label(string $meta_key): string
    {
        if (isset(self::META_KEY_LABELS[$meta_key])) {
            return self::META_KEY_LABELS[$meta_key];
        }

        $label = trim(str_replace(['_', '-'], ' ', ltrim($meta_key, '_')));

        return $label !== '' ? ucwords($label) : $meta_key;
    }

    private function meta_value_label(string $meta_key, mixed $value): string
    {
        if ($value === '' || $value === null || $value === false) {
            return '';
        }

        if (preg_match(self::SECRET_META_PATTERN, $meta_key) === 1) {
            return __('(hidden)', 'kovalenko-admin-change-audit');
        }

        if ($meta_key === '_thumbnail_id') {
            $attachment_id = (int) $value;
            $title = $attachment_id > 0 ? get_the_title($attachment_id) : '';

            return $attachment_id > 0 ? ($title !== '' ? sprintf('%s (#%d)', $title, $attachment_id) : sprintf('#%d', $attachment_id)) : '';
        }

        if (is_array($value) || is_object($value)) {
            $value = (string) wp_json_encode($value);
        }

        return kovalenko_admin_change_audit_truncate((string) $value);
    }

    /**
     * @param array<int, int|string> $tt_ids
     *
     * @return list<string>
     */
    private function term_names(array $tt_ids, string $taxonomy): array
    {
        $names = [];
        foreach ($tt_ids as $tt_id) {
            $term = get_term_by('term_taxonomy_id', (int) $tt_id, $taxonomy);
            if ($term instanceof WP_Term) {
                $names[] = $term->name;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @param array<int, string> $roles
     */
    private function role_names(array $roles): string
    {
        $names = wp_roles()->get_names();

        return implode(', ', array_map(
            static fn (string $role): string => isset($names[$role]) ? translate_user_role($names[$role]) : $role,
            array_values($roles)
        ));
    }

    /**
     * @return array{Name: string, Version: string}
     */
    private function plugin_data(string $plugin): array
    {
        $file = WP_PLUGIN_DIR . '/' . $plugin;
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = is_readable($file) ? get_plugin_data($file, false, false) : [];

        return [
            'Name' => (string) ($data['Name'] ?? '') !== '' ? (string) $data['Name'] : $plugin,
            'Version' => (string) ($data['Version'] ?? ''),
        ];
    }

    /**
     * @return array{object_type: string, object_label: string}
     */
    private function plugin_args(string $plugin): array
    {
        return [
            'object_type' => 'plugin',
            'object_label' => $this->plugin_data($plugin)['Name'],
        ];
    }

    /**
     * @return list<array{label: string, before: string, after: string}>
     */
    private function version_detail(string $before, string $after): array
    {
        if ($before === '' && $after === '') {
            return [];
        }

        return [[
            'label' => __('Version', 'kovalenko-admin-change-audit'),
            'before' => $before,
            'after' => $after,
        ]];
    }

    private function option_value_label(string $option, mixed $value): string
    {
        if (in_array($option, ['page_on_front', 'page_for_posts'], true)) {
            $page_id = (int) $value;

            return $page_id > 0 ? sprintf('%s (#%d)', get_the_title($page_id), $page_id) : '';
        }

        if (in_array($option, ['users_can_register', 'blog_public'], true)) {
            return (string) (int) $value === '1' ? __('Yes', 'kovalenko-admin-change-audit') : __('No', 'kovalenko-admin-change-audit');
        }

        if (is_array($value) || is_object($value)) {
            return kovalenko_admin_change_audit_truncate((string) wp_json_encode($value));
        }

        return kovalenko_admin_change_audit_truncate((string) $value);
    }

    /**
     * @return list<array{label: string, before: string, after: string}>
     */
    private function detect_post_changes(WP_Post $before, WP_Post $after): array
    {
        $fields = [
            'post_title' => [__('Title', 'kovalenko-admin-change-audit'), null],
            'post_content' => [__('Content', 'kovalenko-admin-change-audit'), 'content'],
            'post_excerpt' => [__('Excerpt', 'kovalenko-admin-change-audit'), 'content'],
            'post_status' => [__('Status', 'kovalenko-admin-change-audit'), 'status'],
            'post_name' => [__('Slug', 'kovalenko-admin-change-audit'), null],
            'post_date' => [__('Publish date', 'kovalenko-admin-change-audit'), null],
            'post_password' => [__('Password protection', 'kovalenko-admin-change-audit'), 'secret'],
            'menu_order' => [__('Order', 'kovalenko-admin-change-audit'), null],
            'post_parent' => [__('Parent', 'kovalenko-admin-change-audit'), 'post'],
            'post_author' => [__('Author', 'kovalenko-admin-change-audit'), 'user'],
            'comment_status' => [__('Comments', 'kovalenko-admin-change-audit'), null],
        ];

        $details = [];
        foreach ($fields as $field => [$label, $kind]) {
            $old = (string) ($before->{$field} ?? '');
            $new = (string) ($after->{$field} ?? '');

            // A draft's post_date follows "now" until it is published; that is not a real change.
            if ($field === 'post_date' && $before->post_status !== 'publish' && $before->post_status !== 'future') {
                continue;
            }

            if ($old === $new) {
                continue;
            }

            $details[] = [
                'label' => $label,
                'before' => $this->field_value($kind, $old),
                'after' => $this->field_value($kind, $new),
            ];
        }

        return $details;
    }

    private function field_value(?string $kind, string $value): string
    {
        switch ($kind) {
            case 'content':
                $plain = trim(wp_strip_all_tags($value, false));
                $plain = (string) preg_replace("/\n{3,}/", "\n\n", $plain !== '' ? $plain : $value);

                return kovalenko_admin_change_audit_truncate($plain);
            case 'status':
                $status = get_post_status_object($value);

                return $status instanceof stdClass && isset($status->label) ? (string) $status->label : $value;
            case 'secret':
                return $value !== '' ? __('On', 'kovalenko-admin-change-audit') : __('Off', 'kovalenko-admin-change-audit');
            case 'post':
                $post_id = (int) $value;

                return $post_id > 0 ? sprintf('%s (#%d)', get_the_title($post_id), $post_id) : '';
            case 'user':
                $user = get_userdata((int) $value);

                return $user instanceof WP_User ? $user->user_login : $value;
            default:
                return kovalenko_admin_change_audit_truncate($value);
        }
    }
}
