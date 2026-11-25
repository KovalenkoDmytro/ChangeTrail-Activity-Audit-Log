<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registry of every event type ChangeTrail records: its group, icon and translatable summary.
 */
final class Kovalenko_Admin_Change_Audit_Events
{
    public const GROUP_CONTENT = 'content';
    public const GROUP_USERS = 'users';
    public const GROUP_EXTENSIONS = 'extensions';
    public const GROUP_SETTINGS = 'settings';

    /** Rows written before 3.0 that could not be mapped to a structured event. */
    public const LEGACY = 'legacy';

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function groups(): array
    {
        return [
            self::GROUP_CONTENT => [
                'label' => __('Content', 'kovalenko-admin-change-audit'),
                'icon' => 'dashicons-admin-post',
            ],
            self::GROUP_USERS => [
                'label' => __('Users & logins', 'kovalenko-admin-change-audit'),
                'icon' => 'dashicons-admin-users',
            ],
            self::GROUP_EXTENSIONS => [
                'label' => __('Plugins & themes', 'kovalenko-admin-change-audit'),
                'icon' => 'dashicons-admin-plugins',
            ],
            self::GROUP_SETTINGS => [
                'label' => __('Settings', 'kovalenko-admin-change-audit'),
                'icon' => 'dashicons-admin-settings',
            ],
        ];
    }

