=== Kovalenko Admin Change Audit ===
Contributors: dmytrokovalenko
Tags: activity log, audit log, security, logging, monitoring
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Records important WordPress activity and provides an owner-only log viewer for monitoring client changes.

== Description ==

Kovalenko Admin Change Audit helps site owners keep an audit trail of important administrative activity in a dedicated owner-only log viewer.

Features include:

* Login and logout activity logging
* Post creation, updates, trash actions, and permanent deletions
* Plugin activation, deactivation, and deletion logging
* Owner-only log viewer under the Tools menu
* Live refresh, filters, sorting, and pagination
* Optional second password layer before logs are shown
* Configurable timezone with America/Edmonton as the default
* Automatic cleanup of expired logs

The plugin does not require an external account or third-party service to function.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate **Kovalenko Admin Change Audit**.
3. After activation, open **Tools > Activity Logs**.
4. Optionally set an extra access password inside the log viewer.

== Frequently Asked Questions ==

= Who can access the log viewer? =

Only the owner account stored by the plugin can open the log viewer screen.

= Is the viewer visible in the admin menu? =

The screen is available to the owner account from the Tools menu and from an admin bar shortcut.

= Does the plugin send data to third-party services? =

No. The release prepared for WordPress.org works fully inside WordPress and does not fetch plugin updates or operational data from an external service.

= What happens on scheduled maintenance? =

The plugin runs a daily cleanup task that removes log rows older than the configured retention period.

= Can I choose a different timezone? =

Yes. The plugin defaults to `America/Edmonton`, but the owner can choose a different timezone for displayed timestamps, date filters, and the nightly maintenance schedule.

= I used the earlier "WP Activity Logger" plugin. Do I keep my settings and logs? =

Yes. Existing log rows are kept because both plugins use the same log table, and on the first load after updating this plugin imports the old access URL, owner, extra password, and timezone settings (only where this plugin has no value yet). The old plugin's options are left in place. The old password is only imported when the old owner is also the owner of this plugin. If the old plugin is still active during the first load, its access URL is not copied (both would use the same menu address); after deactivating the old plugin your log address is the new one shown in the access notice. Deactivate the old plugin to avoid recording every event twice. A password set through the old plugin's `wp_activity_logger_access_password` filter is not carried over; set it again in this plugin's security form or with KOVALENKO_ADMIN_CHANGE_AUDIT_ACCESS_PASSWORD.

= I lost access to the log viewer. How do I recover? =

If the stored owner account was deleted or lost administrator access, the next administrator who opens the dashboard becomes the owner automatically. To reset it manually with WP-CLI, run `wp option delete kovalenko_admin_change_audit_owner_user_id`.

== Screenshots ==

1. Owner-only activity log viewer with filters, metrics, and live refresh.
2. Security panel for protecting the hidden viewer with an extra password.

== Changelog ==

= 2.8.1 =

* Import settings from the earlier WP Activity Logger plugin once: access URL slug, owner account, extra password, notice preference, and timezone. Existing values in this plugin are never overwritten.
* Honor the WP_ACTIVITY_LOGGER_ACCESS_PASSWORD constant as a read-only fallback when KOVALENKO_ADMIN_CHANGE_AUDIT_ACCESS_PASSWORD is not defined.
* Show an owner-only notice when the earlier plugin is still active, because both would log every event twice.
* Remove scheduled cleanup events and unlock sessions left behind by the earlier plugin.
* Keep the existing owner account when the plugin is re-activated, but only while that account still exists and has access; otherwise ownership moves to the user who activates it (or the next administrator).

= 2.8.0 =

* Log custom field, SEO field, ACF field, and featured image changes with readable before-and-after details instead of the generic fallback message.
* Log category and tag changes on posts with human-readable term names.
* Aggregate all custom field changes from a single save into one log entry instead of one row per field.

= 2.7.0 =

* Add expandable before-and-after change details for post and page updates in the activity log viewer.
* Store structured log details in the database for newly recorded content updates.
* Add a schema upgrade path for the new log details column.

= 2.6.3 =

* Rename the public plugin to a more distinctive WordPress.org-ready brand and slug.
* Replace the old wp_* technical prefix with a unique plugin-specific prefix across declarations, hooks, and stored keys.
* Limit activation guidance notices to the plugin list and the dedicated log screen.

= 2.6.2 =

* Harden the activity log queries so every dynamic value uses a $wpdb->prepare() placeholder.
* Require WordPress 6.2+ for identifier (%i) placeholder support.

= 2.6.1 =

* Prepare the plugin for WordPress.org distribution.
* Remove the private auto-update flow from the public release build.
* Add WordPress.org-compatible readme and deployment packaging workflow.
* Add configurable timezone support with America/Edmonton as the default.

= 2.6 =

* Improve actor labels and log viewer clarity.
* Preserve Edmonton-based timestamps and filtering behavior.

== Upgrade Notice ==

= 2.8.1 =

Imports settings from the earlier WP Activity Logger plugin. If you are switching, deactivate the old plugin first, since both write to the same log.

= 2.6.3 =

This release renames the plugin for WordPress.org review and replaces the old technical prefixes with a unique plugin-specific prefix.

= 2.6.2 =

This release hardens the database queries and now requires WordPress 6.2 or newer.

= 2.6.1 =

This release removes the private updater and aligns the public package with WordPress.org distribution requirements.
