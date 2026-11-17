# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 2.0.x | Yes |
| 1.0.x | No. Update to the latest 2.0.x (one click from 1.0.1) |

Security fixes are shipped as signed updates. Install them from **Settings → System → Updates**.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security problems.

- Use **"Report a vulnerability"** on this repository's *Security* tab (GitHub private vulnerability reporting), or
- contact Nexta Web Solution through [nextawebsolution.com](https://nextawebsolution.com).

Please include the affected version, steps to reproduce, and the impact you observed. We aim to acknowledge reports within 3 working days and to share a fix timeline within 10 working days. Test only against installations you own. Do not access other people's data.

## Verifying downloads

Every release ZIP is signed with Ed25519, and installed copies verify the signature and SHA-256 before updating. Download releases from [GitHub Releases](https://github.com/NextaWebSolution/nextacrm/releases) or [updates.nextawebsolution.com](https://updates.nextawebsolution.com/products/nextacrm), where the SHA-256 and signature of each version are listed. The `latest.json` attached to each GitHub release has the same values. GitHub's "Download ZIP" of a branch is unsigned source code, not a release.

## Hardening checklist

See section 6 of [`docs/INSTALL.md`](docs/INSTALL.md#6-production-security-checklist) and the v1.0 audit with its v2.0 addendum in [`docs/SECURITY_AUDIT.md`](docs/SECURITY_AUDIT.md).
