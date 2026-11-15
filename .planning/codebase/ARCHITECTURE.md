# Architecture: Montenegro EFI fiscalization

**Analysis Date:** 2026-11-15

> Read-only. The flow below comes from reading the code. Nothing ran.

## One invoice, end to end

```
Invoice marked "sent"
  └─ BaseModel webhook hook (BaseModel.php:256)  or  manual action (InvoiceController.php:882)
       └─ InvoiceService::sendMontenegro()            (InvoiceService.php:829)
            - skip if backup->guid (JIKR) is set
            - reuse an open log (jikr IS NULL) → pass its id, set SubseqDelivType=SERVICE
            - backup->e_invoice_status = 'pending'
            └─ dispatch SendToCis(invoice_id, company, kind, corrective, subseq, null, log_id)

SendToCis::handle()                                    (Jobs/SendToCis.php:89)
  - WithoutOverlapping per company
  - markSent()
  - load log by log_id → restore ordinal, issue_date_time, iic, iic_signature,
    payment_method, invoice_type from request_state
  └─ Montenegro::run()                                 (Montenegro.php:109)
       1. FiscalSettings: is configured? certificate present?
       2. ADVANCE kind → PaymentMethod::ADVANCE
       3. NONCASH + require buyer TIN → buyer TIN must exist
       4. CASH → an INITIAL cash deposit must exist today for this TCR
       5. InvoiceTotalsCalculator → totals, items, SameTaxes, fees
       6. Ordinal: reuse stored one, or MontenegroFiscalCounter::nextOrdinal(company, year)
       7. InvNum = BU / ordinal / year / TCR
       8. Ikof::concatenate(TIN, IssueDateTime, InvNum, BU, TCR, SoftCode, TotPrice)   ← see CONCERNS #1
          Ikof::generate() or reuse stored IIC + signature
       9. DocumentBuilder::build() → RegisterInvoiceRequest DOM
      10. Envelope::wrap() → Signer::sign() on Id="Request"
      11. assertSendable(): size ≤ 150 kB, TIN = certificate TIN, VAT rules
  - persistLog(): create or update MontenegroFiscalLog (status pending, request_state)
  └─ Montenegro::send() → CisClient::send() → ResponseProcessor::process()
  └─ applyResponse()
       success  → log sent + jikr, invoice backup->guid = JIKR, status 'sent'
       fault <900 → log rejected (terminal)
       other    → log failed; if transient: set log_id + subseq, release(backoff)   ← see CONCERNS #2
```

## Retry paths

There are three separate retry paths. They do not share one guard.

1. **Queue retry inside `SendToCis`.** `$tries = 5`, backoff `[5, 30, 120, 600, 3600]` seconds. `applyResponse()` calls `release()` after it sets `$this->log_id`.
2. **Uncaught exception.** `CisClient::send()` has no try/catch. A connection timeout throws, and Laravel retries the job from its original payload.
3. **Cron `MontenegroFiscalizationCron`.** It runs on the schedule (`Kernel.php:103`). Docs section 10 says every 30 minutes. It picks logs with status `pending` or `failed`, no `jikr`, and age inside the `SubseqDelivType` window (2 days for `NOINTERNET`, 5 days for the others). It dispatches `SendToCis` with the log id.

## Data that the fiscal module keeps

- `MontenegroFiscalLog.request_state` is the snapshot for a resend and for the QR code. It holds `iic`, `iic_signature`, `inv_num`, `ordinal`, `issue_date_time`, `tin`, `tcr`, `bu`, `sw`, `operator`, `total`, `corrective`, `mode`.
- `Invoice.backup->guid` holds the JIKR. `Invoice.backup->e_invoice_status` holds `pending` / `sent` / `failed` / `rejected`.
- The certificate blob and passphrase sit on the company (`Company::getInvoiceCert()`, `getSslPassPhrase()`). The README says they are stored encrypted. I did not verify the encryption.

## Code you can port to plain PHP

These classes import only PHP built-ins (DOM, OpenSSL) or one small package. They are the most useful parts for your own EFI work.

| Class | Dependencies |
|-------|--------------|
| `Ikof` | `ext-openssl` only |
| `Soap/Envelope` | `ext-dom` only |
| `Soap/ResponseProcessor` | `ext-dom` only. Has the full error-code table. |
| `Support/Money` | none |
| `Xml/Signer` | `robrichards/xmlseclibs` |
| `QrCode` | `endroid/qr-code` (only for the PNG; `url()` is plain PHP) |
| `Certificate` | `ext-openssl`, Carbon, Laravel `Company` in `fromCompany()` |
| `Xml/DocumentBuilder` | Invoice Ninja `Invoice`/`Credit` models and `FiscalSettings`. Needs rework to port. |
| `Soap/CisClient` | Laravel `Http` facade. Replace with cURL. |

The ELv2 license applies to any code that you copy. See `STACK.md`.
