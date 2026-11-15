# Poređenje: fork Invoice Ninja Montenegro ↔ skill `montenegro-efi`

**Datum:** 2026-11-15

Referenca za presudu je zvanični XSD sa servera Uprave:
`https://efitest.tax.gov.me/fs-v1/eficg-fiscalization-service.xsd` i `https://efi.tax.gov.me/fs-v1/...`.
Oba fajla su bajt-identična, SHA-256 `242c657173ec62f2193447ebc52e3ef26fc4eaecaf7e335c9b9631935591243a`, preuzeto 2026-11-15.
XSD koji je u forku (`Montenegro/Resources/`) **nije** zvanični (vidi `CONCERNS.md` #8a), pa ga ne koristim kao referencu.

Oznake: ✅ isto i ispravno · ❌ fork griješi · ⚠️ skill griješi · ❓ nije potvrđeno

## 1. Gdje se fork i skill slažu

| Tema | Fork | Skill | Napomena |
|------|------|-------|----------|
| IIC = MD5 nad **binarnim** potpisom, uppercase hex (32) | `Ikof::generate()` | pravilo 1, 3 | ✅ |
| IICSignature = uppercase hex binarnog potpisa (512) | `Ikof::generate()` | pravilo 2 | ✅ |
| Separator `|` (ASCII 124) | `Ikof::concatenate()` | pravilo 5 | ✅ |
| Isti `IssueDateTime` string u IKOF-u i u XML-u | `Montenegro::$issueDateTime` se koristi za oba | pravilo 4 | ✅ |
| Vrijeme sa zonom, `Y-m-d\TH:i:sP` | `Montenegro::now()` | pravilo 4 | ✅ |
| XML-DSig: enveloped → exc-c14n, SHA-256, RSA-SHA256, `#Request`, samo X509 | `Xml/Signer.php` | pravilo 6, `xml-signing.md` | ✅ |
| `xmlseclibs`, `DOMDocument`, bez `SoapClient` | da | „What to avoid“ | ✅ |
| `InvNum` = `BU/ordinal/godina/TCR`, `InvOrdNum` = samo broj | `composeInvoiceNumber()` | pravilo 8 | ✅ |
| `IsReverseCharge="false"` | `DocumentBuilder` | pravilo 10 | ✅ |
| Decimale sa tačkom, nula kao `"0"` | `Support/Money` | pravilo 7 | ✅ |
| Endpointi, SOAPAction vrijednosti, QR hostovi (`mapr` / `efitest`) | `CisClient`, `QrCode`, `config/services.php` | `endpoints.md` | ✅ |
| QR: `ord` = samo ordinal, `#` na pravom mjestu, `crtd` URL-enkodiran, test/prod iz podešavanja | `QrCode::url()` | `qr-code-url.md` | ✅ |
| Ponovno slanje koristi isti IIC i `SubseqDelivType` | namjera u `SendToCis` (ali vidi `CONCERNS.md` #2, #3) | pravilo 11 | ✅ namjera, ❓ izvedba |
| `Fee Type` bez `BOTTLE` (samo `PACK`, `COMMISSION`, `OTHER`) | `Enums/FeeType.php` | `invoice-fields.md` | ✅ (zvanični XSD isto). Samo `docs/…md` §14 još pominje `BOTTLE`. |
| Timeout 10 s connect / 30 s ukupno | `CisClient` | `endpoints.md` | ✅ |

## 2. Gdje fork griješi, a skill je u pravu

| # | Tema | Fork | Skill / zvanični izvor | Status |
|---|------|------|------------------------|--------|
| A | **IKOF polje 3** | složeni `InvNum` (`Montenegro.php:176`) | samo `InvOrdNum` | ❌ **potvrđeno** potpisom iz specifikacije (`CONCERNS.md` #1) |
| B | `SubseqDelivType` | `BUSINESSNEED` | `BUSINESSNEEDS` | ❌ potvrđeno zvaničnim XSD-om |
| C | `<Buyer>` bez ID-a | šalje `<Buyer Name=… Country=…>` i kad klijent nema PIB (`DocumentBuilder::buyer()`) | zvanični XSD: kad `<Buyer>` postoji, `IDType`, `IDNum` i `Name` su **obavezni** | ❌ prema zvaničnom XSD-u, greška 11 za gotovinski račun kupcu bez PIB-a. Nije testirano na CIS-u. |
| D | `SOAPAction` u navodnicima | bez navodnika | SOAP 1.1: sa navodnicima | ⚠️ niska važnost. Autori forka prijavljuju da CIS prihvata. |
| E | Kod greške 58 | nema u `describeErrorCode()` | 58 = nema INITIAL depozita danas | niska važnost. Fork lokalno blokira gotovinski račun bez depozita. |

## 3. Gdje skill griješi ili je nepotpun (popraviti skill)

| Tema | Skill kaže | Zvanični XSD |
|------|-----------|--------------|
| `<Buyer>` | „everything optional“ | `IDType`, `IDNum`, `Name` obavezni kad `<Buyer>` postoji |
| `Name` za Seller/Buyer | do 100 znakova | `String400SType` (do 400) |
| `ExemptFromVAT` kodovi | `VAT_CL17 … VAT_CL30` | i `VAT_CL3`, `VAT_CL4`, `VAT_CL14`, `VAT_CL15`, `VAT_CL44` |
| Decimale stavki | „UPB/UPA 2–4 mjesta“ | tačno: `UPB`, `UPA`, `PB`, `PA`, `VA`, `VR` su `Decimal4*`. Fork ih zaokružuje na 2 (vidi 4.B). |

## 4. Razlike u pristupu (provjeriti sa specifikacijom ili knjigovođom)

| # | Tema | Fork | Skill | Status |
|---|------|------|-------|--------|
| A | **Avansni račun** | Sam avansni račun dobija `PayMethod Type="ADVANCE"` (`Montenegro::run()`: `DocumentKind::ADVANCE` → `PaymentMethod::ADVANCE`). Konačni račun je običan `INVOICE` sa `ADVANCE` + `AdvIIC` (`link_advance`). | Avansni račun nosi **stvarni** način plaćanja (`BANKNOTE`, `CARD`, `ACCOUNT`). `ADVANCE` + `AdvIIC` ide tek na konačni račun, koji je `CORRECTIVE` i poništava stavke avansa. | ❓ Zvanični XSD kaže samo „Invoice is paid in advance“. Treba provjeriti tehničku specifikaciju (Table 90, 3.7.1.54). |
| B | Decimale cijena stavki | `Money::decimal()`: 2 decimale za `UPB`, `UPA`, `PB`, `PA`, `VA` | 2–4 | ❓ Nije greška XSD-a, ali zaokruživanje jedinične cijene na 2 decimale može dati `UPB × Q ≠ PB` u naknadnim provjerama. |
| C | `SubseqDelivType` za timeout | uvijek `SERVICE` (rok 5 dana) | `NOINTERNET` (rok 2 dana) za problem sa mrežom, `SERVICE` za grešku servisa (9xx) | ❓ Pitanje tumačenja; utiče na zakonski rok. |
| D | Ordinal brojač | jedan po firmi i godini | skill ne propisuje | ❓ vidi `CONCERNS.md` #6 |

## 5. Šta skill nema, a fork ima

- Čuvanje stanja za ponovno slanje (`request_state`), audit log, izvoz žurnala, rok čuvanja 10 godina.
- Više ENU uređaja, korektivni i storno tok, bad debt, cash deposit sa lokalnom provjerom INITIAL depozita.
- Lokalne provjere prije slanja: veličina ≤ 150 kB, PIB = PIB iz sertifikata (greška 37), PDV status.
- Šifrovano čuvanje sertifikata i praćenje isteka.

To su dobri obrasci za vaš kod, uz napomenu o ELv2 licenci (`STACK.md`).
