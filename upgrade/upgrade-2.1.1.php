<?php

function upgrade_module_2_1_1($module)
{
    $module->unregisterHook('paymentReturn');
    $module->registerHook('displayPaymentReturn');

    return true;
}
