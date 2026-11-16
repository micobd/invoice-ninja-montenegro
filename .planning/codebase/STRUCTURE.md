# Codebase Structure

**Analysis Date:** 2026-11-15

The fork adds about 6,200 lines for Montenegro EFI. The rest is upstream Invoice Ninja. This map covers only the fiscal parts.

## Fiscal core: `app/Services/EDocument/Standards/Montenegro/`

| File | Lines | Job |
|------|------:|-----|
| `Montenegro.php` | 458 | Orchestrator. Builds one signed `RegisterInvoiceRequest`, then sends it. |
| `Jobs/SendToCis.php` | 372 | Queue job. Runs the orchestrator, writes the fiscal log, applies the response, retries. |
| `Xml/DocumentBuilder.php` | 518 | Builds the `RegisterInvoiceRequest` DOM (Header, Invoice, PayMethods, Seller, Buyer, Items, SameTaxes, Fees, Currency). |
| `Xml/Signer.php` | 116 | Enveloped XML-DSig over the element with `Id="Request"`. |
| `Xml/FiscalEventBuilder.php` | 134 | Builds `RegisterTCR` and `RegisterCashDeposit` requests. |
| `Ikof.php` | 102 | IKOF payload, `IICSignature` (RSA-SHA256, hex), `IIC` (MD5 of raw signature). |
| `Soap/Envelope.php` | 55 | SOAP 1.1 envelope with an empty header. |
| `Soap/CisClient.php` | 60 | HTTP POST with the `SOAPAction` header. No try/catch. |
| `Soap/ResponseProcessor.php` | 198 | Parses `FIC` / `TCRCode` / `FCDC` or a SOAP Fault. Error-code text table. |
| `FiscalSettings.php` | 289 | Reads company settings and the default ENU. Resolves TIN, TCR, BU, operator, software codes, endpoint. |
| `Certificate.php` | 314 | Loads PKCS#12 or PEM. Extracts the TIN from the certificate subject. Expiry checks. |
| `InvoiceTotalsCalculator.php` | 146 | Converts an Invoice Ninja invoice to EFI totals, items, `SameTaxes`, fees. Uses line items only. Groups `SameTaxes` by VAT rate. See `CONCERNS.md` #8b. |
| `InvoiceTotals.php`, `ItemTotals.php`, `SameTaxTotals.php`, `FeeTotals.php` | 28–45 | Value objects for the totals. |
| `QrCode.php` | 98 | Verify URL and PNG data URI. |
| `CashDeposit.php`, `TcrRegistration.php` | 85–86 | Send cash deposit and TCR registration. |
| `CorrectiveReference.php` | 31 | `IICRef` + `IssueDateTime` + type for storno / corrective / bad debt. |
| `MontenegroExporter.php`, `MontenegroFiscalLogExporter.php`, `MontenegroFiscalReport.php` | 170–207 | Signed XML export, fiscal journal CSV/JSON, report. |
| `Support/Money.php` | 60 | Decimal format. Zero is `"0"`, not `"0.00"`. |
| `Support/XmlSanitizer.php` | 68 | Cleans registration codes and text. |
| `Enums/*` | 30–73 | `DocumentKind`, `InvoiceType`, `PaymentMethod`, `SubseqDelivType`, `CorrectiveType`, `ExemptFromVat`, `FeeType`, `TcrType`, `CashDepositOperation`. |

## Models: `app/Models/`

| Model | Table | Purpose |
|-------|-------|---------|
| `MontenegroFiscalLog` | `montenegro_fiscal_logs` | One row per fiscalization attempt chain. Holds `iic`, `jikr`, `status`, `request_state` (JSON), `response` (JSON). |
| `MontenegroFiscalCounter` | `montenegro_fiscal_counters` | `InvOrdNum` sequence. Unique on `(company_id, year)`. |
| `MontenegroEnu` | `montenegro_enus` | ENU / TCR devices with their codes. |
| `MontenegroCashDeposit` | `montenegro_cash_deposits` | Daily INITIAL / WITHDRAW records with `FCDC`. |

Migrations: `database/migrations/2026_09_21_000001` to `2026_11_09_000001`.

## Entry points

| Entry | File |
|-------|------|
| REST API | `routes/api.php:294-305`, `app/Http/Controllers/MontenegroFiscalizationController.php` (634 lines) |
| Form requests | `app/Http/Requests/EInvoice/Montenegro/*` |
| Auto-fiscalize on "sent" event | `app/Models/BaseModel.php:256-263` |
| Triggered action on save | `app/Services/Invoice/TriggeredActions.php:96-97` |
| Manual fiscalize action | `app/Http/Controllers/InvoiceController.php:882` |
| Storno on cancel | `app/Services/Invoice/HandleCancellation.php:192` |
| Invoice service methods | `app/Services/Invoice/InvoiceService.php:821-880` (`sendMontenegro`, `sendMontenegroCancellation`) |
| Credit note | `app/Services/Credit/CreditService.php:175-204` |
| Artisan | `app/Console/Commands/MontenegroFiscalize.php`, `MontenegroExport.php`, `MontenegroExportLogs.php`, `MontenegroLogRetention.php` |
| Scheduler | `app/Console/Kernel.php:103-114` (retry cron, log retention) |
| Retry cron | `app/Jobs/Cron/MontenegroFiscalizationCron.php` |
| PDF variables | `app/Utils/HtmlEngine.php` (`$montenegro_fiscalization` and single fields) |

## Docs

- `docs/docker-montenegro-fiscalization.md` (670 lines). The fork authors' setup and operations guide. Read sections 7–15 for the fiscal behavior they intend.
- `README.md`. Bilingual summary of the changes.
