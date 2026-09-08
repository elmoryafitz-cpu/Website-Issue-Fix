# Google update review — 8 September 2026

Sources reviewed are official Google documentation, changelogs and API references. This is a review of changes relevant to this plugin, not a claim that every Search Console UI feature or undocumented behavior is implemented.

| Update or current rule | Treatment in version 5 |
| --- | --- |
| FAQ rich results retired in May 2026; documentation removed in June | No FAQ rich-result generation/promise; existing FAQ markup gets an informational note |
| HowTo and several other dedicated rich-result experiences retired | Retired-type notes; no invented schema replacements |
| July 2026 canonical troubleshooting clarification | Preserve canonical intent and explain delayed Google re-evaluation |
| March 2026 robots-meta clarification | Inspect robots meta throughout HTML plus X-Robots-Tag; never blindly remove noindex |
| March 2026 preferred-image guidance | Allow missing og:image to be filled from an existing featured image |
| 2026 product properties, sale-date guidance and review transparency | No fabricated price validity, identifiers, ratings, shipping, returns or product attributes; source plugins remain authoritative |
| June 2026 generative-AI performance reports and July social/video platform properties | These are reporting features, not automatic website error-repair APIs; use native Search Console |
| AI feature guidance | Remove unsupported promises that special AI schema or keyword meta tags guarantee AI visibility |
| URL Inspection API | Optional indexed-status and rich-result evidence with read-only authentication, caching and quota/backoff handling |
| General indexing | No bulk request-indexing feature for ordinary WordPress pages; use sitemaps and native inspection |

Official references:

- [Search documentation changelog](https://developers.google.com/search/updates)
- [Search Central blog and release archive](https://developers.google.com/search/blog)
- [Generative-AI performance reports](https://developers.google.com/search/blog/2026/06/gen-ai-performance-reports)
- [Search result feature simplification](https://developers.google.com/search/blog/2025/06/simplifying-search-results)
- [URL Inspection method](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect)
- [API quotas](https://developers.google.com/webmaster-tools/limits)
- [Service-account OAuth](https://developers.google.com/identity/protocols/oauth2/service-account)
- [Canonical URL guidance](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls)
- [Structured-data policies](https://developers.google.com/search/docs/appearance/structured-data/sd-policies)
- [Product snippet requirements](https://developers.google.com/search/docs/appearance/structured-data/product-snippet)
- [Video structured data](https://developers.google.com/search/docs/appearance/structured-data/video)
- [JobPosting structured data](https://developers.google.com/search/docs/appearance/structured-data/job-posting)
- [Request recrawling](https://developers.google.com/search/docs/crawling-indexing/ask-google-to-recrawl)
- [Indexing API supported use](https://developers.google.com/search/apis/indexing-api/v3/using-api)

Future Google changes require a reviewed plugin release. The plugin does not download or execute new repair rules from news feeds.
