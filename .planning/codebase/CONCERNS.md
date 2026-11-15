# Codebase Concerns

**Analysis Date:** 2026-11-15

> Item #1 is confirmed by a signature check. The other items come from reading, not from running. `vendor/` is not installed and no test ran. The README claims "idempotentno ponovno slanje" (idempotent resend). Treat that claim as unverified until items #2–#4 are checked.
>
> Order: most urgent first. Each item says how to confirm it.

## 1. IKOF payload uses `InvNum` instead of the ordinal — CRITICAL, CONFIRMED BY SIGNATURE CHECK (2026-11-15)

- `Montenegro.php:173-181` builds the IKOF string with `$this->invoiceNumber`. That value is the composite `BU/ordinal/year/TCR` (`composeInvoiceNumber()`). The `Ikof` docblock also says "InvNum".
- The correct field 3 is the plain ordinal (`InvOrdNum`).

### Evidence

1. **Cryptographic check of the spec's worked example.** The spec (section 4.3.2) publishes an example public key, a signature, and the IIC `E4033D471FEEA47A3C664B15C669C709`. I took them from the test vector `test-vectors/iic/iic-spec-001.json` in the open-source library `atakancelik/MonteFiskal`. The same signature hex and IIC also appear in the local `montenegro-efi` skill. The check ran with PHP 8.3 `openssl_verify`:

   | Field 3 | Payload | `openssl_verify` |
   |---------|---------|------------------|
   | ordinal | `12345678\|2019-06-12T17:05:43+02:00\|9952\|bb123bb123\|cc123cc123\|ss123ss123\|99.01` | `1` (valid) |
   | composite InvNum (fork) | `12345678\|2019-06-12T17:05:43+02:00\|bb123bb123/9952/2019/cc123cc123\|bb123bb123\|cc123cc123\|ss123ss123\|99.01` | `0` (invalid) |

   MD5 of the raw signature equals the published IIC. The script is in the session scratchpad (`scripts/verify_ikof.php`). It is not in the repo.
2. **Independent implementations use the ordinal:**
   - `fiskaltrust/middleware`, `scu-me/.../Helpers/SigningHelper.cs`: field 3 is `YearlyOrdinalNumber`. The composite InvNum is built separately for the journal.
   - `djordjen/ElektronskaFiskalizacijaNodeJS`, `tools.js` / `xmls.js`: field 3 is `invoice.ORD`. `InvNum` is built separately.
   - `atakancelik/MonteFiskal`, `IkofParameters.php`: field 3 is `$ordinal`.
3. **The QR verify URL** (`QrCode.php`) carries exactly the seven IKOF inputs. Its third value is `ord`, the plain ordinal.
4. The official rulebook (Pravilnik, Prilog 2) names the field only "broj računa". The wording alone does not decide the question. The evidence above does.
5. The fork's own `IkofTest.php:42` passes `'9952'`. It tests `Ikof::concatenate()` alone, never the call in `Montenegro::run()`. So the test passes and the bug stays hidden.

### What it means

- Every IIC that this fork produces is wrong. The `IICSignature` cannot be verified against the invoice data.
- The CIS returned JIKRs anyway (the fork authors report working fiscalization), so the CIS does not reject this at registration. The error shows up in later checks or in verification.
- A code fix is not enough alone. Open logs reuse the stored IIC on resend (`SendToCis` reads `request_state['iic']`), so those resends keep the wrong IIC. Already registered invoices keep their wrong IIC at the CIS. Decide with your accountant or the tax administration how to handle them.
### Fix status (2026-11-16, not committed)

- `Montenegro.php` now passes `(string) $this->ordinal` as field 3. `Ikof::concatenate()` names the parameter `$ordinal`, and the docblock says `InvOrdNum`.
- New test `tests/Feature/EInvoice/Montenegro/MontenegroIkofPayloadTest.php` rebuilds the payload from the signed XML and checks `IICSignature` with the certificate public key.
  - Before the fix: fail, `openssl_verify` = 0.
  - After the fix: pass. All 101 Montenegro unit and feature tests pass.
- Still open:
  - Open logs created before the fix keep the old IIC on resend.
  - Invoices already registered keep their wrong IIC at the CIS.
  - No run on `efitest` yet.

## 2. `release()` may drop `log_id`, so a retry takes a new ordinal and a new IIC — HIGH, NOT CONFIRMED

