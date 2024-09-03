<?php

class NodapayWebhookModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $id = Tools::getValue('id');
        $result = Tools::getValue('result');
        $merchantId = Tools::getValue('merchant_id');
        $orderId = Tools::getValue('id_order');
        $signature = Tools::getValue('signature');
        $orderState = new OrderState((int) Configuration::getGlobalValue('NODAPAY_PAYMENT_DOCE'));

        $order = new Order((int) ($orderId));

        $expectedSignature = hash( 'sha256',
            $id . $result . Configuration::get('NODAPAY_SIGNATURE_KEY')
        );

        if (Configuration::get('NODAPAY_SHOP_ID')  !== $merchantId) {
            $orderState = new OrderState((int) Configuration::getGlobalValue('NODAPAY_PAYMENT_FAILED'));
        }

        $status = strtolower($result);
        if (!in_array($status, ['done', 'processing', 'failed'])) {
            $orderState = new OrderState((int) Configuration::getGlobalValue('NODAPAY_PAYMENT_FAILED'));
        }

        if (in_array($status, ['failed'])) {
            $orderState = new OrderState((int) Configuration::getGlobalValue('NODAPAY_PAYMENT_FAILED'));
        }

        if ($expectedSignature !== $signature) {
            $orderState = new OrderState((int) Configuration::getGlobalValue('NODAPAY_PAYMENT_FAILED'));
        }

        $history = new OrderHistory();
        $history->id_order = (int)$order->id;
        $history->changeIdOrderState($orderState->id, (int)($order->id));
    }
}
