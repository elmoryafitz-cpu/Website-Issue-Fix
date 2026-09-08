# Ahrefs audit support — version 5.1.0

## Import and scan

Open **Settings → GSC Schema Fix → Import an Ahrefs audit**, select the ZIP/CSV/TSV export, then click **Import Audit**. This replaces the previous imported URL list; it does not change website content. Click **Scan Website** afterward. Auto Fix becomes available when discovery, fetching and redirect/sitemap checks finish.

The importer supports Ahrefs UTF-16 tab-separated exports, UTF-8 CSV/TSV, quoted multiline cells and duplicate files. It recognizes page-level `URL` and link-level `Source URL` reports. Only explicit URL columns from matching-site rows become scan seeds. Patch fields, descriptions and instructions inside exports are never applied as changes. Off-site rows and WordPress login/admin URLs are counted and excluded. A ZIP may contain reports for several websites; configure/import separately on each matching WordPress installation.

Uploads are read from PHP's temporary upload location without extracting archive paths or publishing files. The plugin stores the report summary and deduplicated URL list, not the uploaded ZIP. Limits: 32 MiB uploaded file, 200 ZIP entries, 8 MiB per expanded CSV, 64 MiB total expanded CSV data, 100,000 rows and 5,000 matching URLs. Server upload limits may be lower. Oversized/malformed archives are rejected; the URL ceiling is explicitly reported. Non-CSV archive entries are ignored.

## Added checks and repairs

| Ahrefs category | Plugin behavior |
| --- | --- |
| Missing image alt attribute | Restore existing Media Library alt text when the exact image/attachment match can be established; verify and support undo |
| Empty image alt | Preserve it, because it may intentionally identify a decorative image |
| Missing Open Graph fields | Supplement missing title, description, URL and type from existing public content; preserve existing fields |
| Empty/duplicate Open Graph fields | Report the conflict for the source provider to correct |
| Missing/empty or multiple H1s | Diagnose; do not rewrite page-builder or theme headings automatically |
| Long titles, short/long descriptions | Report editorial guidelines; do not arbitrarily truncate content |
| Internal broken links and image/CSS/script failures | Fetch destinations and show status plus up to five referring URLs |
| Redirects, broken redirects, redirect loops/chains | Queue destinations and inspect the fetched graph after scanning; never choose replacement content automatically |
| External broken/redirected links/assets | Optional external destination checks; disabled by default |
| CSS size | Flag fetched CSS bodies over a 150 kB guideline; no automatic minification or deletion |
| Slow pages/server response | Measure full HTTP fetch time against a 3-second guideline, clearly distinguished from TTFB, browser load and Core Web Vitals |
| Missing sitemap membership | Compare published URLs against successfully parsed sitemap entries; preserve intentional exclusions |
| Internal nofollow | Report observed links without changing rel attributes |
| Only one inlink | Report one observed referring URL within the scan, not a claim of exhaustive link counts |
| Hreflang/x-default | Report missing optional x-default and conflicting language destinations; do not invent translation mappings |
| Noindex/login-page metadata and H1 warnings | Recognize utility pages and preserve noindex rather than making login pages indexable |

Auto Fix acts only on fresh locally verified candidates. Importing a report does not make its old statuses current, nor does it guarantee an Ahrefs health-score increase. A 403/429 can be a firewall or rate-limit result, not proof that an image is missing. Third-party reports may use different crawler identities, rendering and thresholds.

## Resource-check coverage

The scanner extracts ordinary HTML anchors, image `src`, stylesheet links and script `src` from scanned pages, respecting a `base` element. It queues up to 200 unique destinations per page and 3,000 additional resource URLs per run; limit notices remain visible. Native WordPress content inventory still has no 500-item ceiling. Resource pages are status-checked, not recursively crawled as new content pages unless independently discovered through WordPress, a sitemap or import. Redirect targets are queued within the same resource allowance. Chain analysis stops after 10 hops and reports unconfirmed destinations.

External checks, when enabled, issue ordinary safe HTTP requests without WordPress cookies or authorization headers. Private-network/unsafe destinations remain subject to WordPress's safe HTTP validation. Redirects are fetched as separate queue items so an external hop cannot silently bypass scope. Disabling external checks means external destinations remain explicitly unverified.

This is bounded server-HTML inspection. It does not render JavaScript, inspect every `srcset`, CSS background, lazy-loading attribute, hreflang return-link matrix or infinite query URL. Sitemap and observed-inlink conclusions are correspondingly limited. Resource limits, fetch failures and timeouts are reported rather than treated as passes.

Restoring an alt attribute uses the current saved Media Library description. It does not invent descriptions from filenames, overwrite explicit empty alt, change captions or mutate Media Library records. Inline alt supplements are active while the plugin is active and can be undone through the existing history mechanism. A media file returning 403/404 still needs its separate access/file issue corrected.

## References

- [Ahrefs Site Audit issue documentation](https://help.ahrefs.com/en/collections/87920-site-audit)
- [Ahrefs missing H1 explanation](https://help.ahrefs.com/en/articles/2764262-h1-tag-missing-or-empty-warning-in-site-audit)
- [Google title guidance](https://developers.google.com/search/docs/appearance/title-link)
- [Google description/snippet guidance](https://developers.google.com/search/docs/appearance/snippet)
- [Google x-default explanation](https://developers.google.com/search/blog/2023/05/x-default)
- [W3C guidance on decorative image alt](https://www.w3.org/WAI/tutorials/images/decorative/)
- [WordPress HTML Tag Processor](https://developer.wordpress.org/reference/classes/wp_html_tag_processor/)
