# Conventions

**Analysis Date:** 2026-11-15

## Code style in the fiscal module

- PHP 8.2 features: constructor promotion, `readonly`, backed enums, named arguments, `match`.
- Each fiscal class has a docblock that names the specification section (for example "section 4.3.2", "v4.2 changelog section 3.7.7"). Use these references when you check behavior against the spec.
- Errors in the build step are `RuntimeException` with the prefix `Montenegro fiscalization:`. The job catches them and writes a failed log.
- Log lines use `nlog('MONTENEGRO FISCALIZATION ERROR: ...')`.
- Upstream header (Invoice Ninja copyright, ELv2) stays on every file.

## EFI value formats used

| Value | Format | Where |
|-------|--------|-------|
| Decimal amount | 2 places, dot, zero as `"0"` | `Support/Money::decimal()` |
| Quantity | up to 3 places, trailing zeros removed | `Support/Money::quantity()` |
| Date-time | `Y-m-d\TH:i:sP` in the company time zone | `Montenegro::now()` |
| `InvNum` | `BU/ordinal/year/TCR` | `Montenegro::composeInvoiceNumber()` |
| `IIC` | uppercase hex, 32 chars, MD5 of the raw signature bytes | `Ikof::generate()` |
| `IICSignature` | uppercase hex, 512 chars | `Ikof::generate()` |
| `IsReverseCharge` | always `false` | `DocumentBuilder.php` |
| `TotVATAmt` | only when the issuer is in VAT | `DocumentBuilder.php` |

## Fiscal state on the invoice

`Invoice.backup` (JSON) carries the fiscal fields: `guid` (JIKR), `e_invoice_status`, and optional overrides `tcr_code`, `business_unit_code`, `operator_code`.
