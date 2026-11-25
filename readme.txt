=== ChangeTrail – Activity & Audit Log ===
Contributors: dmytrokovalenko
Tags: activity log, audit log, security, user activity, changelog
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See who changed what and when on your site: logins, content, users, plugins, themes and key settings, in a private log only you can open.

== Description ==

ChangeTrail keeps a clear, private history of what happens in your WordPress admin. It is made for site owners and agencies who want to know who changed what and when, without a complicated security suite.

The log lives under **Tools > ChangeTrail** and looks like the rest of WordPress: a normal list with search, filters, sorting and pagination.

**What it records**

* Logins, logouts and failed login attempts
* New and deleted users, and role, email and password changes
* Posts, pages and other content: created, edited (with a before/after view), trashed, restored and deleted
* Custom field, featured image, category and tag changes
* Plugins and themes installed, updated (with versions), activated, deactivated and deleted
* WordPress core updates
* Which device did it: every entry shows the computer and browser (for example "Reception PC · Chrome 131 · Windows 10/11"), so you can tell people apart even when they share one account and one office IP address
* Key site settings: site title, site and WordPress address, admin email, membership, default role, language, timezone, permalinks, search engine visibility and homepage settings

**Built for privacy and control**

* Only one administrator (the owner) can open the log. Ownership can be handed over in the settings.
* Optional extra password, with a lock after 5 wrong tries and an unlock that ends when you log out.
* Choose how long entries are kept, from 7 days to forever. Old entries are removed automatically.
* Turn activity types on or off, and ignore noisy post types and custom fields.
* Values are shortened to 2 KB, and fields that look like passwords or keys are hidden.
* Export to CSV, or delete the whole log.
* Works with the WordPress personal data export and erase tools, and adds suggested text to the privacy policy guide.
* No account, no external service, no third-party tracking. Everything stays in your own database.

== Installation ==

1. Install ChangeTrail from **Plugins > Add New**, or upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. The administrator who activates it becomes the log owner.
3. Open **Tools > ChangeTrail**. Use the **Settings** tab to choose what to log, how long to keep it and your timezone.

== Frequently Asked Questions ==

= Who can see the log? =

Only the owner: the administrator who activated the plugin, or whoever the owner handed it over to under Settings > Access. Other administrators do not see the menu item.

Keep in mind that any administrator can still deactivate or delete plugins. ChangeTrail logs it when it is deactivated, but it cannot stop another administrator who has full control of the site.

= Can I add an extra password? =

Yes, under Settings > Access. You can also define `KOVALENKO_ADMIN_CHANGE_AUDIT_ACCESS_PASSWORD` in `wp-config.php`. After 5 wrong tries the screen locks for 15 minutes, and every wrong try is logged.

= I lost access to the log. How do I get it back? =

If the owner account is deleted or loses administrator rights, the next administrator who opens the dashboard becomes the owner. With WP-CLI you can reset it with `wp option delete kovalenko_admin_change_audit_owner_user_id`, or remove the extra password with `wp option delete kovalenko_admin_change_audit_access_password_hash`.

= Which timezone is used? =

By default the site timezone from Settings > General. You can choose a different one in ChangeTrail's settings. It is used for log times, date filters, exports and the nightly cleanup.

= How does it tell devices apart when everyone shares one account? =

Each browser that opens the login page or the dashboard gets a random device cookie (`kovalenko_admin_change_audit_device`, HttpOnly, kept for two years). Every log entry stores that device ID, a short one-way hash of the WordPress login session (never the session token itself) and the browser's user agent, shown as browser and operating system.

By default a small script in wp-admin also reads, once per login session, a few device details: screen size and pixel ratio, colour depth, device timezone, language, CPU cores, memory, graphics card (WebGL renderer), touch support and platform. These are stored with a hash in the device list, so a computer is recognized as "probably the same as" a known one even after its cookies are cleared. You can turn this off under Settings > What to log > Device fingerprint.

The first time a user is seen on a device, a "New device" entry is logged. Under the **Devices** tab you can name devices (for example "Reception PC") or forget them, and the log can be filtered by device.

= Is the device data personal data? =

It can be, so ChangeTrail discloses it in the suggested privacy policy text, includes it in the personal data export, and removes it with the personal data eraser (log entries lose their device link, and devices used only by that person are deleted). Deleting the plugin removes the device list too, unless you chose to keep the data.

= Does it slow down my site? =

ChangeTrail writes one small database row per action and does nothing on normal front-end page views. Custom fields changed by anonymous visitors, such as view counters, are ignored.

= Does it send data anywhere? =

No. Everything stays in your WordPress database.

= What happens when I delete the plugin? =

By default the log table, the device list and the settings are removed. Turn on "Keep the log and settings" under Settings > Uninstall to keep them.

== Screenshots ==

1. The activity log with views, filters, search, device column and before/after details.
2. The Devices tab: name or forget the computers that use the site.
3. Settings: retention, timezone, what to log, owner and extra password.

== Changelog ==

= 3.0.0 =

* New: redesigned log screen built from standard WordPress parts: list table with views, search, user/type/date filters, sortable columns, pagination, Screen Options and before/after details (long text uses the WordPress revisions diff).
* New: Settings tab with retention period (7 days to forever), timezone, activity types, ignored post types and custom fields, owner transfer, extra password, unlock duration, optional toolbar link and uninstall behaviour.
* New: logs failed logins, new and deleted users, and role, email and password changes.
* New: logs plugin and theme installs and updates (with versions), theme switches and deletions, and WordPress core updates.
* New: logs changes to key site settings.
* New: device identification on every entry: a device cookie, a short hash of the login session, the browser and operating system, and an optional device fingerprint. Includes a Devices tab (rename, forget), a device filter, "New device" entries, "probably the same as" matching and device columns in the CSV export.
* New: CSV export of the whole log or the current filter, and a "Delete all log entries" button.
* New: personal data exporter and eraser, privacy policy text and an uninstall routine.
* New: structured log entries (type, object and username are stored separately). Existing entries are converted in the background.
* Security: the extra password locks after 5 wrong tries for 15 minutes, every wrong try is logged, and an unlock now lasts only for the current login session.
* Security: values are cut to 2 KB and fields that look like passwords or keys are hidden. Changes made by anonymous visitors to custom fields are ignored.
* Fix: deleting a plugin is now logged (the previous hook never fired).
* Fix: permanently deleting an item from the trash is now logged.
* Changed: the log address is now `tools.php?page=changetrail`. Old private addresses redirect to it.
* Changed: when no timezone was chosen before, existing sites keep America/Edmonton. New sites use the site timezone.
* Changed: the toolbar link is off by default.
* Removed: header banner, statistics cards, auto-refresh and the AJAX endpoint.
* Removed: the one-time import from the earlier "WP Activity Logger" plugin.

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

= 3.0.0 =

New native WordPress log screen and settings, more activity types, CSV export, retention setting and stronger access protection. The log address changes to Tools > ChangeTrail (old links redirect). If you use an extra password, you will be asked for it once after updating.

= 2.6.3 =

This release renames the plugin for WordPress.org review and replaces the old technical prefixes with a unique plugin-specific prefix.

= 2.6.2 =

This release hardens the database queries and now requires WordPress 6.2 or newer.

= 2.6.1 =

This release removes the private updater and aligns the public package with WordPress.org distribution requirements.