- `SendToCis.php:265` sets `$this->log_id = $log->id`. `SendToCis.php:270` then calls `$this->release($delay)`.
- Laravel's Redis and database queues re-push the original serialized payload on `release()`. A property changed inside `handle()` does not reach the next attempt.
- If that is so, the next attempt has `log_id = null`. Then `reserveOrdinal: $log === null` (`SendToCis.php:130`) reserves a new ordinal, makes a new IIC, and creates a second log row.
- **What it means:** a transient failure where the first request did reach the CIS can give one invoice two fiscal registrations. The first log row stays `failed`, and the cron (#4) can resend it too.
- With `QUEUE_CONNECTION=sync` (`.env.example`), `release()` does not re-queue at all, so the path behaves differently again.
- **Confirm:** a feature test with the Redis or database queue that fakes one 503 and then a success. Count the log rows and the ordinals after the run.

## 3. An exception from the HTTP call bypasses the retry logic — HIGH, NOT CONFIRMED

- `CisClient::send()` (`CisClient.php:46`) and `Montenegro::send()` (`Montenegro.php:420`) have no try/catch. A connect or read timeout throws `ConnectionException`.
- The log row already exists with status `pending` (`persistLog()` runs before `send()`).
- Laravel retries the job from the original payload with `log_id = null`. The retry reserves a new ordinal (same effect as #2).
- `failed()` returns early when `log_id` is null (`SendToCis.php`), so the first log stays `pending`.
- A read timeout is the most dangerous case: the CIS may have registered the first request.
- **Confirm:** a test that makes the `Http` fake throw `ConnectionException`, then inspects log rows and the counter.

## 3a. Credit note fiscalization throws a `TypeError` — HIGH, CONFIRMED BY READING, NOT RUN

- `SendToCis::handle()` loads a `Credit` when `entity_type` is `credit`.
- `persistLog()` (`SendToCis.php:152-153`), `applyResponse()` (`:223`), `writeActivity()` (`:310`), and `systemLog()` (`:329`) type-hint `Invoice $invoice`.
- `Credit` extends `BaseModel` (`app/Models/Credit.php:148`), not `Invoice`. PHP always enforces class type hints, with or without `strict_types`.
- `persistLog()` runs outside the try/catch, after `Montenegro::run()` reserved the ordinal.
- **What it means:** a credit note never reaches the CIS. Each of the 5 queue attempts reserves a new ordinal (see #3), so each credit note burns up to 5 ordinals.
- `MontenegroCreditFiscalizationTest` uses `Bus::fake()`. It checks only that the job is dispatched. It never runs `handle()`.
- **Confirm:** a test that runs `SendToCis::handle()` for a credit with a faked HTTP response.

## 4. The cron resends any open log, even when another log for the same invoice has a JIKR — MEDIUM, NOT CONFIRMED

- `MontenegroFiscalizationCron::processCompany()` selects logs by status and `jikr IS NULL`. It does not check the invoice's `backup->guid` or other logs for the same invoice.
- `SendToCis::handle()` also does not check `backup->guid` before it sends. Only `InvoiceService::sendMontenegro()` has that guard.
- Combined with #2 or #3, an orphaned log can be resent after the invoice already has a JIKR.
- **Confirm:** create two logs for one invoice, one with a JIKR and one `failed`. Run the cron and check whether `SendToCis` is dispatched.

## 5. A build failure after the ordinal is reserved leaves a gap — MEDIUM

- `nextOrdinal()` runs at `Montenegro.php:167`. Later steps can still throw: `Signer::sign()`, `assertSendable()` (size, TIN mismatch, VAT rules).
- `recordBuildFailure()` writes `request_state = []`, so the reserved ordinal is lost. The next attempt takes a new number.
- **What it means:** gaps in `InvOrdNum`. Check whether UPC accepts gaps for your case.

## 6. The ordinal counter is per company and year, not per ENU — QUESTION

- `montenegro_fiscal_counters` is unique on `(company_id, year)`. The fork supports several ENUs (`MontenegroEnu`, docs section 12).
- `InvNum` contains the TCR code, so each ENU sees a sequence with gaps when two ENUs share the counter.
- Docs section 12 explains how to choose an ENU for each invoice. It says nothing about the ordinal scope.
- **Confirm:** check whether the spec requires one sequence per TCR per year.

## 7. A resend recomputes data that the reused IIC was based on — MEDIUM

- On a resend, the ordinal, issue time, IIC, and signature come from `request_state`. TCR, BU, software code, and totals are read again from current settings and the current invoice.
- Docs section 10 ("Immutability and resends") says this: after a fiscal record with an IIC exists, the invoice is locked, and the update endpoint rejects edits. If the lock works, edits to totals are blocked. I did not verify the lock in code.
- The lock does not cover the ENU. If someone changes the default ENU between attempts, the resend uses the new TCR and BU codes with the old IIC.
- `TotPriceToPay` depends on `paid_to_date`. A payment between attempts changes the XML, but not the IIC payload.

## 8. `BUSINESSNEED` is wrong, the official value is `BUSINESSNEEDS` — LOW, CONFIRMED (2026-11-15)

- `Enums/SubseqDelivType.php` uses `BUSINESSNEED`.
- The official XSD lists `BUSINESSNEEDS`. I fetched it from `https://efitest.tax.gov.me/fs-v1/eficg-fiscalization-service.xsd` and from `https://efi.tax.gov.me/fs-v1/eficg-fiscalization-service.xsd` on 2026-11-15. Both files have the same SHA-256 `242c657173ec62f2193447ebc52e3ef26fc4eaecaf7e335c9b9631935591243a`.
- The local `montenegro-efi` skill is correct on this point.
- **What it means:** a resend with `BUSINESSNEED` fails XSD validation (CIS error 11). The automatic resends use `SERVICE`, so only a manual choice of this value hits the bug.

## 8a. The bundled XSD is not the official XSD — MEDIUM, CONFIRMED (2026-11-15)

- `Montenegro/Resources/eficg-fiscalization-service.xsd` differs from the official file in values, not only in formatting. Examples:
  - `SubseqDelivTypeSType`: fork `BUSINESSNEED`, official `BUSINESSNEEDS`.
  - `SameTaxType.ExemptFromVAT`: fork uses a type with `TYPE_1,TYPE_2`, official uses the `VAT_CL*` codes.
  - `ExemptFromVATSType`: official also has `VAT_CL3`, `VAT_CL4`, `VAT_CL14`, `VAT_CL15`.
  - `BuyerType`: official makes `IDType`, `IDNum`, `Name` required when `<Buyer>` is present. Fork has them optional.
  - Item amounts: official uses 4-decimal types (`Decimal4SType`) for `UPB`, `UPA`, `PB`, `PA`, `VA`, `VR`.
  - Official has `Note`, `TaxPeriod`, `TotPriceToPay`, `BankAccNum`, `PayMethod.BankAcc`, `IICRefs`, `Approvals`. The fork copy lacks some of them.
  - The fork copy has annotations that cite "technical specification v5, 3.7.1.54". The official file has no such text, so the copy was edited.
- No code validates against the bundled XSD. `DocumentBuilder.php:34` names it only in a comment.
- **What it means:** do not use the bundled XSD as a reference. Replace it with the official file before you add local XSD validation.

## 8b. Fiscal totals ignore invoice-level discount, surcharges and inclusive taxes — MEDIUM, NOT CONFIRMED

- `InvoiceTotalsCalculator::calculate()` sums only the line items (`line_total`, `tax_amount`).
- It does not read the invoice `discount`, `custom_surcharge1..4`, or `uses_inclusive_taxes`.
- With inclusive taxes, Invoice Ninja's `line_total` may already contain VAT. The calculator then treats it as the net price and adds `tax_amount` on top.
- **What it means:** the fiscal `TotPrice` can differ from the amount on the invoice that the client gets.
- **Confirm:** a feature test with an invoice-level discount, and one with `uses_inclusive_taxes = true`. Compare `TotPrice` with `$invoice->amount`.

## 8c. `<Buyer>` without an ID breaks the official XSD — MEDIUM, CONFIRMED AGAINST THE XSD, NOT SENT TO CIS

- `DocumentBuilder::buyer()` emits `<Buyer>` for every client. It adds `IDType`/`IDNum` only when the client has a VAT or ID number.
- The official XSD makes `IDType`, `IDNum` and `Name` required when `<Buyer>` is present.
- **What it means:** a cash invoice, or a non-cash invoice with `require_buyer_tin` off, to a client without a tax number fails with CIS error 11.

## 9. Small items

- `Ikof::verify()` does not use the private key. It only checks that MD5 of the stored signature equals the stored IIC. The name suggests more than it does.
- `SendToCis::handle()` calls `markSent()` before the build. A build failure leaves the invoice "sent" in Invoice Ninja but not fiscalized. The job marks `e_invoice_status = 'failed'`, so it is visible.
