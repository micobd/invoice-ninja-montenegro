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

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Montenegro;

/**
 * Checks the IKOF that Montenegro::run() puts into the request.
 *
 * Technical specification section 4.3.2: the IKOF payload is
 * TIN|IssueDateTime|InvOrdNum|BusinUnitCode|TCRCode|SoftCode|TotPrice.
 * The third value is the plain ordinal, not the composite InvNum. The
 * published worked example of the specification only verifies with the
 * ordinal (12345678|2019-06-12T17:05:43+02:00|9952|...).
 *
 * The test rebuilds the payload from the signed XML and verifies the
 * IICSignature with the certificate public key, the same way a verifier
 * at the tax administration can.
 */
class MontenegroIkofPayloadTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    private string $certificatePem = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testIicSignatureVerifiesAgainstThePayloadWithThePlainOrdinal(): void
    {
        $this->enableMontenegro();

        $montenegro = new Montenegro($this->invoice->fresh()->setRelation('company', $this->company));
        $montenegro->run();

        $xpath = $this->xpath((string) $montenegro->getEnvelope());
        $invoice = $xpath->query('//*[local-name()="Invoice"]')->item(0);

        $this->assertNotNull($invoice, 'The request has no Invoice element.');

        $ordinal = $invoice->getAttribute('InvOrdNum');

        $this->assertMatchesRegularExpression('/^[1-9][0-9]*$/', $ordinal);
        $this->assertSame(
            $invoice->getAttribute('BusinUnitCode') . '/' . $ordinal . '/' . substr($invoice->getAttribute('IssueDateTime'), 0, 4) . '/' . $invoice->getAttribute('TCRCode'),
            $invoice->getAttribute('InvNum'),
            'InvNum must stay the composite BU/ordinal/year/TCR.'
        );

        $expectedPayload = implode('|', [
            '12345678',
            $invoice->getAttribute('IssueDateTime'),
            $ordinal,
            $invoice->getAttribute('BusinUnitCode'),
            $invoice->getAttribute('TCRCode'),
            $invoice->getAttribute('SoftCode'),
            $invoice->getAttribute('TotPrice'),
        ]);

        $signature = hex2bin($invoice->getAttribute('IICSignature'));

        $this->assertNotFalse($signature);
        $this->assertSame(
            1,
            openssl_verify($expectedPayload, $signature, openssl_pkey_get_public($this->certificatePem), OPENSSL_ALGO_SHA256),
            'IICSignature does not verify against ' . $expectedPayload
        );
        $this->assertSame(strtoupper(md5($signature)), $invoice->getAttribute('IIC'));
        $this->assertSame($expectedPayload, $montenegro->getIkof()?->payload);
    }

    private function xpath(string $xml): DOMXPath
    {
        $doc = new DOMDocument();
        $doc->loadXML($xml);

        return new DOMXPath($doc);
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
            ['commonName' => 'Montenegro IKOF Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        $this->certificatePem = $certPem;

        return $keyPem . "\n" . $certPem;
    }
}