    /**
     * Event definitions. "summary" takes the object label as %s.
     *
     * @return array<string, array{group: string, name: string, summary: string}>
     */
    public static function all(): array
    {
        static $events = null;
        if (is_array($events)) {
            return $events;
        }

        $events = [
            /* translators: %s: post title */
            'post_created' => [self::GROUP_CONTENT, __('Post created', 'kovalenko-admin-change-audit'), __('Created %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: post title */
            'post_updated' => [self::GROUP_CONTENT, __('Post updated', 'kovalenko-admin-change-audit'), __('Updated %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: post title */
            'post_meta_updated' => [self::GROUP_CONTENT, __('Custom fields updated', 'kovalenko-admin-change-audit'), __('Updated custom fields of %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: post title */
            'post_terms_updated' => [self::GROUP_CONTENT, __('Categories or tags changed', 'kovalenko-admin-change-audit'), __('Changed categories or tags of %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: post title */
            'post_trashed' => [self::GROUP_CONTENT, __('Post trashed', 'kovalenko-admin-change-audit'), __('Moved %s to the trash', 'kovalenko-admin-change-audit')],
            /* translators: %s: post title */
            'post_restored' => [self::GROUP_CONTENT, __('Post restored', 'kovalenko-admin-change-audit'), __('Restored %s from the trash', 'kovalenko-admin-change-audit')],
            /* translators: %s: post title */
            'post_deleted' => [self::GROUP_CONTENT, __('Post deleted', 'kovalenko-admin-change-audit'), __('Permanently deleted %s', 'kovalenko-admin-change-audit')],

            /* translators: %s: username */
            'user_login' => [self::GROUP_USERS, __('Logged in', 'kovalenko-admin-change-audit'), __('%s logged in', 'kovalenko-admin-change-audit')],
            /* translators: %s: username */
            'user_logout' => [self::GROUP_USERS, __('Logged out', 'kovalenko-admin-change-audit'), __('%s logged out', 'kovalenko-admin-change-audit')],
            /* translators: %s: username that was tried */
            'user_login_failed' => [self::GROUP_USERS, __('Failed login', 'kovalenko-admin-change-audit'), __('Failed login attempt for %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: device code, e.g. #A3F2 */
            'device_new' => [self::GROUP_USERS, __('New device', 'kovalenko-admin-change-audit'), __('Used a new device %s', 'kovalenko-admin-change-audit')],
            /* translators: 1: code of the new device, e.g. #A3F2; 2: name and code of the known device it matches, e.g. Reception PC (#9705) */
            'device_matched' => [self::GROUP_USERS, __('Device recognized', 'kovalenko-admin-change-audit'), __('New device %1$s looks like %2$s', 'kovalenko-admin-change-audit')],
            /* translators: %s: username */
            'user_created' => [self::GROUP_USERS, __('User created', 'kovalenko-admin-change-audit'), __('Created user %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: username */
            'user_deleted' => [self::GROUP_USERS, __('User deleted', 'kovalenko-admin-change-audit'), __('Deleted user %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: username */
            'user_role_changed' => [self::GROUP_USERS, __('Role changed', 'kovalenko-admin-change-audit'), __('Changed the role of %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: username */
            'user_email_changed' => [self::GROUP_USERS, __('Email changed', 'kovalenko-admin-change-audit'), __('Changed the email address of %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: username */
            'user_password_changed' => [self::GROUP_USERS, __('Password changed', 'kovalenko-admin-change-audit'), __('Changed the password of %s', 'kovalenko-admin-change-audit')],

            /* translators: %s: plugin name */
            'plugin_installed' => [self::GROUP_EXTENSIONS, __('Plugin installed', 'kovalenko-admin-change-audit'), __('Installed plugin %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: plugin name */
            'plugin_updated' => [self::GROUP_EXTENSIONS, __('Plugin updated', 'kovalenko-admin-change-audit'), __('Updated plugin %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: plugin name */
            'plugin_activated' => [self::GROUP_EXTENSIONS, __('Plugin activated', 'kovalenko-admin-change-audit'), __('Activated plugin %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: plugin name */
            'plugin_deactivated' => [self::GROUP_EXTENSIONS, __('Plugin deactivated', 'kovalenko-admin-change-audit'), __('Deactivated plugin %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: plugin name */
            'plugin_deleted' => [self::GROUP_EXTENSIONS, __('Plugin deleted', 'kovalenko-admin-change-audit'), __('Deleted plugin %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: theme name */
            'theme_installed' => [self::GROUP_EXTENSIONS, __('Theme installed', 'kovalenko-admin-change-audit'), __('Installed theme %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: theme name */
            'theme_updated' => [self::GROUP_EXTENSIONS, __('Theme updated', 'kovalenko-admin-change-audit'), __('Updated theme %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: theme name */
            'theme_switched' => [self::GROUP_EXTENSIONS, __('Theme switched', 'kovalenko-admin-change-audit'), __('Switched the theme to %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: theme name */
            'theme_deleted' => [self::GROUP_EXTENSIONS, __('Theme deleted', 'kovalenko-admin-change-audit'), __('Deleted theme %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: WordPress version */
            'core_updated' => [self::GROUP_EXTENSIONS, __('WordPress updated', 'kovalenko-admin-change-audit'), __('Updated WordPress to %s', 'kovalenko-admin-change-audit')],

            /* translators: %s: setting name */
            'option_updated' => [self::GROUP_SETTINGS, __('Site setting changed', 'kovalenko-admin-change-audit'), __('Changed the setting %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: always "ChangeTrail" */
            'changetrail_settings_updated' => [self::GROUP_SETTINGS, __('Log settings changed', 'kovalenko-admin-change-audit'), __('Changed the %s settings', 'kovalenko-admin-change-audit')],
            /* translators: %s: new owner username */
            'changetrail_owner_changed' => [self::GROUP_SETTINGS, __('Log owner changed', 'kovalenko-admin-change-audit'), __('Transferred the activity log to %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: always "ChangeTrail" */
            'changetrail_password_changed' => [self::GROUP_SETTINGS, __('Log password changed', 'kovalenko-admin-change-audit'), __('Changed the %s access password', 'kovalenko-admin-change-audit')],
            /* translators: %s: always "ChangeTrail" */
            'changetrail_unlock_failed' => [self::GROUP_SETTINGS, __('Wrong log password', 'kovalenko-admin-change-audit'), __('Entered a wrong %s access password', 'kovalenko-admin-change-audit')],
            /* translators: %s: device name or code */
            'changetrail_device_updated' => [self::GROUP_SETTINGS, __('Device list changed', 'kovalenko-admin-change-audit'), __('Changed the device %s', 'kovalenko-admin-change-audit')],
            /* translators: %s: always "ChangeTrail" */
            'changetrail_logs_deleted' => [self::GROUP_SETTINGS, __('Log cleared', 'kovalenko-admin-change-audit'), __('Deleted all %s log entries', 'kovalenko-admin-change-audit')],
        ];

        $events = array_map(
            static fn (array $definition): array => [
                'group' => $definition[0],
                'name' => $definition[1],
                'summary' => $definition[2],
            ],
            $events
        );

        return $events;
    }

    public static function exists(string $event): bool
    {
        return isset(self::all()[$event]);
    }

    public static function group_of(string $event): string
    {
        return self::all()[$event]['group'] ?? '';
    }

    /**
     * @return list<string>
     */
    public static function in_group(string $group): array
    {
        return array_keys(array_filter(self::all(), static fn (array $definition): bool => $definition['group'] === $group));
    }

    public static function icon(string $event): string
    {
        $group = self::group_of($event);

        return self::groups()[$group]['icon'] ?? 'dashicons-backup';
    }

    /**
     * Plain-text summary used for the stored activity text, CSV export and search.
     */
    /**
     * @param string $second Second placeholder value, used by summaries with two placeholders (device_matched).
     */
    public static function summary(string $event, string $object_label, string $second = ''): string
    {
        $definition = self::all()[$event] ?? null;
        if ($definition === null) {
            return $object_label;
        }

        // Always pass both values: extra arguments are ignored by one-placeholder summaries.
        return sprintf($definition['summary'], $object_label, $second);
    }
}
