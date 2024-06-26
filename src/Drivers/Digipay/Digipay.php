<?php


namespace Romero\Multipay\Drivers\Digipay;

use GuzzleHttp\Client;
use Romero\Multipay\Abstracts\Driver;
use Romero\Multipay\Contracts\ReceiptInterface;
use Romero\Multipay\Exceptions\InvalidPaymentException;
use Romero\Multipay\Exceptions\PurchaseFailedException;
use Romero\Multipay\Invoice;
use Romero\Multipay\Receipt;
use Romero\Multipay\RedirectionForm;
use Romero\Multipay\Request;

class Digipay extends Driver
{
    /**
    * Digipay Client.
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
     * Digipay Oauth Token
     *
     * @var string
     */
    protected $oauthToken;

    /**
     * Digipay constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings= (object) $settings;
        $this->client = new Client();
        $this->oauthToken = $this->oauth();
    }

    public function purchase()
    {
        $details = $this->invoice->getDetails();

        $phone = null;
        if (!empty($details['phone'])) {
            $phone = $details['phone'];
        } elseif (!empty($details['mobile'])) {
            $phone = $details['mobile'];
        }
        $data = array(
            'amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'phone' => $phone,
            'providerId' => $this->invoice->getUuid(),
            'redirectUrl' => $this->settings->callbackUrl,
            'type' => 0,
            'userType' => is_null($phone) ? 2 : 0
        );

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer '.$this->oauthToken
                    ],
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);
        if ($response->getStatusCode() != 200) {
            // error has happened
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($body['ticket']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    public function pay(): RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl.$this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    public function verify(): ReceiptInterface
    {
        $tracingId=Request::input("trackingCode");

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl.$tracingId,
            [
                'json' => [],
                "headers" => [
                    "Accept" => "application/json",
                    "Authorization" => "Bearer ".$this->oauthToken,
                ],
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200) {
            $message = 'تراکنش تایید نشد';

            throw new InvalidPaymentException($message, (int)$response->getStatusCode());
        }

        return new Receipt('digipay', $body["trackingCode"]);
    }

    protected function oauth()
    {
        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiOauthUrl,
                [
                    "headers" => [
                        'Content-Type' => 'multipart/form-data',
                        'Authorization' => 'Basic '.base64_encode("{$this->settings->client_id}:{$this->settings->client_secret}")
                    ],
                    "username" => $this->settings->username,
                    "password" => $this->settings->password,
                    "grant_type" => 'password',
                ]
            );
        if ($response->getStatusCode()!=200) {
            if ($response->getStatusCode()==401) {
                throw new PurchaseFailedException("خطا نام کاربری یا رمز عبور شما اشتباه می باشد.");
            } else {
                throw new PurchaseFailedException("خطا در هنگام احراز هویت.");
            }
        }
        $body = json_decode($response->getBody()->getContents(), true);
        return $body['access_token'];
    }
}
