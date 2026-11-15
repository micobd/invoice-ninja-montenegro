# Technology Stack

**Analysis Date:** 2026-11-15

> Read-only analysis. `vendor/` and `node_modules/` are not installed, so no code or test ran.

## Base

- Invoice Ninja v5, version `5.13.43` (`VERSION.txt`). Flutter admin portal `5.0.194+194`.
- Upstream: <https://github.com/invoiceninja/invoiceninja>.
- License: Elastic License 2.0 (ELv2). See `LICENSE` and `NOTICE`.
  - ELv2 allows use and change. It forbids offering the software to third parties as a hosted or managed service.
  - If you copy fork code into your own product, the ELv2 terms come with it. Check this before reuse.

## Runtime

| Item | Value | Source |
|------|-------|--------|
| PHP | `^8.2` (platform pin `8.2.0`) | `composer.json:34`, `:210` |
| Framework | Laravel `^12` | `composer.json:72` |
| Database | MySQL 8 (Docker) | `docker-compose.yml` |
| Cache / queue | Redis 7 in Docker, `sync` in `.env.example` | `.env.docker.example:62`, `.env.example:27` |
| PDF | Chromium (headless) | `Dockerfile` |

This is not the ASEngine / jQuery stack from `C:\wamp2025\www\CLAUDE.md`. It is a Laravel app with a Flutter SPA.

## Packages that the fiscal module uses

| Package | Use | Where |
|---------|-----|-------|
| `robrichards/xmlseclibs` `^3.1` | XML-DSig signature (exc-c14n, SHA-256, RSA-SHA256) | `Montenegro/Xml/Signer.php` |
| `endroid/qr-code` `^5` | QR PNG for the verify URL | `Montenegro/QrCode.php` |
| PHP `ext-openssl` | IKOF signature, PKCS#12 read | `Montenegro/Ikof.php`, `Montenegro/Certificate.php` |
| PHP `ext-dom` | XML build and response parse | `Montenegro/Xml/*`, `Montenegro/Soap/*` |
| Laravel `Http` facade (Guzzle) | SOAP POST | `Montenegro/Soap/CisClient.php` |

## Configuration

`config/services.php:179`:

| Key | Env var | Default |
|-----|---------|---------|
| `production_url` | `MONTENEGRO_FISCALIZATION_URL` | `https://efi.tax.gov.me/fs-v1` |
| `test_url` | `MONTENEGRO_FISCALIZATION_TEST_URL` | `https://efitest.tax.gov.me/fs-v1` |
| `test_mode` | `MONTENEGRO_FISCALIZATION_TEST_MODE` | `true` |
| `fiscal_log_retention_days` | `MONTENEGRO_FISCAL_LOG_RETENTION_DAYS` | `3650` |
| `fiscal_log_export_limit` | `MONTENEGRO_FISCAL_LOG_EXPORT_LIMIT` | `50000` |

The per-company test mode is a company setting (`montenegro_test_mode`). `FiscalSettings::endpoint()` calls `testMode()`, which reads only that company setting (`FiscalSettings.php:150-153`). The env value `MONTENEGRO_FISCALIZATION_TEST_MODE` is not used on this path.
