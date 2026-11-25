<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Owner-only access, the optional second password, session-bound unlocks and attempt limiting.
 */
final class Kovalenko_Admin_Change_Audit_Access
{
    public const CAPABILITY = 'manage_options';
    public const OPTION_OWNER_USER_ID = 'kovalenko_admin_change_audit_owner_user_id';
    public const OPTION_PASSWORD_HASH = 'kovalenko_admin_change_audit_access_password_hash';
    public const USER_META_UNLOCK = 'kovalenko_admin_change_audit_unlocked_until';
    public const PASSWORD_CONSTANT = 'KOVALENKO_ADMIN_CHANGE_AUDIT_ACCESS_PASSWORD';

    public const MAX_ATTEMPTS = 5;
    public const ATTEMPT_WINDOW = 15 * MINUTE_IN_SECONDS;

    public static function owner_id(): int
    {
        return (int) get_option(self::OPTION_OWNER_USER_ID, 0);
    }

    public static function has_valid_owner(): bool
    {
        $owner_id = self::owner_id();

        return $owner_id > 0 && get_userdata($owner_id) instanceof WP_User && user_can($owner_id, self::CAPABILITY);
    }

    public static function set_owner(int $user_id): void
    {
        if ($user_id > 0) {
            update_option(self::OPTION_OWNER_USER_ID, $user_id, false);
        }
    }

    /**
     * Makes the current administrator the owner when no valid owner exists (fresh install, or the owner
     * account was deleted or lost administrator rights).
     */
    public static function ensure_owner(): void
    {
        if (self::has_valid_owner() || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        self::set_owner(get_current_user_id());
    }

    public static function is_owner(): bool
    {
        if (! current_user_can(self::CAPABILITY)) {
            return false;
        }

        $owner_id = self::owner_id();

        return $owner_id <= 0 || get_current_user_id() === $owner_id;
    }

    /**
     * @return list<WP_User>
     */
    public static function eligible_owners(): array
    {
        $users = get_users([
            'capability' => self::CAPABILITY,
            'orderby' => 'display_name',
            'number' => 200,
        ]);

        return array_values(array_filter($users, static fn ($user): bool => $user instanceof WP_User));
    }

    public static function constant_password(): string
    {
        $password = defined(self::PASSWORD_CONSTANT) ? trim((string) constant(self::PASSWORD_CONSTANT)) : '';

        return (string) apply_filters('kovalenko_admin_change_audit_access_password', $password);
    }

    public static function saved_hash(): string
    {
        return (string) get_option(self::OPTION_PASSWORD_HASH, '');
    }

    public static function is_password_required(): bool
    {
        return self::constant_password() !== '' || self::saved_hash() !== '';
    }

    public static function is_password_from_constant(): bool
    {
        return self::constant_password() !== '';
    }

    public static function set_password(string $password): void
    {
        update_option(self::OPTION_PASSWORD_HASH, wp_hash_password($password), false);
    }

    public static function remove_password(): void
    {
        delete_option(self::OPTION_PASSWORD_HASH);
    }

    public static function password_matches(string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $constant = self::constant_password();
        if ($constant !== '') {
            return hash_equals($constant, $password);
        }

        $hash = self::saved_hash();

        return $hash !== '' && wp_check_password($password, $hash);
    }

    private static function session_fingerprint(): string
    {
        $token = function_exists('wp_get_session_token') ? wp_get_session_token() : '';

        return $token !== '' ? hash('sha256', $token) : '';
    }

    /**
     * Active unlocks of the current user, keyed by a hash of the login session token.
     *
     * @return array<string, int>
     */
    private static function unlocks(): array
    {
        $stored = get_user_meta(get_current_user_id(), self::USER_META_UNLOCK, true);
        if (! is_array($stored)) {
            // Pre-3.0 unlocks were one timestamp that was not tied to a session; those ask once again.
            return [];
        }

        $now = time();

        return array_filter(
            array_map('intval', array_filter($stored, 'is_scalar')),
            static fn (int $until, $session): bool => is_string($session) && strlen($session) === 64 && $until > $now,
            ARRAY_FILTER_USE_BOTH
        );
    }

    public static function is_unlocked(): bool
    {
        if (! self::is_password_required()) {
            return true;
        }

        $fingerprint = self::session_fingerprint();

        return $fingerprint !== '' && isset(self::unlocks()[$fingerprint]);
    }

    public static function unlock(): void
    {
        $fingerprint = self::session_fingerprint();
        if ($fingerprint === '') {
            return;
        }

        $hours = max(1, (int) Kovalenko_Admin_Change_Audit_Settings::get('unlock_hours'));
        $unlocks = self::unlocks();
        $unlocks[$fingerprint] = time() + $hours * HOUR_IN_SECONDS;

        update_user_meta(get_current_user_id(), self::USER_META_UNLOCK, $unlocks);
    }

    public static function lock(int $user_id): void
    {
        delete_user_meta($user_id, self::USER_META_UNLOCK);
    }

    private static function attempts_key(): string
    {
        return 'kovalenko_admin_change_audit_unlock_attempts_' . get_current_user_id();
    }

    /** Seconds until another attempt is allowed, or 0 when attempts are allowed. */
    public static function lockout_remaining(): int
    {
        $attempts = get_transient(self::attempts_key());
        if (! is_array($attempts) || (int) ($attempts['count'] ?? 0) < self::MAX_ATTEMPTS) {
            return 0;
        }

        return max(0, (int) ($attempts['start'] ?? 0) + self::ATTEMPT_WINDOW - time());
    }

    public static function register_failed_attempt(): void
    {
        $attempts = get_transient(self::attempts_key());
        if (! is_array($attempts) || (int) ($attempts['start'] ?? 0) + self::ATTEMPT_WINDOW <= time()) {
            $attempts = ['count' => 0, 'start' => time()];
        }

        $attempts['count'] = (int) $attempts['count'] + 1;
        set_transient(self::attempts_key(), $attempts, self::ATTEMPT_WINDOW);
    }

    public static function clear_attempts(): void
    {
        delete_transient(self::attempts_key());
    }

    /** Owner who can see the screen and has passed the optional password. */
    public static function can_view(): bool
    {
        return self::is_owner() && self::is_unlocked();
    }
}
