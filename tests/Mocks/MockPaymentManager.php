<?php

namespace Romero\Multipay\Tests\Mocks;

use Romero\Multipay\Payment;

class MockPaymentManager extends Payment
{
    public function getDriver() : string
    {
        return $this->driver;
    }

    public function getConfig() : array
    {
        return $this->config;
    }

    public function getCallbackUrl() : string
    {
        return $this->settings['callbackUrl'];
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function getCurrentDriverSetting()
    {
        return $this->settings;
    }
}
