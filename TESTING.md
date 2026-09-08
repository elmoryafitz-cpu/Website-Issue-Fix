# Validation report — 5.2.0

Tested 8 September 2026 in an isolated WordPress 7.1 installation, PHP 8.3.33, and the WordPress Performance Team's SQLite Database Integration. The runtime, database and test-only fixtures are outside the release ZIP. No live customer website or Google account was modified.

## Version 5.2 validation

- All 40 existing integration assertions and 23 Ahrefs assertions pass against the updated build.
- 25 new regression assertions cover empty metadata, canonical preservation, language/viewport, permanent/temporary/broken/external links, fragments, base elements, responsive images, toggles, general/commerce detection, scan phase ordering, repair transactions and undo.
- Real HTTP confirms detection and coverage AJAX, six repair controls and filters in dashboard HTML, existing authorization/nonce checks, and anonymous repair/undo.
- PHP and JavaScript syntax checks pass. Cache-provider APIs are documented integrations; live WP Rocket/LiteSpeed installations were not available.
- Installable ZIP excludes Git metadata and test scripts.

## Passed

- PHP syntax checks for all shipped PHP files.
- JavaScript syntax check with Node 24.20.0.
- Actual WordPress installation, activation and database-table creation.
- 40 integration assertions against real WordPress APIs and storage, with deterministic mocked HTTP/Google responses:
  - More than 520 discovered URLs, paginated report, nested sitemap discovery and duplicate prevention.
  - Pages and public noncommerce custom types; protected, private and draft inventory exclusions.
  - Metadata supplements, idempotence, unchanged page body, SEO-provider preservation and no fabricated Product schema.
  - Meta and HTTP noindex, global privacy, membership/paywall text protection, credential-bearing/external URL rejection, 404 reporting, invalid JSON-LD and legitimate zero prices.
  - Head insertion with apparent closing tags inside scripts/comments.
  - Atomic lock ownership, manual repairs with automatic mode off, verified finding counts, undo and rollback after cache-obstructed verification.
  - Complete automatic scan-then-repair processing over 500 pages, more than 1,000 verified metadata findings and scheduled continuation.
  - Google indexed/rich-result mapping, cache reuse, rate-limit backoff and rolling quota deferral.
- Real anonymous HTTP requests through WordPress and its theme confirmed output-buffer supplements appear and disappear after undo.
- Real admin HTTP requests confirmed dashboard HTML and script configuration, anonymous request rejection, invalid nonce rejection, authenticated report access, scan start, premature Auto Fix rejection and cancellation.

## Ahrefs extension validation

- Re-ran the 40 regression assertions against version 5.1.0.
- Passed 23 additional assertions: actual 75-file ZIP parsing (40 distinct files, 35 duplicates), UTF-16/UTF-8/multiline handling, scope separation, ignored patch columns, malformed-row rejection, utility-route recognition, exact attachment matching, saved-alt restoration and verification, decorative-alt preservation, Open Graph additions, relative/base URL resolution, external checks off by default, broken redirect/loop analysis, oversized CSS, 403 distinction, source references and migration preserving existing rows.
- Both Node and PHP reconciliation found the same 40 distinct input files. The matching-site importer produced 455 non-utility URLs from 11,116 rows across distinct files. These are historical audit data, not a live crawl of either supplied domain.
- Database upgrade from the 5.0 schema was tested with existing rows; new nullable text fields preserve existing data.
- Real HTTP multipart upload tested the import form, CSV acceptance, foreign-only rejection, preservation of a prior import after rejection and clear-import action. The anonymous repair/undo and admin authorization/nonce tests were also rerun.

## Limits

- No connected browser was available, so visual rendering and mouse/keyboard interaction were not tested.
- No actual Google credentials/property were supplied. Google authentication/network behavior was implemented from official documentation; API response handling was tested with fixtures. Live token exchange and property permissions need validation during setup.
- The database integration test used SQLite, not a separate MySQL/MariaDB server. SQL uses WordPress's standard wpdb/dbDelta APIs and MySQL-compatible statements.
- The declared minimum WordPress/PHP versions were not run as separate runtime combinations. Actual runtime validation used the versions above.
- WooCommerce and third-party SEO/cache/multilingual plugins were not installed for a full compatibility matrix. Existing-provider HTML preservation was tested; source-specific behavior needs staging checks.
- PHP's single-worker local development server is unsuitable for running a browser AJAX scan that fetches itself. The real HTTP repair test ran from a separate CLI worker; deployed WordPress hosting must support loopback requests and concurrent PHP workers.

Reproduction scripts are supplied separately in the workspace's `tests` folder. They create local test content and must not be run against a production database.
