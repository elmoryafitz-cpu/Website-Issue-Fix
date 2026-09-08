# GSC Schema Fix 5.2.0

A rebuilt WordPress website audit and reversible metadata repair plugin. Requires WordPress 6.2+, PHP 7.4+, DOM and SimpleXML. PHP OpenSSL is required only for the optional Google connection.

**Version 5.2:** website-type detection before every scan, six repair toggles, expanded automatic repairs, retryable remaining candidates, cache-provider integration and clearer coverage reporting. See [AUTO-FIX.md](AUTO-FIX.md) for the complete current repair matrix and limits.

## Install or upgrade

1. Back up the site and test this major upgrade on staging.
2. Upload `gscerrorfix-main-5.2.0.zip` in **Plugins → Add New → Upload Plugin**. Replace the existing plugin when WordPress offers that option. The plugin folder and main filename match the supplied archive.
3. Activate it and open **Settings → GSC Schema Fix**.
4. Click **Scan Website**. Leave the page open for faster processing. You can return and click **Resume**; WordPress cron also processes saved work.
5. Once discovery and scanning finish, click **Auto Fix Solvable Issues**. The button shows the number of repair candidates and is disabled if none qualify. Review remaining findings and the verified repair history.

**Ahrefs support:** import a ZIP/CSV/TSV in the dashboard, then run a fresh scan. Version 5.1 adds link/asset status checks, redirect-chain diagnostics, headings, editorial metadata guidelines, Open Graph supplements and restoration of existing image alt text. See [AHREFS.md](AHREFS.md) for coverage, limits and external-check privacy.

The new build does not run the old generator, overwrite SEO-plugin output, force indexing, redirect missing pages to the homepage, or fabricate commerce data. Version 4 settings and any content it previously changed are preserved in the database, but the old settings no longer control this version. Review previously generated product identifiers, excerpts, ratings and policies manually. Existing WooCommerce/SEO-plugin schema remains the responsibility of its source provider. This is a major replacement of the old workflow, not a settings-compatible minor release.

## Coverage

Discovery includes the homepage, posts page, published public/viewable posts, pages and custom types, public nonempty taxonomy archives, post-type archives, and same-site sitemap entries. Published content is enumerated in 100-ID batches with no 500-post ceiling. Sitemap indexes are followed, and duplicate URLs are queued once. Drafts, revisions, password-protected content and attachments are not enumerated from WordPress. URLs supplied through sitemaps or the additional URL field can still be inspected, but protected/private content is never eligible for repair.

Enter old URLs from a Search Console export in **Additional URLs** to check 404s or redirects that no longer exist in WordPress. Enter custom sitemap URLs when needed. Each custom URL field allows 200 entries; larger lists can be supplied through XML sitemaps.

This is not a comprehensive crawler of every link or infinite URL variation. Author/date archives, paginated URLs, arbitrary endpoints, translations on other hosts and JavaScript-only routes require sitemap entries or explicit URLs. URLs published after a scan's initial ID snapshot are included in the next scan. Archive membership can change during scanning. Redirect destinations are queued and checked within the resource allowance. External hosts are checked only when the separate external-destination option is enabled. Local HTTP checks do not prove Googlebot can access the same content.

| Check | Automatic action / result |
| --- | --- |
| Missing HTML title | Add the existing published title if no title element exists |
| Missing meta description | Derive text from the existing excerpt/content without executing shortcodes |
| Missing canonical | Add the WordPress canonical for an eligible singular permalink |
| Missing preferred image metadata | Add `og:image` from the existing featured image |
| Missing structured data on general content | Add a factual `WebPage` object only when no JSON-LD, microdata or RDFa exists |
| HTTP errors, redirects, network/TLS failures | Report status and review guidance; no arbitrary redirects |
| Meta/X-Robots noindex and global search visibility | Report and preserve intent |
| Single empty description and relative same-site canonical | Repair when unambiguous; conflicting providers and alternate absolute canonicals require review |
| Invalid JSON-LD and baseline rich-result fields | Report for factual source-data correction; not full schema validation |
| Sitemap availability, XML validity, robots.txt root rules | Report; discover same-site sitemap URLs |
| Missing viewport/language | Optional supplements using site configuration |
| HTTP resource references on HTTPS | Report for resource availability review |
| Retired schema rich-result features | Explain retirement; do not invent replacements |
| Google indexed status and rich-result issues | Optional read-only URL Inspection; separate from local findings |

Missing descriptions, canonicals, images or generic schema are often **SEO suggestions**, not Search Console errors. A canonical is a hint; a different Google-selected canonical or an intentional excluded URL is not necessarily a fault.

## Repairs, verification and undo

Repairs are small per-post flags. They supplement missing anonymous public HTML information and repair selected empty/relative metadata and verified permanent redirect anchors. Missing image alt can use existing Media Library text after matching the actual image URL; explicit empty alt remains untouched. Stored text, titles and images must also occur in the anonymous response before being used, to avoid exposing content hidden by membership/paywall providers. They do not edit page content, post titles, excerpts, database URLs, commerce facts, robots rules or populated third-party metadata. HTML bodies are not reserialized. Private/noindex pages and pagination are excluded from repair.

