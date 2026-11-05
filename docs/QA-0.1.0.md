# Local QA — 0.1.0

## Current GitHub repository candidate (2026-11-05)

- ZIP: `dist/digicyfr-external-embed-inventory-0.1.0.zip`
- SHA-256: `db619049d20c6f230071fc4c6bfc471cb9c052af8787b9ed85e7e806eb06f209`
- The new repository snapshot adds a per-plugin GitHub `Plugin URI`, updates packaged README instructions, and adds standalone build/verification tools, Docker harness, CI, contribution/security policies and release notes outside the ZIP. Scanner, storage, authorization and admin behavior are unchanged.
- Both standalone and workspace `verify_zip.py --against-source` passed on this ZIP; its `.sha256` sidecar passed. PHP and JavaScript syntax checks passed.
- Fresh disposable WordPress 7.1/PHP 8.3.33 install, activation/deactivation/reactivation, and all 49 exact-ZIP contract assertions passed using the adapted standalone Docker harness. Manual screen-reader, broader browser and production-host validation remain outstanding.
- The browser and Plugin Check results below were collected on the earlier candidate hash; they were not rerun on the current packaged README/header revision. GitHub Actions has not yet run because the repository is not published.

## Earlier candidate evidence (ZIP SHA-256 7499cf318cdb39dce92075ab885ec47416f3813fc4a60b79e92a0a8750dc2141)

## Candidate identity

- ZIP: `dist/digicyfr-external-embed-inventory-0.1.0.zip`
- SHA-256: `7499cf318cdb39dce92075ab885ec47416f3813fc4a60b79e92a0a8750dc2141`
- `tools/verify_zip.py --against-source`: 14 of 14 release files match source bytes. The sidecar `.sha256` matches.
- Target tested: WordPress 7.1, PHP 8.3.33, MariaDB 11.4.13, InnoDB.

## Automated evidence on this exact ZIP

- Clean disposable WordPress install, activation, deactivation and reactivation passed with version 0.1.0.
- `tests/run-wordpress-contract.sh` passed 49 assertions against the installed ZIP. The test-only site locale was set to `pl_PL` before normal admin bootstrap; the bundled MO translated “External Embed Inventory” to “Wykaz zewnętrznych osadzeń” without manually loading the catalog.
- The contract checks URL classification and normalization, nested saved block/resource extraction once, provider versus direct evidence, malformed HTML failure, 11 posts in 10+1 batches, draft exclusion, zero-match coverage, no URL secret persistence, CSV formula neutralization, same-timestamp stale detection, oversize incomplete attempts retaining the previous report, retryable SQL errors, transaction rollback and same-cursor retry without duplicate counts, terminal coverage-query failure, post-commit pointer repair, forced digest collision, 201-host report pagination, late CSV page failure, coverage JOIN failure, and InnoDB table engines.
- PHP 8.3 lint passed for runtime and contract files. WordPress Coding Standards `phpcs --standard=WordPress` passed for release PHP files with the documented suppressions around fixed plugin-owned table identifiers, read-only admin filters, and private temporary file cleanup.
- Plugin Check ran against the exact installed ZIP. It reported one WordPress.org-specific `plugin_updater_detected` error for the intentional self-hosted `Update URI` and 25 warnings. The warnings cover bundled translation loading, fixed plugin-owned SQL table identifiers, and uninstall-local variable naming. This ZIP is for manual Digicyfr distribution; it is not claimed ready for the WordPress.org directory. The fixed table names originate from `DEEI_Storage::tables()` or the uninstall allowlist, and all data parameters use prepared statements.

## Final exact-ZIP browser and operational evidence

The frozen ZIP above was installed on two more fresh disposable WordPress sites. The complete evidence and raw screenshots/JSON/CSV are in `tools/wordpress-test/external-embed-final-evidence.md` and `tools/wordpress-test/evidence/external-embed-7499/`.

- Small site: real admin Start/Continue completed 10+1 posts, with 11 coverage records and 26 aggregate rows. The first batch exposed no exportable report. Direct DB checks matched all 26 expected keys/counts and 11 coverage hashes. Guest front-end HTML hashes and request URL sets matched before and after activation.
- Browser checks passed for nested provider hints versus direct resources, contributor view/action denial, private CSV and formula neutralization, URL path omission, same-timestamp zero-match staleness, cancel/restart/promotion retention, and named `post_too_large` incomplete behavior. At 390 px, the page stayed 390 px wide and the report table scrolled within its container; keyboard Tab reached Start and Enter applied a host filter. The Polish admin title and Start label appeared under `pl_PL`.
- Authenticated GET mutation and invalid-nonce POST each returned 403. Two concurrent Continue requests produced one exact 11-post run; a Continue/Cancel race ended cancelled with the previous complete report intact.
- A test-only SQL fault on the second 200-row CSV page produced HTTP 500 `text/html`, no attachment header, and left the active report unchanged. Without the fault, the 227-row export returned HTTP 200 `text/csv` and 228 lines.
- WordPress `delete_plugins()` removed all four plugin-owned tables and options while preserving 11 published source posts/pages and the draft. Both disposable sites and volumes were removed after testing.
- Scale site: exactly 100 authenticated Continue requests scanned 1,000 published posts/pages with 1,000 coverage records, zero skips and 1,015 aggregate rows in 9.865 seconds. Continue median/p95/max was 55/77/116 ms. Container cgroup peak was 342,908,928 bytes; this peak preceded the measured scan. Report pagination returned 20 rows on the first page and 15 on the last, and the 1,016-line CSV returned 133,934 bytes.

## Limits and open publication gates

- This is a locally accepted, self-hosted ZIP candidate. Plugin Check's WordPress.org-specific error and warnings remain open for any WordPress.org submission.
- No manual screen-reader, broader browser matrix, real merchant, or production-host testing has been performed. The HTTP race harness observed one Continue/Cancel ordering; it did not enumerate every possible timing.
- A high-water/cursor pass observes saved content when each post reaches the cursor, not a single immutable database snapshot. Theme, page-builder, dynamic, CSS, JavaScript and browser network requests remain outside this saved-content inventory.
- The scanner intentionally retains only normalized ASCII host and port, not full discovered URLs. Some hostname values can still identify users or organizations; keep report and CSV administrator-only.
