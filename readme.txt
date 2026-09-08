=== GSC Schema Fix ===
Contributors: dratzymarcano
Tags: search-console, seo, schema, wordpress, audit
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 5.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress website diagnostics with optional Google URL Inspection and verified, reversible metadata repairs.

== Description ==
Now includes Ahrefs ZIP/CSV import, heading and metadata diagnostics, linked-asset status checks, redirect-chain summaries, Open Graph supplements and safe restoration of saved image alt text. See AHREFS.md.
Scans public posts, pages, custom types, archives and same-site sitemap URLs. After a complete scan, use Auto Fix Solvable Issues to apply metadata additions derived from existing content. No indexing or ranking guarantee. See README.md for complete setup, coverage and migration notes.

== Installation ==
Upload the plugin ZIP, activate it, and open Settings > GSC Schema Fix. Scan Website, then review and run Auto Fix Solvable Issues.

== External services ==
Optional external link/asset checking requests public destination URLs found in your pages, without WordPress cookies or authorization headers. It is disabled by default.
Optional Google Search Console inspection sends scanned page URLs and your property identifier to Google when enabled. Authentication uses oauth2.googleapis.com; inspection uses searchconsole.googleapis.com. It uses a server-configured service-account key and read-only scope. See https://policies.google.com/privacy and https://developers.google.com/terms for Google's policies. Local scans work without this service.

== Changelog ==
= 5.1.0 =
Universal batched scanning, verified reversible metadata repairs, optional read-only Google inspection and updated rich-result guidance. Major replacement of the 4.1.3 workflow; see CHANGELOG.md.
