# Testing

**Analysis Date:** 2026-11-15

> No test ran. `vendor/` is not installed. To run tests, install dependencies first (`composer install`) in a PHP 8.2+ environment, or use the Docker stack from `docs/docker-montenegro-fiscalization.md`.

## Fiscal tests

Unit, `tests/Unit/EDocument/Montenegro/`:

- `IkofTest.php`, `CertificateTest.php`, `FiscalEventBuilderTest.php`, `MoneyTest.php`, `QrCodeTest.php`, `ResponseProcessorTest.php`, `SubseqDelivTypeTest.php`

Feature, `tests/Feature/EInvoice/Montenegro/`:

- `MontenegroAdvanceTest`, `MontenegroCertificateStatusTest`, `MontenegroComplianceGuardsTest`, `MontenegroCreditFiscalizationTest`, `MontenegroEnuTest`, `MontenegroExportTest`, `MontenegroFiscalLogRetentionTest`, `MontenegroFiscalReportTest`, `MontenegroFiscalizationPdfTest`, `MontenegroInvoiceElementsTest`, `MontenegroManualFiscalizationTest`, `MontenegroRegistrationEndpointsTest`, `MontenegroSubsequentFiscalizationTest`

## Gaps found by reading

- `IkofTest` passes `'9952'` (the plain ordinal) as the third IKOF field (`IkofTest.php:42`). Production code passes the composite `InvNum` (`Montenegro.php:176`). So the test does not cover the real call. A signature check against the spec example confirms that the ordinal is correct and the production call is wrong. See `CONCERNS.md` #1.
- No test covers `SendToCis::release()`, a resend with `log_id`, or a `ConnectionException` from `CisClient`. A grep for `release`, `log_id`, and `ConnectionException` in the fiscal tests found nothing. See `CONCERNS.md` #2.
- `MontenegroCreditFiscalizationTest` uses `Bus::fake()`. It checks the dispatch only and never runs `SendToCis::handle()` for a credit. See `CONCERNS.md` #3a.
- Only `MontenegroRegistrationEndpointsTest` uses `Http::fake()` among the feature tests. I did not read the other test files in full, so I did not check how each one isolates the CIS.
