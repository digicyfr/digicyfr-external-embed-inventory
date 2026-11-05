# QA — 0.1.0

## Public GitHub prerelease ZIP (2026-11-05)

- ZIP: [digicyfr-external-embed-inventory-0.1.0.zip](https://github.com/digicyfr/digicyfr-external-embed-inventory/releases/download/v0.1.0/digicyfr-external-embed-inventory-0.1.0.zip)
- SHA-256: `db619049d20c6f230071fc4c6bfc471cb9c052af8787b9ed85e7e806eb06f209`
- The downloaded public ZIP passed its published `.sha256` sidecar and standalone `tools/verify_zip.py --against-source` checks: 14 release files match the current source byte for byte. The release branch and tag point to these runtime bytes.
- Fresh disposable WordPress 7.1/PHP 8.3.33 install, activation/deactivation/reactivation, and all 49 exact-ZIP contract assertions passed using the adapted standalone Docker harness. The release branch and tag GitHub Actions checks passed.
- The later candidate changed the packaged plugin header/README and repository tooling; scanner, storage, authorization and admin behavior are unchanged. Plugin Check below ran on the earlier candidate hash and has **not** been repeated on this public ZIP.

## Post-tag browser check on the exact public ZIP (2026-11-05)

The ZIP above was downloaded from the public GitHub prerelease, checksum-verified, and installed on a fresh disposable WordPress 7.1/PHP 8.3.33/MariaDB 11.4.13 site. Synthetic saved-content fixtures were used; no customer content or external host was fetched.

- Real administrator Start and Continue actions completed a scan of five published posts/pages with zero skips. The host report showed three normalized external hosts and five supported source rows. An ordinary hyperlink remained outside the embed inventory. [Complete host report](evidence/0.1.0/complete-host-report.png) and [source rows](evidence/0.1.0/source-rows.png).
- The coverage report included all five published posts/pages, including one without a supported embed. The exact-host, `core/embed` source-kind and hint-evidence filters returned one provider hint row. [Coverage](evidence/0.1.0/coverage.png) and [filtered provider hint](evidence/0.1.0/filtered-provider-hint.png).
- The browser downloaded a six-line CSV (header plus five rows). It contained direct, provider-hint and potential-lazy evidence, and omitted saved URL paths, query tokens and ordinary-link hosts. The synthetic CSV remains private test output and is not committed.
- At a 390px viewport, the document width was 390px and the 560px source table scrolled inside its 368px container; there was no page-wide horizontal overflow or browser script error. [390px source report](evidence/0.1.0/source-rows-390px.png).
- After a saved-content edit with the **same** `post_modified_gmt` second, all three prior source rows for that page showed `Stale: Yes`, and its coverage entry showed `Stale or unavailable`. The content SHA-256 changed while the timestamp stayed fixed. [Same-second stale report](evidence/0.1.0/same-second-stale.png).
- Both disposable containers and volumes were removed after the check.

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

- This is a public GitHub prerelease for manual, self-hosted installation. Plugin Check's WordPress.org-specific error and warnings from the earlier ZIP remain open for any WordPress.org submission.
- No manual screen-reader, broader browser matrix, or representative production-host testing has been performed. The HTTP race harness observed one Continue/Cancel ordering; it did not enumerate every possible timing.

## Automated accessibility audit on the public ZIP — 2026-11-05

The exact public ZIP and SHA sidecar above were verified again and installed on a fresh disposable WordPress 7.1 / PHP 8.3.33 site with synthetic saved embed content. Chromium 156.0.8078.4 and axe-core 4.10.3 ran WCAG 2/2.1/2.2 A/AA rule tags on the scan overview and completed source-row report. The plugin regions and the full source-row page had **zero axe violations**; the WordPress Dashboard baseline also had zero. At 390px, both screens had document width 390px. Keyboard Tab moved from **Exact host** to **Source kind**; Chromium's accessibility tree exposed named host, kind, evidence and Filter controls. Axe could not determine native WordPress select contrast because its background uses an image; other inconclusive full-page nodes were outside the plugin region. See [machine-readable audit evidence](evidence/0.1.0/axe-2026-11-05.json).

This automated check did not find a plugin-owned defect, so the `v0.1.0` ZIP, tag, and release branch were not changed. A human screen-reader pass and representative host-content review remain open.
- A high-water/cursor pass observes saved content when each post reaches the cursor, not a single immutable database snapshot. Theme, page-builder, dynamic, CSS, JavaScript and browser network requests remain outside this saved-content inventory.
- The scanner intentionally retains only normalized ASCII host and port, not full discovered URLs. Some hostname values can still identify users or organizations; keep report and CSV administrator-only.
