<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads and sanitizes the plugin settings (stored as one array option) and the timezone option.
 */
final class Kovalenko_Admin_Change_Audit_Settings
{
    public const OPTION = 'kovalenko_admin_change_audit_settings';
    public const OPTION_TIMEZONE = 'kovalenko_admin_change_audit_timezone';

    public const RETENTION_CHOICES = [7, 30, 90, 180, 365, 0];
    public const UNLOCK_CHOICES = [1, 4, 12, 24];

    public const DEFAULT_EXCLUDED_POST_TYPES = [
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'nav_menu_item',
        'wp_font_face',
        'wp_font_family',
    ];

    public const DEFAULT_EXCLUDED_META_KEYS = [
        '_elementor_*',
        '_wpb_*',
        '_et_pb_*',
        '_fl_builder_*',
        'post_views_count',
        'views',
        '_yoast_wpseo_content_score',
        '_yoast_wpseo_estimated-reading-time-minutes',
        'rank_math_seo_score',
        '_last_editor_used_jetpack',
    ];

    /**
     * @return array{retention_days: int, admin_bar: bool, groups: list<string>, excluded_post_types: list<string>, excluded_meta_keys: list<string>, unlock_hours: int, keep_data: bool, fingerprinting: bool}
     */
    public static function defaults(): array
    {
        return [
            'retention_days' => 30,
            'admin_bar' => false,
            'groups' => array_keys(Kovalenko_Admin_Change_Audit_Events::groups()),
            'excluded_post_types' => self::DEFAULT_EXCLUDED_POST_TYPES,
            'excluded_meta_keys' => self::DEFAULT_EXCLUDED_META_KEYS,
            'unlock_hours' => 12,
            'keep_data' => false,
            'fingerprinting' => true,
        ];
    }

    /**
     * @return array{retention_days: int, admin_bar: bool, groups: list<string>, excluded_post_types: list<string>, excluded_meta_keys: list<string>, unlock_hours: int, keep_data: bool, fingerprinting: bool}
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return self::sanitize(is_array($stored) ? array_merge(self::defaults(), $stored) : self::defaults());
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{retention_days: int, admin_bar: bool, groups: list<string>, excluded_post_types: list<string>, excluded_meta_keys: list<string>, unlock_hours: int, keep_data: bool, fingerprinting: bool}
     */
    public static function sanitize(array $input): array
    {
        $retention = (int) ($input['retention_days'] ?? 30);
        if (! in_array($retention, self::RETENTION_CHOICES, true)) {
            $retention = 30;
        }

        $unlock_hours = (int) ($input['unlock_hours'] ?? 12);
        if (! in_array($unlock_hours, self::UNLOCK_CHOICES, true)) {
            $unlock_hours = 12;
        }

        $groups = array_values(array_intersect(
            array_map('strval', (array) ($input['groups'] ?? [])),
            array_keys(Kovalenko_Admin_Change_Audit_Events::groups())
        ));

        $post_types = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => sanitize_key((string) $value),
            (array) ($input['excluded_post_types'] ?? [])
        ))));

        $meta_keys = $input['excluded_meta_keys'] ?? [];
        if (is_string($meta_keys)) {
            $meta_keys = preg_split('/[\r\n,]+/', $meta_keys) ?: [];
        }
        $meta_keys = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => preg_replace('/[^A-Za-z0-9_\-\*]/', '', trim((string) $value)) ?? '',
            (array) $meta_keys
        ))));

        return [
            'retention_days' => $retention,
            'admin_bar' => (bool) ($input['admin_bar'] ?? false),
            'groups' => $groups,
            'excluded_post_types' => $post_types,
            'excluded_meta_keys' => $meta_keys,
            'unlock_hours' => $unlock_hours,
            'keep_data' => (bool) ($input['keep_data'] ?? false),
            'fingerprinting' => (bool) ($input['fingerprinting'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function save(array $settings): void
    {
        update_option(self::OPTION, self::sanitize($settings), false);
    }

    public static function is_group_enabled(string $group): bool
    {
        return in_array($group, self::get('groups'), true);
    }

    public static function retention_days(): int
    {
        return max(0, (int) apply_filters('kovalenko_admin_change_audit_retention_days', (int) self::get('retention_days')));
    }

    /** Empty string means "use the site timezone". */
    public static function stored_timezone(): string
    {
        $timezone = (string) get_option(self::OPTION_TIMEZONE, '');

        return self::is_valid_timezone($timezone) ? $timezone : '';
    }

    public static function is_valid_timezone(string $timezone): bool
    {
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true);
    }

    public static function timezone(): DateTimeZone
    {
        $stored = self::stored_timezone();

        return $stored !== '' ? new DateTimeZone($stored) : wp_timezone();
    }

    public static function timezone_name(): string
    {
        $stored = self::stored_timezone();

        return $stored !== '' ? $stored : wp_timezone_string();
    }
}