Auto Fix rechecks the current URL before changing flags and fetches it again afterward. Only findings confirmed absent in a successful HTML response count as fixed. Unverified additions are rolled back. A failed verification may be caused by a page cache, CDN, disabled loopback access or an unusual theme; its report says so. A Google indexed snapshot is never marked repaired just because a local fix succeeded.

The repair history retains before/after flags. **Undo** restores the prior flags only when newer changes have not overwritten them. Undo newer entries first. Deactivating the plugin stops all supplements, although external caches must be cleared. The last 20 repair entries are visible; all entries remain in the database.

Use the `gscsf_purge_url_cache` action to integrate a host/CDN's documented cache purge API:

```php
add_action('gscsf_purge_url_cache', function ($url, $post_id) {
    // Invoke your cache provider's documented per-URL purge here.
}, 10, 2);
```

Automatic cache-provider purges are not assumed. Clear page/CDN caches before scanning and after undo. Review changes using anonymous page views; supplements intentionally do not run for signed-in visitors.

## Optional Google Search Console setup

Local scanning needs no Google credentials. To inspect Google's indexed snapshot:

1. In Google Cloud, enable the **Google Search Console API** and create a service account with a JSON key.
2. Add its `client_email` as a user with suitable access to the intended Search Console property. It does not need Google Cloud project-owner access.
3. Save the key on the server **outside the publicly served web root**, readable only by the PHP account and administrators. Do not upload it to the media library or this plugin folder.
4. Add this server configuration before WordPress's “stop editing” line in `wp-config.php`:

```php
define('GSCSF_SERVICE_ACCOUNT_FILE', '/private/path/search-console-service-account.json');
```

5. Enter the exact property identifier (`sc-domain:example.com` or `https://example.com/`) in this plugin's settings, select **Send scanned page URLs to Google**, and save.

The plugin signs short-lived JWT assertions, exchanges them for OAuth tokens and refreshes tokens automatically. Only the `webmasters.readonly` scope is requested. Page URLs and the property identifier are sent to Google's URL Inspection service. No page content is submitted to Google. External resource checks, when separately enabled, request the linked public URLs from their destination servers without WordPress cookies. Keys are not stored in WordPress settings or included in reports. Access tokens and indexed results are cached in WordPress transients; protect database access accordingly.

Google inspections are cached for 24 hours. The plugin permits up to 1,900 new calls per property per rolling 24 hours. Google's documented property limit is 2,000 daily inspections, shared with your other tools. Rate-limit/authentication/server errors trigger a backoff. Deferred or failed inspections are explicitly reported; the rest of the local scan continues. Rescan after the quota/backoff period to inspect deferred URLs. This version does not automatically prioritize deferred URLs ahead of all earlier URLs on very large sites; use additional targeted sitemaps or change the inventory through WordPress filters if needed.

URL Inspection reports Google's **indexed version**, not a live test. The API cannot force crawling, request general-purpose bulk indexing, or submit “Validate Fix”. The plugin does not use the Indexing API for ordinary pages. Submit sitemaps and validate fixes in Search Console itself.

## Scheduling, storage and security

- Daily scans and automatic repair after scanning are separate opt-in settings. The manual Auto Fix button works without enabling automatic mode.
- One URL is processed per request. WordPress enumeration uses 100 items per request. Scanning continues via admin AJAX or cron; a database lock prevents simultaneous workers. Larger sites should use a server-triggered WordPress cron.
- HTTP responses have a 4 MiB limit. Sitemap files exceeding that limit are reported as incomplete; split large sitemap files. Requests have timeouts and do not bypass TLS verification, loopback protection or firewalls.
- Two per-site database tables hold the latest scan and repair history. A new scan replaces the previous scan report but retains repairs and history. Scan tables are not autoloaded.
- Administrative actions require `manage_options` and a WordPress nonce. Report content is rendered as text. No public mutation endpoints are exposed. Optional external destination requests remain subject to WordPress safe HTTP validation and the resource limits.
- Multisite: activate and configure on each site. Each uses its own prefix, options, permissions and property. There is no network-wide audit dashboard.
- Uninstall retains data by default. To remove this version's data on the current site, define `GSCSF_DELETE_DATA` as `true` before deleting the plugin. For multisite, clean each site's data separately. Version 4 content/settings are intentionally not deleted automatically.

## What cannot be promised

No WordPress plugin can automatically solve every possible Google Search Console issue or guarantee indexing, rankings, rich results or a zero-error report. Content quality, soft 404 interpretation, manual actions, hacked content, hosting failures, Core Web Vitals and video eligibility need evidence and appropriate human or hosting changes. This plugin performs baseline schema checks, not Google rendering or a complete validator of every rich-result type and policy.

After repairs, use Search Console's live inspection and Validate Fix where available, the Rich Results Test, and PageSpeed Insights. Allow Google to recrawl. Read [GOOGLE-UPDATES.md](GOOGLE-UPDATES.md) for the documented update review and [CHANGELOG.md](CHANGELOG.md) for this release's changes.
