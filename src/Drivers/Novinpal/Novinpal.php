<?php

namespace Romero\Multipay\Drivers\Novinpal;

use GuzzleHttp\Client;
use Romero\Multipay\Abstracts\Driver;
use Romero\Multipay\Exceptions\InvalidPaymentException;
use Romero\Multipay\Exceptions\PurchaseFailedException;
use Romero\Multipay\Contracts\ReceiptInterface;
use Romero\Multipay\Invoice;
use Romero\Multipay\Receipt;
use Romero\Multipay\RedirectionForm;
use Romero\Multipay\Request;

class Novinpal extends Driver
{
    /**
     * Novinpal Client.
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
    protected $refId;

    /**
     * Novinpal constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
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
        $details = $this->invoice->getDetails();

        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        $orderId = crc32($this->invoice->getUuid()) . time();
        if (!empty($details['orderId'])) {
            $orderId = $details['orderId'];
        } elseif (!empty($details['order_id'])) {
            $orderId = $details['order_id'];
        }

        $data = array(
            "api_key" => $this->settings->merchantId, //required
            "return_url" => $this->settings->callbackUrl, //required
            "amount" => $amount, //required
            "order_id" => $orderId, //optional
        );

        // Pass current $data array to add existing optional details
        $data = $this->checkOptionalDetails($data);

        $response = $this->client->request(
            'POST',
            $this->settings->apiPurchaseUrl,
            ["form_params" => $data, "http_errors" => false]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] == 0) {
            // some error has happened
            throw new PurchaseFailedException($body['errorDescription']);
        }

        $this->invoice->transactionId($body['refId']);
        $this->refId = $body['refId'];
        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl . $this->invoice->getTransactionId();

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
    public function verify(): ReceiptInterface
    {
        $successFlag = Request::input('success');
        $code = Request::input('code');
        $orderId = Request::input('invoiceNumber');
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('refId');

        if ($successFlag != 1) {
            if ($code == 109) {
                $this->notVerified('تراکنش ناموفق بود', $code);
            } elseif ($code == 104) {
                $this->notVerified('در انتظار پردخت', $code);
            } elseif ($code == 107) {
                $this->notVerified('PSP یافت نشد', $code);
            } elseif ($code == 108) {
                $this->notVerified('خطای سرور', $code);
            } elseif ($code == 114) {
                $this->notVerified('متد ارسال شده اشتباه است', $code);
            } elseif ($code == 115) {
                $this->notVerified('ترمینال تایید نشده است', $code);
            } elseif ($code == 116) {
                $this->notVerified('ترمینال غیرفعال است', $code);
            } elseif ($code == 117) {
                $this->notVerified('ترمینال رد شده است', $code);
            } elseif ($code == 118) {
                $this->notVerified('ترمینال تعلیق شده است', $code);
            } elseif ($code == 119) {
                $this->notVerified('ترمینالی تعریف نشده است', $code);
            } elseif ($code == 120) {
                $this->notVerified('حساب کاربری پذیرنده به حالت تعلیق درآمده است', $code);
            } elseif ($code == 121) {
                $this->notVerified('حساب کاربری پذیرنده تایید نشده است', $code);
            } elseif ($code == 122) {
                $this->notVerified('حساب کاربری پذیرنده یافت نشد', $code);
            } else {
                $this->notVerified('خطای ناشناخته ای رخ داده است.');
            }


        }


        //start verfication
        $data = array(
            "api_key" => $this->settings->merchantId, //required
            "ref_id" => $transactionId, //required
        );

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            ["form_params" => $data, "http_errors" => false]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] != 1) {
            $this->notVerified($body['errorDescription'], $body['errorCode']);
        }

        /*
            for more info:
            var_dump($body);
        */

        return $this->createReceipt($body['refNumber']);
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
        $receipt = new Receipt('Novinpal', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $message
     * @throws InvalidPaymentException
     */
    private function notVerified($message, $code = 0)
    {
        if (empty($message)) {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', $code);
        } else {
            throw new InvalidPaymentException($message, $code);
        }
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        $detail = null;
        if (!empty($this->invoice->getDetails()[$name])) {
            $detail = $this->invoice->getDetails()[$name];
        } elseif (!empty($this->settings->$name)) {
            $detail = $this->settings->$name;
        }

        return $detail;
    }

    /**
     * Checks optional parameters existence (except orderId) and
     * adds them to the given $data array and returns new array
     * with optional parameters for api call.
     *
     * To avoid errors and have a cleaner api call log, `null`
     * parameters are not sent.
     *
     * To add new parameter support in the future, all that
     * is needed is to add parameter name to $optionalParameters
     * array.
     *
     * @param $data
     *
     * @return array
     */
    private function checkOptionalDetails($data)
    {
        $optionalParameters = [
            'mobile',
            'description',
            'card_number',
        ];

        foreach ($optionalParameters as $parameter) {
            if (!is_null($this->extractDetails($parameter))) {
                $parameterArray = array(
                    $parameter => $this->extractDetails($parameter)
                );
                $data = array_merge($data, $parameterArray);
            }
        }

        return $data;
    }
}
