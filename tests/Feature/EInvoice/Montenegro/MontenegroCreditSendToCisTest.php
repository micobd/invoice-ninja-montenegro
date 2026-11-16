<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Feature\EInvoice\Montenegro;

use App\DataMapper\InvoiceItem;
use App\Models\Credit;
use App\Models\MontenegroFiscalCounter;
use App\Models\MontenegroFiscalLog;
use App\Services\EDocument\Standards\Montenegro\Jobs\SendToCis;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Runs SendToCis::handle() for a credit note end to end, with the CIS
 * response faked.
 *
 * MontenegroCreditFiscalizationTest only checks that the job is dispatched.
 * This test runs the job itself, so it covers persistLog() and
 * applyResponse() for the entity type "credit".
 */
class MontenegroCreditSendToCisTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    private const FIC = '9f3c1a2b-4d5e-4f60-8a7b-1c2d3e4f5a6b';

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testCreditNoteIsFiscalizedAndStoresTheJikr(): void
    {
        $this->enableMontenegro();

        $credit = $this->makeCredit();

        Http::fake(['*' => Http::response($this->ficResponse(), 200)]);

        (new SendToCis(
            invoice_id: $credit->id,
            company: $this->company,
            kind: 'credit',
            corrective: [
                'iic' => '0E3CB8457E17AE9A4676E3227AAC86F8',
                'issue_date_time' => '2026-11-01T10:00:00+01:00',
                'type' => 'CORRECTIVE',
            ],
            entity_type: 'credit',
        ))->handle();

        $log = MontenegroFiscalLog::where('credit_id', $credit->id)->first();

        $this->assertNotNull($log, 'No fiscal log was written for the credit note.');
        $this->assertNull($log->invoice_id);
        $this->assertSame(MontenegroFiscalLog::STATUS_SENT, $log->status);
        $this->assertSame(self::FIC, $log->jikr);

        $credit = $credit->fresh();

        $this->assertSame(self::FIC, $credit->backup->guid);
        $this->assertSame('sent', $credit->backup->e_invoice_status);

        // One credit note reserves exactly one ordinal.
        $this->assertSame(
            1,
            MontenegroFiscalCounter::where('company_id', $this->company->id)->value('last_ordinal')
        );

        Http::assertSentCount(1);
    }

    private function ficResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Header/><env:Body>'
            . '<RegisterInvoiceResponse xmlns="https://efi.tax.gov.me/fs/schema" Id="Response" Version="1">'
            . '<Header UUID="1b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d" RequestUUID="2c3d4e5f-6071-4b8c-9d0e-1f2a3b4c5d6e" SendDateTime="2026-11-16T10:00:01+01:00"/>'
            . '<FIC>' . self::FIC . '</FIC>'
            . '</RegisterInvoiceResponse></env:Body></env:Envelope>';
    }

    private function makeCredit(): Credit
    {
        $item = new InvoiceItem();
        $item->notes = 'Storno';
        $item->quantity = 1;
        $item->cost = 100;
        $item->line_total = 100;
        $item->tax_amount = 0;

        return Credit::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'amount' => 100,
            'line_items' => [$item],
            'tax_rate1' => 0,
            'tax_rate2' => 0,
            'tax_rate3' => 0,
        ]);
    }

    private function enableMontenegro(): void
    {
        $settings = $this->company->settings;
        $settings->e_invoice_type = 'MONTENEGRO';
        $settings->montenegro_tin = '12345678';
        $settings->montenegro_tcr_code = 'nn825pg646';
        $settings->montenegro_business_unit_code = 'ro861ij638';
        $settings->montenegro_operator_code = 'ap514jz098';
        $settings->montenegro_software_code = 'gz434bv927';
        $settings->montenegro_test_mode = true;
        $settings->montenegro_invoice_type = 'NONCASH';
        $settings->montenegro_payment_method = 'ACCOUNT';
        $settings->montenegro_require_buyer_tin = true;
        $this->company->settings = $settings;
        $this->company->e_invoice_certificate = base64_encode($this->pemBundle());
        $this->company->save();

        $this->company = $this->company->fresh();

        $client = $this->client;
        $client->vat_number = '12345678';
        $client->saveQuietly();
    }

    private function pemBundle(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(
            ['commonName' => 'Montenegro Credit Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return $keyPem . "\n" . $certPem;
    }
}
