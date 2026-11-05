=== Digicyfr External Embed Inventory ===
Tags: embeds, external resources, editorial, inventory
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

A private inventory of external references found in saved published posts and pages.

== Description ==

This local, read-only tool groups supported saved resource URL attributes and core/embed provider URL hints by host. It never loads those URLs, changes content, adds front-end output, blocks resources, or makes privacy/compliance claims. The manual scan is bounded and resumable; skipped content makes an attempt incomplete. Administrators can inspect a paged report and export guarded CSV. See README.md for exact coverage and limits.

== Installation ==

1. Upload the ZIP at Plugins > Add New > Upload Plugin and activate it. InnoDB tables are required.
2. Open Tools > External Embed Inventory and start a scan. Use Continue until complete.
3. Review hosts and source pages. Rescan after content changes.

== Frequently Asked Questions ==

= Does a host row prove a browser request? =

No. A saved reference may never render or load, and a provider URL hint can differ from the rendered resource endpoint.

= Does no row mean the site has no external resources? =

No. Themes, dynamic output, CSS, JavaScript, templates, widgets, and other excluded sources are outside this scan.

= What happens on uninstall? =

Only the plugin's scan tables and options are removed. Posts and pages remain. Deactivation keeps the report.

== Changelog ==

= 0.1.0 =

* Initial candidate with bounded saved-content scan, private report and guarded CSV.
