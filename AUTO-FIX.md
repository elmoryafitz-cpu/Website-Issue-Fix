# Website detection and Auto Fix in 5.3.0

Every scan starts by detecting commerce software, public content types, SEO providers and WordPress search visibility. WooCommerce, Easy Digital Downloads, BigCommerce and common catalog post types provide commerce evidence. An installed catalog is evidence of an ecommerce-capable site, not proof that checkout is operational. Unknown commerce integrations can still be scanned through public WordPress types and sitemaps. Product-specific diagnostics apply when Product markup is present; all other diagnostics run on both site types.

For normal use, click **Scan & Auto Fix**. No configuration or report upload is needed. **Scan Only** performs no repairs. Optional repair toggles, daily scheduling, Ahrefs imports and discovery overrides are under **Advanced options**. Detection also runs automatically before each manual or scheduled scan. The one-click action records automatic repair consent for that run. The separate automatic setting applies to scheduled daily scans. It is independent of the manual Auto Fix button.

## Automatic repair families

| Group | Repair | Conditions |
| --- | --- | --- |
| Metadata | Missing HTML title | Existing published title is also visible in anonymous HTML; no existing title element |
| Metadata | Missing or single empty description | Existing excerpt/content text appears publicly; duplicate descriptions require review |
| Metadata | Missing canonical | Eligible published singular permalink, no HTML or HTTP canonical |
| Metadata | Relative canonical | Nonempty relative URL resolves within the site; preserve its selected destination; no base element or HTTP canonical |
| Social | Missing og:title, og:description, og:url, og:type, og:image | Existing public title/text/featured image; preserve existing tags |
| Images | Missing alt attribute | Exact Media Library URL and existing saved alt; preserve explicit empty/decorative alt |
| Images | Missing width and height | Exact saved dimensions; preserve existing dimensions, inline styles and responsive art direction |
| Links | Permanent internal redirect anchors | Up to two unresolved same-site anchors probed per page; direct 301/308 to 200 HTML, no query parameters, external destination, download or base element; preserve fragments |
| Schema | Missing general WebPage JSON-LD | Existing public facts, no competing structured data, general content rather than commerce |
| Document | Missing language or viewport | Use configured WordPress language and standard responsive viewport; preserve existing values |

These are 16 repair families, with potentially many findings per website. Eligibility depends on the actual HTML and enabled groups; a website can legitimately have only four repairable findings. Some repairs are general SEO/accessibility improvements, not confirmed Search Console errors.

Repairs affect anonymous singular HTML while the plugin is active. Source content is not rewritten. Deactivation or undo stops the supplement after caches refresh. Changing a group toggle also controls previously stored flags. Title/schema/social safeguards can mean that a page qualifies for some repairs and not others.

The repair queue now retains remaining candidates and allows another manual pass. Each pass handles each eligible row once; it does not retry failed pages indefinitely. A new scan refreshes the current-run counts. Logs list the exact issue codes repaired. The report filter applies to the current 50-URL page.

WP Rocket's `rocket_clean_post()` and LiteSpeed's `litespeed_purge_url` are called before verification and after rollback/undo. Other page/CDN caches can integrate with `gscsf_purge_url_cache`. If stale HTML prevents verification, additions are rolled back and do not count as successes. Purges may be asynchronous. Clear external caches after disabling a repair group.

## Coverage and limitations

Local checks cover HTTP errors, redirect graphs, robots availability/root rules, noindex, canonical signals, sitemaps, baseline structured data, headings, metadata and bounded linked resources. The UI reports the website profile, candidates, verified repairs and which areas need an external connection or review.

Google URL Inspection provides indexed snapshots and available rich-result errors, not live indexing tests. It does not expose all Search Console reports or let a plugin resolve manual actions, security incidents, Core Web Vitals, all video indexing issues, content quality or Google's indexing/canonical decisions. A successful local repair is not a guarantee of Google indexing or rankings. Full JavaScript rendering, all robots user-agent/path combinations and complete rich-result validation are outside this scanner.

Activation/upgrades pause saved work and disable scheduling until re-enabled. Old results remain available as a clearly labelled saved report. Shared scan probes may be reused within the same scan; repair preflight and verification always request fresh evidence.

Sources reviewed 8 September 2026:

- [Google documentation updates](https://developers.google.com/search/updates)
- [Page indexing report](https://support.google.com/webmasters/answer/7440203)
- [URL Inspection API limitations](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect)
- [WP Rocket post cache API](https://docs.wp-rocket.me/article/93-rocketcleanpost)
- [LiteSpeed cache API](https://docs.litespeedtech.com/lscache/lscwp/api/)

## Regression test

The repository includes `tests/regression.php`. Set `GSCSF_WP_ROOT` to a disposable WordPress installation with this plugin active and `GSCSF_TEST_SITE=1`, then run `php tests/regression.php`. It changes test settings and creates test content; do not run on production. Network responses are mocked. Tests are excluded from the installable ZIP.
