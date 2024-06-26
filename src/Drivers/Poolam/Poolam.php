<?php

namespace Romero\Multipay\Drivers\Poolam;

use GuzzleHttp\Client;
use Romero\Multipay\Abstracts\Driver;
use Romero\Multipay\Exceptions\InvalidPaymentException;
use Romero\Multipay\Exceptions\PurchaseFailedException;
use Romero\Multipay\Contracts\ReceiptInterface;
use Romero\Multipay\Invoice;
use Romero\Multipay\Receipt;
use Romero\Multipay\RedirectionForm;
use Romero\Multipay\Request;

class Poolam extends Driver
{
    /**
     * Poolam Client.
     *
     * @var object
     */
    protected $client;

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Poolam constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        $data = array(
            'api_key' => $this->settings->merchantId,
            'amount' => $amount,
            'return_url' => $this->settings->callbackUrl,
        );

        $response = $this->client->request(
            'POST',
            $this->settings->apiPurchaseUrl,
            [
                "form_params" => $data,
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if (empty($body['status']) || $body['status'] != 1) {
            // some error has happened
            throw new PurchaseFailedException($body['status']);
        }

        $this->invoice->transactionId($body['invoice_key']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay() : RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl.$this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return mixed|void
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $data = [
            'api_key' => $this->settings->merchantId,
        ];

        $transactionId = $this->invoice->getTransactionId() ?? Request::input('invoice_key');
        $url = $this->settings->apiVerificationUrl.$transactionId;

        $response = $this->client->request(
            'POST',
            $url,
            ["form_params" => $data, "http_errors" => false]
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if (empty($body['status']) || $body['status'] != 1) {
            $message = $body['errorDescription'] ?? null;

            $this->notVerified($message, $body['status']);
        }

        return $this->createReceipt($body['bank_code']);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId)
    {
        $receipt = new Receipt('poolam', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $message
     * @throws InvalidPaymentException
     */
    private function notVerified($message, $status)
    {
        if (empty($message)) {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', (int)$status);
        } else {
            throw new InvalidPaymentException($message, (int)$status);
        }
    }
}
