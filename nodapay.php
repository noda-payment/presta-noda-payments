<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Nodapay extends PaymentModule
{
    const FLAG_DISPLAY_PAYMENT_INVITE = 'BANK_WIRE_PAYMENT_INVITE';
    const STATE_PAYMENT = 'NODAPAY_VALIDATION';
    const STATE_PAYMENT_DONE = 'NODAPAY_PAYMENT_DONE';
    const STATE_PAYMENT_FAILED = 'NODAPAY_PAYMENT_FAILED';

    protected $_html = '';
    protected $_postErrors = [];

    public $shopId;
    public $signatureKey;
    public $privateCode;
    public $extra_mail_vars;
    /**
     * @var int
     */
    public $is_eu_compatible;
    /**
     * @var false|int
     */
    public $evnMode;

    public function __construct()
    {
        $this->name = 'nodapay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->author = 'Nodapay';
        $this->controllers = ['payment', 'validation'];
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $config = Configuration::getMultiple(['NODAPAY_SHOP_ID', 'NODAPAY_SIGNATURE_KEY', 'NODAPAY_PRIVATE_CODE', 'NODAPAY_MODE']);
        if (!empty($config['NODAPAY_SIGNATURE_KEY'])) {
            $this->signatureKey = $config['NODAPAY_SIGNATURE_KEY'];
        }
        if (!empty($config['NODAPAY_SHOP_ID'])) {
            $this->shopId = $config['NODAPAY_SHOP_ID'];
        }
        if (!empty($config['NODAPAY_PRIVATE_CODE'])) {
            $this->privateCode = $config['NODAPAY_PRIVATE_CODE'];
        }
        if (!empty($config['NODAPAY_MODE'])) {
            $this->evnMode = $config['NODAPAY_MODE'];
        }

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Nodapay', [], 'Modules.Nodapay.Admin');
        $this->description = $this->trans('Accept wire payments by displaying your account details during the checkout.', [], 'Modules.Nodapay.Admin');
        $this->confirmUninstall = $this->trans('Are you sure about removing these details?', [], 'Modules.Nodapay.Admin');
        if ((!isset($this->signatureKey) || !isset($this->shopId) || !isset($this->privateCode)) && $this->active) {
            $this->warning = $this->trans('Account owner and account details must be configured before using this module.', [], 'Modules.Nodapay.Admin');
        }
        if (!count(Currency::checkPaymentCurrencies($this->id)) && $this->active) {
            $this->warning = $this->trans('No currency has been set for this module.', [], 'Modules.Nodapay.Admin');
        }

        $this->extra_mail_vars = [
            '{signatureKey}' => $this->signatureKey,
            '{shopId}' => nl2br($this->shopId ?: ''),
            '{privateCode}' => nl2br($this->privateCode ?: ''),
        ];
    }

    public function install()
    {
        Configuration::updateValue(self::FLAG_DISPLAY_PAYMENT_INVITE, true);
        $this->installOrderState();
        if (!parent::install()
            || !$this->registerHook('displayPaymentReturn')
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('nodaWebhook')
        ) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName('NODAPAY_SHOP_ID')
                || !Configuration::deleteByName('NODAPAY_SIGNATURE_KEY')
                || !Configuration::deleteByName('NODAPAY_PRIVATE_CODE')
                || !parent::uninstall()) {
            return false;
        }

        return true;
    }

    public function installOrderState()
    {
        if (!Configuration::getGlobalValue(self::STATE_PAYMENT)) {
            $orderState = new OrderState((int) Configuration::getGlobalValue(self::STATE_PAYMENT));

            if (!Validate::isLoadedObject($orderState)) {
                $this->createOrderState(
                    static::STATE_PAYMENT,
                    'Awaiting NodaPay validation',
                    true === (bool) version_compare(_PS_VERSION_, '1.7.7.0', '>=') ? '#4169E1' : '#34219E'
                );
            }
        }

        if (!Configuration::getGlobalValue(self::STATE_PAYMENT_FAILED)) {
            $orderState = new OrderState((int) Configuration::getGlobalValue(self::STATE_PAYMENT_FAILED));

            if (!Validate::isLoadedObject($orderState)) {
                $this->createOrderState(
                    static::STATE_PAYMENT_FAILED,
                    'NodaPay failed',
                    true === (bool) version_compare(_PS_VERSION_, '1.7.7.0', '>=') ? '#4169E1' : '#34219E'
                );
            }
        }

        if (!Configuration::getGlobalValue(self::STATE_PAYMENT_DONE)) {
            $orderState = new OrderState((int) Configuration::getGlobalValue(self::STATE_PAYMENT_DONE));

            if (!Validate::isLoadedObject($orderState)) {
                $this->createOrderState(
                    static::STATE_PAYMENT_DONE,
                    'NodaPay Done',
                    true === (bool) version_compare(_PS_VERSION_, '1.7.7.0', '>=') ? '#4169E1' : '#34219E'
                );
            }
        }
        return;
    }

    private function createOrderState(
        $configurationKey,
        $name,
        $color
    ) {
        $tabNameByLangId = [];

        foreach (Language::getLanguages(false) as $language) {
            $tabNameByLangId[(int) $language['id_lang']] = $name;
        }

        $orderState = new OrderState();
        $orderState->module_name = $this->name;
        $orderState->name = $tabNameByLangId;
        $orderState->color = $color;
        $orderState->logable = false;
        $orderState->paid = false;
        $orderState->invoice = false;
        $orderState->shipped = false;
        $orderState->delivery = false;
        $orderState->pdf_delivery = false;
        $orderState->pdf_invoice = false;
        $orderState->send_email = true;
        $orderState->hidden = false;
        $orderState->unremovable = true;
        $orderState->template = '';
        $orderState->deleted = false;
        $result = (bool) $orderState->add();

        if (false === $result) {
            $this->_errors[] = sprintf(
                'Failed to create OrderState %s',
                $configurationKey
            );

            return false;
        }

        $result = (bool) Configuration::updateGlobalValue($configurationKey, (int) $orderState->id);

        if (false === $result) {
            $this->_errors[] = sprintf(
                'Failed to save OrderState %s to Configuration',
                $configurationKey
            );

            return false;
        }

        $orderStateImgPath = $this->getLocalPath() . 'views/img/orderstate/logo.gif';

        if (false === (bool) Tools::file_exists_cache($orderStateImgPath)) {
            $this->_errors[] = sprintf(
                'Failed to find icon file of OrderState %s',
                $configurationKey
            );

            return false;
        }

        Tools::copy($orderStateImgPath, _PS_ORDER_STATE_IMG_DIR_ . $orderState->id . '.gif');

        return true;
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue(self::FLAG_DISPLAY_PAYMENT_INVITE,
                Tools::getValue(self::FLAG_DISPLAY_PAYMENT_INVITE));

            if (!Tools::getValue('NODAPAY_SHOP_ID')) {
                $this->_postErrors[] = $this->trans('Shop ID is required.', [], 'Modules.Nodapay.Admin');
            } elseif (!Tools::getValue('NODAPAY_SIGNATURE_KEY')) {
                $this->_postErrors[] = $this->trans('Signature Key is required.', [], 'Modules.Nodapay.Admin');
            } elseif (!Tools::getValue('NODAPAY_PRIVATE_CODE')) {
                $this->_postErrors[] = $this->trans('Secret Key is required.', [], 'Modules.Nodapay.Admin');
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('NODAPAY_SHOP_ID', Tools::getValue('NODAPAY_SHOP_ID'));
            Configuration::updateValue('NODAPAY_SIGNATURE_KEY', Tools::getValue('NODAPAY_SIGNATURE_KEY'));
            Configuration::updateValue('NODAPAY_PRIVATE_CODE', Tools::getValue('NODAPAY_PRIVATE_CODE'));

            $custom_text = [];
            $languages = Language::getLanguages(false);
            foreach ($languages as $lang) {
                if (Tools::getIsset('NODAPAY_CUSTOM_TEXT_' . $lang['id_lang'])) {
                    $custom_text[$lang['id_lang']] = Tools::getValue('NODAPAY_CUSTOM_TEXT_' . $lang['id_lang']);
                }
            }
            Configuration::updateValue('NODAPAY_MODE', Tools::getValue('NODAPAY_MODE'));
            Configuration::updateValue('NODAPAY_CUSTOM_TEXT', $custom_text);
        }
        $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Global'));
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return [];
        }

        if (!$this->checkCurrency($params['cart'])) {
            return [];
        }

        $this->smarty->assign(
            $this->getTemplateVarInfos()
        );

        $newOption = new PaymentOption();
        $newOption->setModuleName($this->name)
                ->setCallToActionText($this->trans('NodaPay', [], 'Modules.Nodapay.Shop'))
                ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
                ->setAdditionalInformation($this->fetch('module:nodapay/views/templates/hook/nodapay_intro.tpl'));
        $payment_options = [
            $newOption,
        ];

        return $payment_options;
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || !Configuration::get(self::FLAG_DISPLAY_PAYMENT_INVITE)) {
            return;
        }

        $signatureKey = $this->signatureKey;
        if (!$signatureKey) {
            $signatureKey = '___________';
        }

        $shopId = Tools::nl2br($this->shopId);
        if (!$shopId) {
            $shopId = '___________';
        }

        $privateCode = Tools::nl2br($this->privateCode);
        if (!$privateCode) {
            $privateCode = '___________';
        }

        $totalToPaid = $params['order']->getOrdersTotalPaid() - $params['order']->getTotalPaid();
        $this->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'total' => $this->context->getCurrentLocale()->formatPrice(
                $totalToPaid,
                (new Currency($params['order']->id_currency))->iso_code
            ),
            'shopId' => $shopId,
            'privateCode' => $privateCode,
            'signatureKey' => $signatureKey,
            'status' => 'ok',
            'reference' => $params['order']->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true),
        ]);

        return $this->fetch('module:nodapay/views/templates/hook/payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    public function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Account details', [], 'Modules.Nodapay.Admin'),
                    'icon' => 'icon-envelope',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Shop ID', [], 'Modules.Nodapay.Admin'),
                        'name' => 'NODAPAY_SHOP_ID',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Secret Key', [], 'Modules.Nodapay.Admin'),
                        'name' => 'NODAPAY_PRIVATE_CODE',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Signature Key', [], 'Modules.Nodapay.Admin'),
                        'name' => 'NODAPAY_SIGNATURE_KEY',
                        'required' => true,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Enable Sandbox', [], 'Modules.Nodapay.Admin'),
                        'name' => 'NODAPAY_MODE',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->trans('Yes', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->trans('No', [], 'Admin.Global'),
                            ],
                        ],
                    ]
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?: 0;
        $helper->id = (int) Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure='
            . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$fields_form]);
    }

    public function getConfigFieldsValues()
    {
        $custom_text = [];
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $custom_text[$lang['id_lang']] = Tools::getValue(
                'NODAPAY_CUSTOM_TEXT_' . $lang['id_lang'],
                Configuration::get('NODAPAY_CUSTOM_TEXT', $lang['id_lang'])
            );
        }

        return [
            'NODAPAY_SHOP_ID' => Tools::getValue('NODAPAY_SHOP_ID', $this->shopId),
            'NODAPAY_SIGNATURE_KEY' => Tools::getValue('NODAPAY_SIGNATURE_KEY', $this->signatureKey),
            'NODAPAY_PRIVATE_CODE' => Tools::getValue('NODAPAY_PRIVATE_CODE', $this->privateCode),
            'NODAPAY_MODE' => Tools::getValue('NODAPAY_MODE', $this->evnMode),
            'NODAPAY_CUSTOM_TEXT' => $custom_text,
            self::FLAG_DISPLAY_PAYMENT_INVITE => Tools::getValue(
                self::FLAG_DISPLAY_PAYMENT_INVITE,
                Configuration::get(self::FLAG_DISPLAY_PAYMENT_INVITE)
            ),
        ];
    }

    public function getTemplateVarInfos()
    {
        $cart = $this->context->cart;
        $total = sprintf(
            $this->trans('%1$s (tax incl.)', [], 'Modules.Nodapay.Shop'),
            $this->context->getCurrentLocale()->formatPrice($cart->getOrderTotal(true, Cart::BOTH), $this->context->currency->iso_code)
        );

        $signatureKey = $this->signatureKey;
        if (!$signatureKey) {
            $signatureKey = '___________';
        }

        $shopId = Tools::nl2br($this->shopId);
        if (!$shopId) {
            $shopId = '___________';
        }

        $privateCode = Tools::nl2br($this->privateCode);
        if (!$privateCode) {
            $privateCode = '___________';
        }

        $evnMode = $this->evnMode;
        if (false === $evnMode) {
            $evnMode = 1;
        }

        $customText = Tools::nl2br(Configuration::get('NODAPAY_CUSTOM_TEXT', $this->context->language->id));
        if (empty($customText)) {
            $customText = '';
        }

        $client = new \GuzzleHttp\Client();
        $currency = new Currency($cart->id_currency);
        $currencyCode = in_array($currency->iso_code, ['GBP', 'EUR', 'CAD', 'BRL', 'PLN', 'BGN', 'RON']) ? $currency->iso_code : 'GBP';

        $logoUrl = '';
        try {
            $url = Configuration::get('NODAPAY_MODE') ? 'https://api.stage.noda.live/api/payments/logo' :
                'https://api.noda.live/api/payments/logo';

            $response = $client->request('POST', $url, [
                'body' => '{
                "currency": "'.$currencyCode.'"
            }',
                'headers' => [
                    'Accept' => 'application/json, text/json, text/plain',
                    'Content-Type' => 'application/*+json',
                    'x-api-key' => '24d0034-5a83-47d5-afa0-cca47298c516',
                ],
            ]);

            $body = $response->getBody()->getContents();
            $body = json_decode($body);
            $logoUrl = $body->url;
        } catch (\Throwable $e) {
        }

        return [
            'total' => $total,
            'shopId' => $shopId,
            'privateCode' => $privateCode,
            'signatureKey' => $signatureKey,
            'nodaLogo' => $logoUrl,
            'mode' => (int) $evnMode,
            'customText' => $customText,
        ];
    }
}
