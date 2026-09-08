# Supplied plugin audit

Source reviewed: `gscerrorfix-main.zip`, supplied version 4.1.3. The original extracted files remain unchanged in the delivery workspace's `source/gscerrorfix-main` folder.

## Findings addressed

- The scan stopped at 500 published content items, so its whole-site claims exceeded actual coverage.
- General content scanning had been added, but diagnostics and repairs remained heavily product-oriented.
- The repair routine could generate price, SKU, GTIN and MPN defaults from arbitrary values or post IDs, and assign shipping/return-policy defaults. These are business facts that cannot safely be inferred this way.
- Default rating/review generation and ecommerce copy could imply claims unsupported by existing content.
- Robots handling forced index/follow on singular content, potentially overriding intentional exclusions.
- The canonical filter forced self-reference, even when another canonical was intentional.
- Every 404 could be redirected to the homepage, obscuring genuine missing pages and creating misleading behavior.
- A repair could be counted as fixed without confirming that public output changed or the original finding disappeared.
- The UI already contained an Auto-Fix button, but it was tied to the old limited workflow. The new button is gated on a completed scan and uses verified repair candidates.
- The old AJAX scan/fix endpoints used a different nonce action from the general localized admin nonce, preventing the old workflow from operating consistently.
- The old interface interpolated finding titles/messages into HTML. The new report builds text nodes and validates link protocols.
- No authenticated Search Console inspection implementation was present. Version 5 adds optional read-only indexed evidence without presenting local guesses as Google results.

Version 5 replaces these mechanisms rather than retaining the old unsafe engine behind compatibility switches. See README.md for the feature/migration consequences.

## Upstream update check

The public GitHub latest-release endpoint for the repository advertised by the supplied plugin returned HTTP 404 during this review. A public newer release could not be established. Version 5.0.0 is the locally prepared update in this delivery; it has not been published as an upstream release or installed on your live website.
