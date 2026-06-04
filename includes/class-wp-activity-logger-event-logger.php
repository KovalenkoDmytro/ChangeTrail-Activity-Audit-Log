<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Kovalenko_Admin_Change_Audit_Event_Logger
{
    public function register_hooks(): void
    {
        add_action('wp_login', [$this, 'log_login'], 10, 2);
        add_action('wp_logout', [$this, 'log_logout'], 10, 1);
        add_action('post_updated', [$this, 'log_post_update'], 10, 3);
        add_action('wp_insert_post', [$this, 'log_post_creation'], 10, 3);
        add_action('wp_trash_post', [$this, 'log_post_trash']);
        add_action('before_delete_post', [$this, 'log_post_deletion']);
        add_action('activated_plugin', [$this, 'log_plugin_activation']);
        add_action('deactivated_plugin', [$this, 'log_plugin_deactivation']);
        add_action('upgrader_process_complete', [$this, 'log_plugin_deletion'], 10, 2);
    }

    public function log_login(string $user_login, \WP_User $user): void
    {
        $this->record_activity(
            sprintf("User '%s' logged in.", $user_login),
            (int) $user->ID
        );
    }

    public function log_logout(int $user_id): void
    {
        $actor = $this->get_actor_from_user_id($user_id);
        $actor_text = $this->format_actor_text($actor['label'], $actor['id']);

        $this->record_activity(
            sprintf('%s logged out.', $actor_text),
            $actor['id']
        );
    }

    public function log_post_update(int $post_id, object $post_after, object $post_before): void
    {
        if (
            $post_after->post_status === 'auto-draft'
            || wp_is_post_revision($post_id)
            || wp_is_post_autosave($post_id)
        ) {
            return;
        }

        $actor = $this->get_current_actor();
        $changes = $this->detect_post_changes($post_before, $post_after);
        $actor_text = $this->format_actor_text($actor['label'], $actor['id']);

        $this->record_activity(
            sprintf(
                '%s updated post ID %d (%s): %s.',
                $actor_text,
                $post_id,
                get_permalink($post_id) ?: home_url(sprintf('/?p=%d', $post_id)),
                $changes['summary']
            ),
            $actor['id'],
            $changes['details']
        );
    }

    public function log_post_creation(int $post_id, \WP_Post $post, bool $update): void
    {
        if (
            $update
            || $post->post_status === 'auto-draft'
            || wp_is_post_revision($post_id)
            || wp_is_post_autosave($post_id)
        ) {
            return;
        }

        $actor = $this->get_current_actor();
        $actor_text = $this->format_actor_text($actor['label'], $actor['id']);
        $this->record_activity(
            sprintf(
                "%s created post ID %d (%s) with title '%s'.",
                $actor_text,
                $post_id,
                get_permalink($post_id) ?: home_url(sprintf('/?p=%d', $post_id)),
                $post->post_title
            ),
            $actor['id']
        );
    }

    public function log_post_trash(int $post_id): void
    {
        $actor = $this->get_current_actor();
        $actor_text = $this->format_actor_text($actor['label'], $actor['id']);
        $post = get_post($post_id);

        if (! $post instanceof \WP_Post) {
            return;
        }

        $this->record_activity(
            sprintf(
                "%s moved post ID %d with title '%s' to the trash.",
                $actor_text,
                $post_id,
                $post->post_title
            ),
            $actor['id']
        );
    }

    public function log_post_deletion(int $post_id): void
    {
        $actor = $this->get_current_actor();
        $actor_text = $this->format_actor_text($actor['label'], $actor['id']);
        $post = get_post($post_id);

        if (! $post instanceof \WP_Post || $post->post_status === 'trash') {
            return;
        }

        $this->record_activity(
            sprintf(
                "%s permanently deleted post ID %d (%s) with title '%s'.",
                $actor_text,
                $post_id,
                get_permalink($post_id) ?: home_url(sprintf('/?p=%d', $post_id)),
                $post->post_title
            ),
            $actor['id']
        );
    }

    public function log_plugin_activation(string $plugin): void
    {
        $actor = $this->get_current_actor();
        $actor_text = $this->format_actor_text($actor['label'], $actor['id']);
        $this->record_activity(
            sprintf(
                "%s activated plugin '%s'.",
                $actor_text,
                plugin_basename($plugin)
            ),
            $actor['id']
        );
    }

    public function log_plugin_deactivation(string $plugin): void
    {
        $actor = $this->get_current_actor();
        $actor_text = $this->format_actor_text($actor['label'], $actor['id']);
        $this->record_activity(
            sprintf(
                "%s deactivated plugin '%s'.",
                $actor_text,
                plugin_basename($plugin)
            ),
            $actor['id']
        );
    }

    public function log_plugin_deletion(object $upgrader, array $options): void
    {
        unset($upgrader);

        if (($options['type'] ?? '') !== 'plugin' || ($options['action'] ?? '') !== 'delete') {
            return;
        }

        $actor = $this->get_current_actor();
        $actor_text = $this->format_actor_text($actor['label'], $actor['id']);
        $deleted_plugins = isset($options['plugins']) && is_array($options['plugins'])
            ? implode(', ', array_map('plugin_basename', $options['plugins']))
            : 'Unknown plugins';

        $this->record_activity(
            sprintf(
                '%s deleted plugin(s): %s.',
                $actor_text,
                $deleted_plugins
            ),
            $actor['id']
        );
    }

    /**
     * @param list<array{label: string, before: string, after: string}>|null $details
     */
    private function record_activity(string $message, ?int $user_id = null, ?array $details = null): void
    {
        kovalenko_admin_change_audit_record_activity($message, $user_id, $details);
    }

    private function get_current_actor(): array
    {
        $current_user = wp_get_current_user();
        if ($current_user instanceof \WP_User && $current_user->exists() && $current_user->user_login !== '') {
            return [
                'id' => (int) $current_user->ID,
                'label' => $current_user->user_login,
            ];
        }

        return [
            'id' => null,
            'label' => $this->get_system_actor_label(),
        ];
    }

    private function get_actor_from_user_id(int $user_id): array
    {
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user instanceof \WP_User && $user->user_login !== '') {
                return [
                    'id' => $user_id,
                    'label' => $user->user_login,
                ];
            }

            return [
                'id' => $user_id,
                'label' => sprintf('Deleted user #%d', $user_id),
            ];
        }

        return [
            'id' => null,
            'label' => $this->get_system_actor_label(),
        ];
    }

    private function get_system_actor_label(): string
    {
        return __('System', 'kovalenko-admin-change-audit');
    }

    private function format_actor_text(string $actor_label, ?int $user_id): string
    {
        if ($user_id === null) {
            return $actor_label;
        }

        return sprintf("User '%s'", $actor_label);
    }

    /**
     * @return array{
     *     summary: string,
     *     details: list<array{label: string, before: string, after: string}>
     * }
     */
    private function detect_post_changes(object $post_before, object $post_after): array
    {
        $summary_parts = [];
        $details = [];

        if ($post_before->post_title !== $post_after->post_title) {
            $summary_parts[] = sprintf(
                "title changed from '%s' to '%s'",
                $post_before->post_title,
                $post_after->post_title
            );
            $details[] = $this->build_change_detail(
                label: __('Title', 'kovalenko-admin-change-audit'),
                before: (string) $post_before->post_title,
                after: (string) $post_after->post_title
            );
        }

        if ($post_before->post_content !== $post_after->post_content) {
            $summary_parts[] = 'content updated';
            $details[] = $this->build_change_detail(
                label: __('Content', 'kovalenko-admin-change-audit'),
                before: $this->prepare_post_content_value((string) $post_before->post_content),
                after: $this->prepare_post_content_value((string) $post_after->post_content)
            );
        }

        if (($post_before->post_excerpt ?? '') !== ($post_after->post_excerpt ?? '')) {
            $summary_parts[] = 'excerpt updated';
            $details[] = $this->build_change_detail(
                label: __('Excerpt', 'kovalenko-admin-change-audit'),
                before: $this->prepare_post_content_value((string) ($post_before->post_excerpt ?? '')),
                after: $this->prepare_post_content_value((string) ($post_after->post_excerpt ?? ''))
            );
        }

        if (($post_before->post_status ?? '') !== ($post_after->post_status ?? '')) {
            $summary_parts[] = sprintf(
                "status changed from '%s' to '%s'",
                $post_before->post_status,
                $post_after->post_status
            );
            $details[] = $this->build_change_detail(
                label: __('Status', 'kovalenko-admin-change-audit'),
                before: (string) ($post_before->post_status ?? ''),
                after: (string) ($post_after->post_status ?? '')
            );
        }

        if (($post_before->post_name ?? '') !== ($post_after->post_name ?? '')) {
            $summary_parts[] = sprintf(
                "slug changed from '%s' to '%s'",
                $post_before->post_name,
                $post_after->post_name
            );
            $details[] = $this->build_change_detail(
                label: __('Slug', 'kovalenko-admin-change-audit'),
                before: (string) ($post_before->post_name ?? ''),
                after: (string) ($post_after->post_name ?? '')
            );
        }

        if (($post_before->menu_order ?? 0) !== ($post_after->menu_order ?? 0)) {
            $summary_parts[] = 'menu order updated';
            $details[] = $this->build_change_detail(
                label: __('Menu order', 'kovalenko-admin-change-audit'),
                before: (string) ($post_before->menu_order ?? 0),
                after: (string) ($post_after->menu_order ?? 0)
            );
        }

        if (($post_before->post_parent ?? 0) !== ($post_after->post_parent ?? 0)) {
            $summary_parts[] = 'parent updated';
            $details[] = $this->build_change_detail(
                label: __('Parent', 'kovalenko-admin-change-audit'),
                before: $this->resolve_post_parent_label((int) ($post_before->post_parent ?? 0)),
                after: $this->resolve_post_parent_label((int) ($post_after->post_parent ?? 0))
            );
        }

        if (($post_before->post_author ?? 0) !== ($post_after->post_author ?? 0)) {
            $summary_parts[] = 'author updated';
            $details[] = $this->build_change_detail(
                label: __('Author', 'kovalenko-admin-change-audit'),
                before: $this->resolve_author_label((int) ($post_before->post_author ?? 0)),
                after: $this->resolve_author_label((int) ($post_after->post_author ?? 0))
            );
        }

        if (($post_before->comment_status ?? '') !== ($post_after->comment_status ?? '')) {
            $summary_parts[] = 'comment settings updated';
            $details[] = $this->build_change_detail(
                label: __('Comments', 'kovalenko-admin-change-audit'),
                before: (string) ($post_before->comment_status ?? ''),
                after: (string) ($post_after->comment_status ?? '')
            );
        }

        if (($post_before->ping_status ?? '') !== ($post_after->ping_status ?? '')) {
            $summary_parts[] = 'ping settings updated';
            $details[] = $this->build_change_detail(
                label: __('Pings', 'kovalenko-admin-change-audit'),
                before: (string) ($post_before->ping_status ?? ''),
                after: (string) ($post_after->ping_status ?? '')
            );
        }

        return [
            'summary' => $summary_parts !== []
                ? implode(', ', $summary_parts)
                : 'post settings or metadata updated',
            'details' => $details,
        ];
    }

    /**
     * @return array{label: string, before: string, after: string}
     */
    private function build_change_detail(string $label, string $before, string $after): array
    {
        return [
            'label' => $label,
            'before' => trim($before),
            'after' => trim($after),
        ];
    }

    private function prepare_post_content_value(string $value): string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $value));
        $plain_text = trim(wp_strip_all_tags($normalized, false));

        if ($plain_text !== '') {
            $normalized = $plain_text;
        }

        $collapsed = preg_replace("/\n{3,}/", "\n\n", $normalized);

        return is_string($collapsed) ? trim($collapsed) : $normalized;
    }

    private function resolve_post_parent_label(int $post_id): string
    {
        if ($post_id <= 0) {
            return '';
        }

        $post = get_post($post_id);

        if (! $post instanceof \WP_Post) {
            return (string) $post_id;
        }

        return $post->post_title !== ''
            ? sprintf('%s (#%d)', $post->post_title, $post_id)
            : sprintf('#%d', $post_id);
    }

    private function resolve_author_label(int $user_id): string
    {
        if ($user_id <= 0) {
            return '';
        }

        $user = get_userdata($user_id);

        if (! $user instanceof \WP_User) {
            return (string) $user_id;
        }

        return $user->user_login;
    }
}
