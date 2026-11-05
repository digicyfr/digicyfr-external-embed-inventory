# External Embed Inventory — 0.1.0 release notes

## What this release does

Local inventory of supported saved external resource references and provider hints; bounded manual scans; private paged report and guarded CSV; English and Polish admin text.

## Requirements and tested stack

Requires WordPress 7.1+, PHP 8.3+ and InnoDB. WordPress 7.1, PHP 8.3.33, MariaDB 11.4.13. Exact-ZIP contract, browser keyboard, 390px and 1,000-post scale checks passed. Manual screen-reader, broader browser and production-host validation are outstanding.

The exact public GitHub release ZIP and SHA-256 sidecar were downloaded and checked again after tagging. A fresh WordPress admin browser run completed a five-post scan, host/source/coverage reports, host/kind/evidence filtering, guarded CSV download, 390px layout and same-second content-staleness display. An automated Chromium/axe-core audit found zero WCAG A/AA rule violations in the plugin's scan overview and source-row report. Native WordPress select contrast was inconclusive to axe; browser automation does not certify WCAG conformance. [Dated QA and screenshots](docs/QA-0.1.0.md) record the results. The earlier WordPress.org Plugin Check result was collected on a prior ZIP candidate, so it is not evidence that the published ZIP passed that tool.

## Known limits

No network requests to found hosts. It does not inspect rendered or dynamic output and does not make privacy/compliance claims. Hostnames may still identify people or organizations. The scan is a best-effort high-water pass, not an immutable snapshot; rescan after content or catalog changes. See [README.md](README.md) for full scope and lifecycle details.

**Release ZIP SHA-256:** `db619049d20c6f230071fc4c6bfc471cb9c052af8787b9ed85e7e806eb06f209`. Verify downloaded bytes against the sidecar before installing.

## Install and update

Download the versioned ZIP and SHA-256 sidecar from the GitHub release for this repository. Verify the checksum, then use **Plugins → Add New → Upload Plugin** in WordPress. For updates, back up the site and database, upload the newer ZIP, and verify the plugin version and expected behavior. Version 0.1.0 has no automatic update endpoint; the `Update URI` header only prevents unrelated directory updates. Deactivation retains private scan data; uninstall deletes plugin-owned data.

## Source and support

Source: https://github.com/digicyfr/digicyfr-external-embed-inventory. Report ordinary bugs through Issues; use [SECURITY.md](SECURITY.md) for private vulnerability reports. The GPL-2.0-or-later license is in [LICENSE](LICENSE). Detailed local QA, including open gates and the exact ZIP hash, is in [docs/QA-0.1.0.md](docs/QA-0.1.0.md).

This stable release targets Digicyfr/GitHub self-hosted distribution. WordPress.org Plugin Check reports an updater-policy error for the self-hosted `Update URI`, so this package is not a WordPress.org submission.
