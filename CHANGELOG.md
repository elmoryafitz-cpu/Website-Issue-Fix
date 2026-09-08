# Changelog

## 5.3.0 — 2026-09-08

- Pause previous work on activation, deactivation and upgrade; retain reports as saved and preserve verified repair flags/history. Require explicit re-enabling of daily automation.
- Add one-click Scan & Auto Fix and explicit Scan Only, with per-run repair consent.
- Automatically discover common sitemap locations, robots.txt-declared sitemaps and known WordPress old slugs. Distinguish guessed sitemap locations from declared/configured failures.
- Move optional Ahrefs imports, manual overrides and scheduling under collapsed advanced options.
- Process up to six sequential units per worker request with a four-second between-unit budget and 150 ms browser pacing.
- Reuse scan-scoped redirect probes, cache repeated HTML facts/text within a request, avoid redundant queue inserts/updates and reduce duplicate UI rendering. Fresh repair verification is preserved.
- Add 21 activation, scheduling, discovery and performance regression checks.

## 5.2.0 — 2026-09-08

- Detect website type and public-content/commerce/SEO evidence before every scan.
- Add six accessible repair-group toggles, a detect button, finding filters, coverage details and exact repair codes in history.
- Add single-empty-description, relative-canonical, language, viewport, saved image-dimension and verified permanent internal-link repairs.
- Preserve failed/remaining repair candidates for a new manual pass; accumulate successful fixes across passes.
- Resolve eligible imported/discovered page URLs to WordPress posts.
- Purge supported WP Rocket/LiteSpeed caches before verification and on undo/rollback.
- Add portable regression tests and updated repair/coverage documentation.
- Keep Google-only actions and unavailable report coverage explicitly separate from local automatic repairs.

## 5.1.0 — 2026-09-08

- Added bounded Ahrefs ZIP/CSV/TSV import with UTF-16 support, duplicate-file detection, domain separation and utility-page exclusion. Export patch columns are never applied.
- Added H1, metadata-length, Open Graph, hreflang, nofollow, observed-inlink and sitemap-membership diagnostics.
- Added link/image/CSS/script destination checks with source references, optional external fetching, redirect-chain/loop summaries and explicit scan limits.
- Added verified/undoable missing Open Graph supplements and restoration of absent image alt attributes from matching Media Library data.
- Added safe database migration preserving the existing scan and repair history.
- Added source-export reconciliation and extension tests.

## 5.0.0 — 2026-09-08

- Replaced the 4.1.3 product-centric engine and 500-item scan ceiling with resumable WordPress and sitemap discovery.
- Added fetched HTML/HTTP diagnostics across public WordPress content, taxonomy archives and post-type archives.
- Added an Auto Fix action available only after a complete scan, independent of the optional automatic-mode switch.
- Added conservative per-post metadata supplements, fresh preflight checks, HTTP verification, rollback of failed additions and repair undo.
- Added optional read-only Google URL Inspection via a server-side service-account key, token refresh, quota budget, cache and backoff.
- Separated informational SEO improvements, local findings and indexed Google evidence.
- Removed invented product identifiers, prices, ratings and policies; universal noindex/canonical overrides; blanket homepage 404 redirects; unsupported AI/FAQ/HowTo rich-result claims.
- Preserved existing content and legacy settings for manual review. The old schema generator is no longer used.
- Added per-site report storage, an atomic worker lock, paginated safe admin rendering, nonce/capability checks and scoped outbound HTTP requests.
- Added the Google update review and explicit coverage/verification limits.

Original 4.1.3 source is preserved separately in the extracted source folder accompanying this delivery.
