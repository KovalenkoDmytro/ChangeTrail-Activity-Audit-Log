<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Device identification for shared accounts: a persistent random device cookie, a short hash of the login
 * session, the parsed user agent and an optional browser fingerprint, plus a registry of known devices.
 */
final class Kovalenko_Admin_Change_Audit_Devices
{
    public const COOKIE = 'kovalenko_admin_change_audit_device';
    public const AJAX_ACTION = 'kovalenko_admin_change_audit_fingerprint';

    /** Registry rows are refreshed at most this often for the same device and user. */
    private const TOUCH_INTERVAL = 10 * MINUTE_IN_SECONDS;

    /** A stored fingerprint is re-checked by the browser at most this often. */
    private const FINGERPRINT_INTERVAL = DAY_IN_SECONDS;

    private static string $request_device_id = '';
    private static string $login_token = '';

    /** @var array<string, object|null> */
    private static array $cache = [];

    public function register_hooks(): void
    {
        add_action('login_init', [$this, 'ensure_cookie']);
        add_action('admin_init', [$this, 'on_admin_init'], 5);
        add_action('set_logged_in_cookie', [$this, 'capture_login_token'], 10, 6);
        // After the "logged in" entry has been written, so "New device" follows it in the log.
        add_action('wp_login', [$this, 'on_login'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_fingerprint_script']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_fingerprint']);
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'changetrail_devices';
    }

    public static function install_table(): void
    {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            device_id varchar(32) NOT NULL,
            label varchar(100) NOT NULL DEFAULT '',
            fingerprint varchar(64) NOT NULL DEFAULT '',
            fingerprint_data text NULL,
            fingerprint_updated datetime NULL,
            same_as varchar(32) NOT NULL DEFAULT '',
            users text NULL,
            first_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_user_agent varchar(255) NOT NULL DEFAULT '',
            last_ip varchar(45) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY device_id (device_id),
            KEY fingerprint (fingerprint),
            KEY last_seen (last_seen)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ---------------------------------------------------------------------
     * Request identity
     * ------------------------------------------------------------------- */

    public static function is_valid_id(string $device_id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $device_id) === 1;
    }

    /**
     * Device ID of the current browser. With $create, a new ID is issued (and the cookie set) when missing.
     * Never issued for WP-CLI or cron, which are not browsers.
     */
    public static function current_id(bool $create = false): string
    {
        if (self::$request_device_id !== '') {
            return self::$request_device_id;
        }

        $cookie = isset($_COOKIE[self::COOKIE]) ? sanitize_key(wp_unslash($_COOKIE[self::COOKIE])) : '';
        if (self::is_valid_id($cookie)) {
            self::$request_device_id = $cookie;

            return $cookie;
        }

        if (! $create || (defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
            return '';
        }

        self::$request_device_id = bin2hex(random_bytes(16));
        self::send_cookie(self::$request_device_id);

        return self::$request_device_id;
    }

    private static function send_cookie(string $device_id): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $device_id, [
            'expires' => time() + 2 * YEAR_IN_SECONDS,
            'path' => COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => (string) COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function ensure_cookie(): void
    {
        self::current_id(true);
    }

    /**
     * @param string $logged_in_cookie
     * @param int    $expire
     * @param int    $expiration
     * @param int    $user_id
     * @param string $scheme
     * @param string $token
     */
    public function capture_login_token($logged_in_cookie, $expire, $expiration, $user_id, $scheme, $token = ''): void
    {
        unset($logged_in_cookie, $expire, $expiration, $user_id, $scheme);
        self::$login_token = is_string($token) ? $token : '';
    }

    /** Short, non-reversible hash of the WordPress login session token. The token itself is never stored. */
    public static function session_hash(): string
    {
        $token = self::$login_token !== '' ? self::$login_token : (is_user_logged_in() ? wp_get_session_token() : '');
        if ($token === '') {
            // During logout the session is destroyed before the event fires, but the request still carries its cookie.
            $cookie = wp_parse_auth_cookie('', 'logged_in');
            $token = is_array($cookie) ? (string) ($cookie['token'] ?? '') : '';
        }

        return $token !== '' ? substr(hash('sha256', $token), 0, 12) : '';
    }

    public static function user_agent(): string
    {
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])) : '';

