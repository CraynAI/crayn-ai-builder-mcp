=== Crayn Safe MCP ===
Contributors: craynai
Tags: mcp, ai, agents, abilities-api, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Direct, ready-to-call tools for AI agents, with preview, change log and one-call revert. Built on the official WordPress MCP Adapter.

== Description ==

Crayn Safe MCP gives AI agents like Claude Desktop, Claude Code and Cursor a safe way to work on your site.

**Direct tools.** Every tool is listed to the agent with its full schema, ready to call, with no discover-inspect-execute round trips.

**Safe by design.** Every write can be previewed first, is logged with a before and after snapshot and can be reverted. Plugin updates take a backup and roll back if the site breaks. A read-only switch blocks every write.

**Works with your stack.** Dedicated tools for Rank Math, WPBakery and Elementor. Rank Math and Yoast's own abilities are exposed through the same safety layer.

Built on the official WordPress MCP Adapter (GPL), which is included unmodified, so there is nothing else to install. This plugin is developed by Crayn AI and is not affiliated with or endorsed by the WordPress Foundation.

57 tools in total (the Rank Math, WPBakery and Elementor tools appear only when that plugin is active):

* Content: list/get/create/update/trash posts, list/upload media, list/create terms.
* SEO: auto-detected Rank Math or Yoast fields (title, description, focus keyword, canonical, robots) with old-value logging.
* WPBakery: list elements, read a page as a tree, add, update, move and remove elements with placement validation, set or clear the page custom CSS, dry run and revert.
* Elementor: list widgets and their controls, read a page as a tree, add, update, move, duplicate and remove elements by id, replace the tree, set page settings, turn a post into an Elementor page. Parts you do not touch are written back byte for byte; V4 atomic elements can be read, moved and removed.
* Site settings: read and atomically change an allow-list of options (title, tagline, timezone, formats, language, reading, comments, permalinks, image sizes). Secrets, URLs, roles and plugin lists can never be changed.
* Menus: list and read menus, create, rename and delete them, add, edit, move and remove items, assign theme locations; deleted menus and items come back with a revert.
* Rank Math parity: every editor field (focus keywords, robots, social, schema, redirects) via rankmath-get / rankmath-update / rankmath-schema-set / rankmath-schema-delete / rankmath-redirect-set / rankmath-analyze, plus safe wrappers for Rank Math and Yoast own abilities (dry run, option snapshot, revert).
* Plugins: safe updates with automatic zip backup, site health check and automatic rollback; activation changes with the same health check; rollback from backups; backup and update listings.
* Site: site health and the change log (list changes, revert changes).

Why it is safe:

1. Every write tool accepts `dry_run: true` and returns the planned change as {before, after} without writing anything.
2. Every real write logs a before/after snapshot BEFORE returning and returns a `change_id`.
3. `change-revert` restores post fields, term assignments, SEO meta, WPBakery and Elementor page data, site settings, menus, plugin activation state and plugin files (from the backup zip). Reverting twice fails cleanly, and a revert is refused when the object changed after the write.
4. Posts are never hard deleted; deletions go to the trash only. Menus and menu items have no trash in WordPress: deleting them keeps the whole menu in the change log and a revert recreates it.
5. Plugin updates run a health check (front end + wp-admin must answer correctly) and roll back automatically when the site breaks.
6. A read-only mode switch makes every write ability return an error.
7. Categories (content/seo/plugins/site/settings) can be enabled or disabled individually from the settings page.

The plugin also creates a custom MCP server with every ability listed as a direct tool at `/wp-json/crayn-safe/v1/mcp`, and keeps `meta.mcp.public = true` so the adapter's default discovery server exposes them too.

== Installation ==

1. Upload the `crayn-safe-mcp` folder to `/wp-content/plugins/` (or install the zip).
2. Activate the plugin through the 'Plugins' screen. The change-log table is created on activation.
3. Create a WordPress Application Password (Users > Profile > Application Passwords).
4. Point your MCP client at `https://yoursite.com/wp-json/crayn-safe/v1/mcp` with HTTP Basic Authentication (username + application password).
5. Optionally: Settings > Crayn Safe MCP to toggle categories, enable read-only mode and copy the ready-to-use client snippet.

== Frequently Asked Questions ==

= Do I need the separate MCP Adapter plugin? =

No. The official adapter is bundled in `vendor/` and loaded with Jetpack Autoloader, which resolves conflicts if another plugin also bundles the adapter. If the standalone MCP Adapter plugin is active, it starts the adapter instead of this plugin. With several copies on a site, the adapter may log a notice in debug mode; it does not affect the tools.

= Which WordPress version do I need? =

WordPress 6.9 or newer, because the Abilities API must be part of core.

= Can I try a change before it happens? =

Yes, pass `dry_run: true` to any write tool and it returns the planned `{before, after}` values without writing.

= How do I undo something? =

Every write result contains a `change_id`. Call `crayn-safe/changes-list` or use the "Recent changes" table in Settings > Crayn Safe MCP and click Revert (or call `crayn-safe/change-revert`).

= What happens if a plugin update breaks the site? =

The plugin zips the plugin folder first, updates it, then checks that both the front page and wp-admin answer correctly without fatal error markers. If the check fails, the backup is restored, the plugin is re-activated if it was active, and the tool reports `rolled_back: true` with the reason.

= Which roles can use it? =

Each tool checks the capability WordPress uses for the same action, and the settings, menu, plugin and change log tools need administrator-level capabilities. A user without `unfiltered_html` cannot add or change WPBakery raw HTML or JS elements. Use an application password from an account with only the role the agent needs.

= Can page content trick my AI agent? =

Tools return site content as it is, so text written by another user can contain instructions aimed at the agent. No plugin can fully prevent this. Tell your client to treat tool output as data, keep write confirmations on, and use read-only mode or a lower-role account when the agent only needs to read.

= Are plugin backups protected on Nginx? =

Backups sit in a folder protected by an `.htaccess` file, and their names contain a random part. Nginx and IIS ignore `.htaccess`, so block the folder in your server configuration, for example `location ~* /crayn-safe-backups/ { deny all; }`.

== Changelog ==

= 1.0.0 =
* Initial release: bundled MCP Adapter, safe content/SEO/plugins/site abilities, change log with revert, plugin backups with automatic rollback, read-only mode, admin settings page.
* Elementor, site settings and menu tools with dry run, change log and revert.
