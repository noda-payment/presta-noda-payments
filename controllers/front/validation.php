<?php

class NodapayValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'nodapay') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            exit($this->module->getTranslator()->trans('This payment method is not available.', [], 'Modules.Nodapay.Shop'));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        $this->module->validateOrder($cart->id, (int) Configuration::get('NODAPAY_VALIDATION'), $total, $this->module->displayName, null, [], (int) $currency->id, false, $customer->secure_key);

        $client = new \GuzzleHttp\Client();
        $url = Configuration::get('NODAPAY_MODE') ? 'https://api.stage.noda.live/api/payments' :
            'https://api.noda.live/api/payments';
        $currency = new Currency($cart->id_currency);
        $currencyCode = in_array($currency->iso_code, ['GBP', 'EUR', 'CAD', 'BRL', 'PLN', 'BGN', 'RON']) ? $currency->iso_code : 'GBP';

        $requestBody = [
            'amount' => $total,
            'currency' => $currencyCode,
            'email' => $customer->email,
            'description' => 'Order '. $customer->email,
            'paymentId' => hash( 'sha256', $customer->email . $cart->id),
            'shopId' => Configuration::get('NODAPAY_SHOP_ID'),
            'returnUrl' => Context::getContext()->link->getPageLink(''). 'index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key,
            'webhookUrl' => Context::getContext()->link->getPageLink('/module/nodapay/webhook') . '?id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key . '&merchant_id=' . Configuration::get('NODAPAY_SHOP_ID'),
        ];
        $response = $client->request('POST', $url, [
            'body' => json_encode($requestBody),
            'headers' => [
                'Accept' => 'application/json, text/json, text/plain',
                'Content-Type' => 'application/*+json',
                'x-api-key' => Configuration::get('NODAPAY_PRIVATE_CODE'),
                'Plugin-Type' => 'PrestaShop'
            ],
        ]);

        $body = $response->getBody()->getContents();
        $body = json_decode($body);
        Tools::redirect($body->url);
    }
}