        return substr($agent, 0, 255);
    }

    public static function code(string $device_id): string
    {
        return $device_id !== '' ? '#' . strtoupper(substr($device_id, 0, 4)) : '';
    }

    /**
     * Lightweight user agent parser: browser with major version, and operating system.
     *
     * @return array{browser: string, os: string}
     */
    public static function parse_user_agent(string $agent): array
    {
        $browser = '';
        $patterns = [
            'Edge' => '/Edg(?:e|A|iOS)?\/(\d+)/',
            'Opera' => '/(?:OPR|Opera)\/(\d+)/',
            'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
            'Vivaldi' => '/Vivaldi\/(\d+)/',
            'Yandex' => '/YaBrowser\/(\d+)/',
            'Firefox' => '/(?:Firefox|FxiOS)\/(\d+)/',
            'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/',
            'Safari' => '/Version\/(\d+(?:\.\d+)?).*Safari\//',
            'WP-CLI' => '/WP[ -]CLI\/?\s*([\d.]+)?/i',
            'curl' => '/curl\/(\d+)/',
        ];
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $agent, $matches) === 1) {
                $browser = trim($name . ' ' . ($matches[1] ?? ''));
                break;
            }
        }

        $os = '';
        if (preg_match('/Windows NT (\d+\.\d+)/', $agent, $matches) === 1) {
            $versions = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
            $os = 'Windows ' . ($versions[$matches[1]] ?? $matches[1]);
        } elseif (preg_match('/(iPhone|iPad|iPod).*? OS (\d+)[_.](\d+)/', $agent, $matches) === 1) {
            $os = ($matches[1] === 'iPad' ? 'iPadOS ' : 'iOS ') . $matches[2] . '.' . $matches[3];
        } elseif (preg_match('/Android (\d+(?:\.\d+)?)/', $agent, $matches) === 1) {
            $os = 'Android ' . $matches[1];
            if (preg_match('/Android [^;)]*;\s*([^;)]+?)(?: Build\/|\))/', $agent, $model) === 1 && strtoupper(trim($model[1])) !== 'K') {
                $os .= ' (' . trim($model[1]) . ')';
            }
        } elseif (str_contains($agent, 'CrOS')) {
            $os = 'ChromeOS';
        } elseif (str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X')) {
            $os = 'macOS';
        } elseif (str_contains($agent, 'Linux')) {
            $os = 'Linux';
        }

        return ['browser' => $browser, 'os' => $os];
    }

    public static function describe_user_agent(string $agent): string
    {
        $parsed = self::parse_user_agent($agent);
        $parts = array_filter([$parsed['browser'], $parsed['os']]);

        return $parts !== [] ? implode(' · ', $parts) : ($agent !== '' ? __('Unknown browser', 'kovalenko-admin-change-audit') : '');
    }

    /* ---------------------------------------------------------------------
     * Registry
     * ------------------------------------------------------------------- */

    public static function get(string $device_id): ?object
    {
        global $wpdb;

        if (! self::is_valid_id($device_id)) {
            return null;
        }

        if (! array_key_exists($device_id, self::$cache)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; cached per request above.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE device_id = %s', self::table(), $device_id));
            self::$cache[$device_id] = is_object($row) ? $row : null;
        }

        return self::$cache[$device_id];
    }

    /**
     * @param list<string> $device_ids
     *
     * @return array<string, object>
     */
    public static function get_many(array $device_ids): array
    {
        global $wpdb;

        $device_ids = array_values(array_unique(array_filter($device_ids, [self::class, 'is_valid_id'])));
        $missing = array_values(array_filter($device_ids, static fn (string $id): bool => ! array_key_exists($id, self::$cache)));

        if ($missing !== []) {
            $placeholders = implode(',', array_fill(0, count($missing), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a list of %s placeholders.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE device_id IN ({$placeholders})", self::table(), ...$missing));
            foreach ($missing as $id) {
                self::$cache[$id] = null;
            }
            foreach ((array) $rows as $row) {
                self::$cache[(string) $row->device_id] = $row;
            }
        }

        $found = [];
        foreach ($device_ids as $id) {
            if (self::$cache[$id] instanceof stdClass) {
                $found[$id] = self::$cache[$id];
            }
        }

        return $found;
    }

    /**
     * @return list<object>
     */
    public static function all(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY last_seen DESC LIMIT 500', self::table()));

        return is_array($rows) ? $rows : [];
    }

    public static function label(?object $device, string $device_id = ''): string
    {
        if ($device === null) {
            return self::code($device_id);
        }

        $code = self::code((string) $device->device_id);

        return (string) $device->label !== '' ? sprintf('%s (%s)', $device->label, $code) : $code;
    }

    /**
     * @return list<int>
     */
    public static function user_ids(?object $device): array
    {
        if ($device === null || empty($device->users)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $device->users))));
    }

    /**
     * Records that $user_id uses the current device, and logs "New device" the first time.
     */
    public static function touch(int $user_id, string $device_id = ''): void
    {
        global $wpdb;

        $device_id = $device_id !== '' ? $device_id : self::current_id(true);
        if ($user_id <= 0 || ! self::is_valid_id($device_id)) {
            return;
        }

        $now = current_time('mysql', true);
        $agent = self::user_agent();
        $ip = kovalenko_admin_change_audit_client_ip();
        $device = self::get($device_id);

        if ($device === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
            $wpdb->insert(
                self::table(),
                [
                    'device_id' => $device_id,
                    'users' => ',' . $user_id . ',',
                    'first_seen' => $now,
                    'last_seen' => $now,
                    'last_user_agent' => $agent,
                    'last_ip' => $ip,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s']
            );
            unset(self::$cache[$device_id]);
            self::log_new_device($user_id, $device_id, $agent);

            return;
        }

        $users = self::user_ids($device);
        $is_new_for_user = ! in_array($user_id, $users, true);
        $stale = strtotime((string) $device->last_seen . ' UTC') < time() - self::TOUCH_INTERVAL;

        if (! $is_new_for_user && ! $stale && (string) $device->last_user_agent === $agent && (string) $device->last_ip === $ip) {
            return;
        }

        if ($is_new_for_user) {
            $users[] = $user_id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
        $wpdb->update(
            self::table(),
            [
                'users' => ',' . implode(',', $users) . ',',
                'last_seen' => $now,
                'last_user_agent' => $agent,
                'last_ip' => $ip,
            ],
            ['device_id' => $device_id],
            ['%s', '%s', '%s', '%s'],
            ['%s']
        );
        unset(self::$cache[$device_id]);

        if ($is_new_for_user) {
            self::log_new_device($user_id, $device_id, $agent);
        }
    }

    private static function log_new_device(int $user_id, string $device_id, string $agent): void
    {
        $parsed = self::parse_user_agent($agent);
        $details = [];
        if ($parsed['browser'] !== '') {
            $details[] = ['label' => __('Browser', 'kovalenko-admin-change-audit'), 'before' => '', 'after' => $parsed['browser']];
        }
        if ($parsed['os'] !== '') {
            $details[] = ['label' => __('Operating system', 'kovalenko-admin-change-audit'), 'before' => '', 'after' => $parsed['os']];
        }

        kovalenko_admin_change_audit_record_event('device_new', [
            'object_type' => 'device',
            'object_label' => self::code($device_id),
            'details' => $details,
            'user_id' => $user_id,
            'device_id' => $device_id,
        ]);
    }

    public function on_admin_init(): void
    {
        if (! is_user_logged_in() || wp_doing_ajax()) {
            return;
        }

        self::touch(get_current_user_id());
    }

    public function on_login(string $user_login, WP_User $user): void
    {
        unset($user_login);
        self::touch((int) $user->ID);
    }

    public static function rename(string $device_id, string $label): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
        $updated = $wpdb->update(self::table(), ['label' => substr($label, 0, 100)], ['device_id' => $device_id], ['%s'], ['%s']);
        unset(self::$cache[$device_id]);

        return is_int($updated) && $updated > 0;
    }

    public static function forget(string $device_id): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
        $wpdb->delete(self::table(), ['device_id' => $device_id], ['%s']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
        $wpdb->update(self::table(), ['same_as' => ''], ['same_as' => $device_id], ['%s'], ['%s']);
        unset(self::$cache[$device_id]);
    }

    /* ---------------------------------------------------------------------
     * Fingerprint
     * ------------------------------------------------------------------- */

    public static function fingerprinting_enabled(): bool
    {
        return (bool) Kovalenko_Admin_Change_Audit_Settings::get('fingerprinting');
    }

    public function enqueue_fingerprint_script(): void
    {
        if (! self::fingerprinting_enabled() || ! is_user_logged_in()) {
            return;
        }

        $device_id = self::current_id();
        $device = self::get($device_id);
        if ($device === null) {
            return;
        }

        $checked = empty($device->fingerprint_updated) ? 0 : (int) strtotime((string) $device->fingerprint_updated . ' UTC');
        if ((string) $device->fingerprint !== '' && $checked > time() - self::FINGERPRINT_INTERVAL) {
            return;
        }

        $plugin_file = dirname(__DIR__) . '/activity-logger-site-owners.php';
        wp_enqueue_script(
            'kovalenko-admin-change-audit-device',
            plugins_url('assets/device.js', $plugin_file),
            [],
            (string) filemtime(dirname(__DIR__) . '/assets/device.js'),
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_add_inline_script(
            'kovalenko-admin-change-audit-device',
            'window.kovalenkoAdminChangeAuditDevice = ' . wp_json_encode([
                'url' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
                'nonce' => wp_create_nonce(self::AJAX_ACTION),
                'session' => self::session_hash(),
            ]) . ';',
            'before'
        );
    }

    /**
     * Allowed fingerprint components and their maximum lengths.
     *
     * @return array<string, int>
     */
    private static function component_limits(): array
    {
        return [
            'screen' => 20,
            'pixelRatio' => 8,
            'colorDepth' => 4,
            'timezone' => 64,
            'language' => 35,
            'languages' => 120,
            'cores' => 4,
            'memory' => 6,
            'webgl' => 200,
            'touch' => 4,
            'platform' => 50,
        ];
    }

    public function handle_fingerprint(): void
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (! is_user_logged_in() || ! self::fingerprinting_enabled()) {
            wp_send_json_error(null, 403);
        }

        $device_id = self::current_id();
        if ($device_id === '') {
            wp_send_json_error(null, 400);
        }

        $raw = isset($_POST['fp']) ? json_decode(sanitize_textarea_field(wp_unslash((string) $_POST['fp'])), true) : null;
        if (! is_array($raw)) {
            wp_send_json_error(null, 400);
        }

        $components = [];
        foreach (self::component_limits() as $key => $max) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $components[$key] = substr(sanitize_text_field((string) $raw[$key]), 0, $max);
            }
        }
        if (count($components) < 3) {
            wp_send_json_error(null, 400);
        }
        ksort($components);

        self::store_fingerprint(get_current_user_id(), $device_id, $components);
        wp_send_json_success();
    }

    /**
     * @param array<string, string> $components
     */
    public static function store_fingerprint(int $user_id, string $device_id, array $components): void
    {
        global $wpdb;

        $hash = hash('sha256', (string) wp_json_encode($components));
        $device = self::get($device_id);
        if ($device === null) {
            self::touch($user_id, $device_id);
            $device = self::get($device_id);
            if ($device === null) {
                return;
            }
        }

        $data = [
            'fingerprint' => $hash,
            'fingerprint_data' => (string) wp_json_encode($components),
            'fingerprint_updated' => current_time('mysql', true),
        ];

        $match = null;
        if ((string) $device->fingerprint !== $hash && (string) $device->same_as === '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
            $match = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM %i WHERE fingerprint = %s AND device_id <> %s ORDER BY last_seen DESC LIMIT 1',
                self::table(),
                $hash,
                $device_id
            ));
            if (is_object($match)) {
                $data['same_as'] = (string) $match->device_id;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
        $wpdb->update(self::table(), $data, ['device_id' => $device_id], array_fill(0, count($data), '%s'), ['%s']);
        unset(self::$cache[$device_id]);

        if (is_object($match)) {
            kovalenko_admin_change_audit_record_event('device_matched', [
                'object_type' => 'device',
                'object_label' => self::code($device_id),
                'user_id' => $user_id,
                'device_id' => $device_id,
                'summary_second' => self::label($match),
                'details' => [[
                    'label' => __('Probably the same as', 'kovalenko-admin-change-audit'),
                    'before' => '',
                    'after' => self::label($match),
                ]],
            ]);
        }
    }

    /**
     * Name of the known device a "Device recognized" entry matched, for display: the current name from the device
     * list (so later renames show up), else the name saved in the entry's details, else a generic phrase.
     */
    public static function matched_label_for_entry(object $item): string
    {
        $device = self::get((string) ($item->device_id ?? ''));
        if ($device !== null && (string) $device->same_as !== '') {
            return self::label(self::get((string) $device->same_as), (string) $device->same_as);
        }

        $details = kovalenko_admin_change_audit_decode_details(is_string($item->details ?? null) ? $item->details : null);
        if ($details !== [] && $details[0]['after'] !== '') {
            return $details[0]['after'];
        }

        return __('a known device', 'kovalenko-admin-change-audit');
    }

    /**
     * Short human summary of a stored fingerprint, e.g. "1512×982 · Apple M2 · 8 cores".
     */
    public static function fingerprint_summary(?object $device): string
    {
        if ($device === null || empty($device->fingerprint_data)) {
            return '';
        }

        $data = json_decode((string) $device->fingerprint_data, true);
        if (! is_array($data)) {
            return '';
        }

        $parts = [];
        if (! empty($data['screen'])) {
            $parts[] = (string) $data['screen'];
        }
        if (! empty($data['webgl'])) {
            $parts[] = self::short_renderer((string) $data['webgl']);
        }
        if (! empty($data['cores'])) {
            /* translators: %s: number of CPU cores */
            $parts[] = sprintf(__('%s cores', 'kovalenko-admin-change-audit'), (string) $data['cores']);
        }
        if (! empty($data['timezone'])) {
            $parts[] = (string) $data['timezone'];
        }

        return implode(' · ', $parts);
    }

    /**
     * "ANGLE (Intel, Intel(R) UHD Graphics 630 Direct3D11 vs_5_0 ps_5_0, D3D11)" becomes "Intel(R) UHD Graphics 630".
     */
    private static function short_renderer(string $renderer): string
    {
        if (preg_match('/^ANGLE \((?:[^,]+,\s*)?(.+?)(?:,\s*[^,]*)?\)$/', $renderer, $matches) === 1) {
            $renderer = $matches[1];
        }
        $renderer = (string) preg_replace(['/^ANGLE Metal Renderer:\s*/', '/\s+(?:Direct3D|OpenGL|vs_|\(0x).*$/'], '', $renderer);

        return trim($renderer);
    }

    /**
     * @return array<string, string>
     */
    public static function fingerprint_components(?object $device): array
    {
        $data = $device !== null && ! empty($device->fingerprint_data) ? json_decode((string) $device->fingerprint_data, true) : [];

        return is_array($data) ? array_map('strval', $data) : [];
    }
}
