# Digicyfr External Embed Inventory

Free, open-source WordPress plugin (GPL-2.0-or-later). Version 0.1.0 is a **local saved-content inventory**, not a network scan, request log, consent tool, or privacy verdict. It adds no front-end hook or output and sends no requests to found hosts.

## Use

Install the ZIP in WordPress, activate it, then open **Tools → External Embed Inventory** as an administrator. Click **Start scan** and **Continue** until the attempt completes. Each Continue handles at most ten published posts or pages. The previous complete report stays available during a new attempt. An incomplete attempt never replaces it. Use the paged host/source and coverage reports, and download the private CSV only from a complete report. Rescan after editing, publishing or unpublishing content. Updates of this self-hosted plugin are manual.

## Exact coverage

The scan reads saved `post_content` for published `post` and `page` items. It parses blocks recursively and scans each block's own saved HTML once. It records `core/embed` URL attributes as **provider URL hints**, and `iframe`, `script`, `embed`, `object`, `video`, `audio`, `source`, and `img` source attributes as **saved resource URLs**. `data-src` and `data-lazy-src` on those tags are **potential lazy URLs**. `srcset` and `srcdoc` are counted as unsupported. An ordinary link is not an embed. It does not run shortcodes, render blocks or fetch URLs.

Theme templates, widgets, menus, synced block targets, CSS URLs, JavaScript-created resources, `link` and `track` tags, page-builder postmeta, dynamic output, inline script strings and arbitrary block attributes are outside version 0.1. A zero-row report means no **supported saved external references** were found in its covered posts. It does not mean the site has no external resources or is compliant. A saved host may never receive a browser request.

The plugin normalizes ASCII host and retained port, preserving `www` and scheme distinctions. It never stores a discovered URL path, query or fragment. Relative references are unresolved, not assumed first-party. Non-ASCII/percent hostnames and protocol-relative same-site default-port ambiguity are counted separately. Only exact normalized `home_url()` and `site_url()` authorities are considered first-party.

## Limits, data and lifecycle

At most ten posts per request, 512 KiB of saved content per post, 300 candidates per post, 2,000 block nodes, 32 levels and 5,000 HTML tags are processed. A cap or parser skip yields an incomplete attempt and retains the prior complete report. Storage errors keep the cursor unchanged and may be retried. The high-water ID and cursor provide a best-effort pass, not an immutable snapshot; changes below the cursor may need another scan.

Four private InnoDB tables store a run ledger, normalized host rows, per-post coverage (including zero-match posts), and a singleton scan lock. Two non-autoloaded options identify the current attempt and active complete report. A hostname can itself contain an identifier, so report and CSV require `manage_options`. CSV text is guarded against spreadsheet formulas. Deactivation keeps report data. Uninstall removes only these plugin-owned tables and options and leaves posts/pages intact. Retention keeps only the active complete run plus the current attempt; a new start replaces an old incomplete/cancelled attempt.

Requires WordPress 7.1+, PHP 8.3+, and InnoDB. WordPress 7.1/PHP 8.3.33/MariaDB 11.4.13 are the local test target for this candidate; see `docs/QA-0.1.0.md` in this repository for actual evidence and open gates. No WordPress.org publication is claimed.

## Development

From this repository root:

```bash
find . -type f -name "*.php" -not -path "./dist/*" -print0 | xargs -0 -r -n1 php -l
find assets -type f -name "*.js" -print0 | xargs -0 -r -n1 node --check
python3 tools/build_plugin.py digicyfr-external-embed-inventory
python3 tools/verify_zip.py dist/digicyfr-external-embed-inventory-0.1.0.zip --slug digicyfr-external-embed-inventory --against-source
(cd dist && sha256sum --check digicyfr-external-embed-inventory-0.1.0.zip.sha256)
bash tests/run-wordpress-contract.sh dist/digicyfr-external-embed-inventory-0.1.0.zip
```

The integration command requires Docker Compose and a working Docker daemon. It installs the built ZIP on a disposable WordPress site and removes its volumes afterward.

## Source, support and security

Source and ordinary bug reports: https://github.com/digicyfr/digicyfr-external-embed-inventory and its Issues tab. For sensitive reports, follow [SECURITY.md](SECURITY.md). Contributions are welcome under [CONTRIBUTING.md](CONTRIBUTING.md). The public release snapshot is recorded on `release/0.1.0`; `main` is for ongoing work. See [RELEASE_NOTES.md](RELEASE_NOTES.md) and [docs/QA-0.1.0.md](docs/QA-0.1.0.md) before distribution.
