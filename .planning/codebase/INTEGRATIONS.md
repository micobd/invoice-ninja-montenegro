# External Integrations

**Analysis Date:** 2026-11-15

## EFI Central Information System (CIS), Uprava prihoda i carina

| Item | Value | Source |
|------|-------|--------|
| Protocol | SOAP 1.1 over one-way TLS. The request signature authenticates the caller. No client TLS certificate. | `Soap/CisClient.php` |
| Test endpoint | `https://efitest.tax.gov.me/fs-v1` | `config/services.php:181` |
| Production endpoint | `https://efi.tax.gov.me/fs-v1` | `config/services.php:180` |
| SOAPAction RegisterInvoice | `https://efi.tax.gov.me/fs/RegisterInvoice` | `CisClient.php:28` |
| SOAPAction RegisterTCR | `https://efi.tax.gov.me/fs/RegisterTCR` | `CisClient.php:29` |
| SOAPAction RegisterCashDeposit | `https://efi.tax.gov.me/fs/RegisterCashDeposit` | `CisClient.php:30` |
| Timeouts | 30 s total, 10 s connect | `CisClient.php:35`, `:49` |
| QR verify (production) | `https://mapr.tax.gov.me/ic/#/verify` | `QrCode.php:35` |
| QR verify (test) | `https://efitest.tax.gov.me/ic/#/verify` | `QrCode.php:36` |

### Response codes returned to the app

| Element | Meaning | Parsed in |
|---------|---------|-----------|
| `FIC` | JIKR for an invoice | `ResponseProcessor::process()` |
| `TCRCode` | Code for a new ENU | same, `codeElement = 'TCRCode'` |
| `FCDC` | Cash deposit code | same, `codeElement = 'FCDC'` |
| SOAP Fault `code` | 0–58 client error, ≥ 900 server error | same, plus `describeErrorCode()` |

### Signature

- Enveloped XML-DSig. Reference `#Request`. Transforms: enveloped-signature, then exc-c14n. Digest SHA-256. Method RSA-SHA256. KeyInfo holds only `X509Certificate`.
- `Signer::verifyEmbedded()` checks a response against the certificate in its own KeyInfo. The code comment says it proves integrity only, not trust. A grep of `app/` found no call site, so responses are not verified.

### Bundled schemas

`app/Services/EDocument/Standards/Montenegro/Resources/` holds `eficg-fiscalization-service.xsd`, `fiscalization-service.wsdl`, and `xmldsig-core-schema.xsd`. Do not use them as a reference. The bundled XSD is an edited, older copy (see `CONCERNS.md` #8a). The official XSD is at `https://efitest.tax.gov.me/fs-v1/eficg-fiscalization-service.xsd` (SHA-256 `242c6571…243a` on 2026-11-15; production is byte-identical).

## Certificate

- Upload: PKCS#12 (raw or base64) or PEM. `Certificate::fromBlob()`.
- The certificate TIN is read from the subject. `assertSendable()` blocks a send when the configured TIN differs (CIS error 37).
