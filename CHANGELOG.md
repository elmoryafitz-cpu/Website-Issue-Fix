# Changelog

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
