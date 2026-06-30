<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/FeeManager.php';

function termrat_gateway_fee_hook_sync_invoice($vars, $reason)
{
    $invoiceId = termrat_gateway_fee_hook_invoice_id($vars);
    if (!$invoiceId) {
        return;
    }

    try {
        $manager = new TermRatGatewayFeeManager();
        $manager->syncInvoice($invoiceId, $reason);
    } catch (Exception $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('termrat_gateway_fee', $reason . ':error', array('invoice_id' => $invoiceId), array('error' => $e->getMessage()), array(), array());
        }
    }
}

function termrat_gateway_fee_hook_invoice_id($vars)
{
    foreach (array('invoiceid', 'invoiceId', 'invoice_id', 'id') as $key) {
        if (isset($vars[$key]) && (int) $vars[$key] > 0) {
            return (int) $vars[$key];
        }
    }

    return 0;
}

function termrat_gateway_fee_hook_sync_automation($reason)
{
    static $automationRan = false;

    if ($automationRan) {
        return;
    }
    $automationRan = true;

    try {
        $manager = new TermRatGatewayFeeManager();
        $manager->syncAutomationInvoices($reason);
    } catch (Exception $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('termrat_gateway_fee', $reason . ':error', array(), array('error' => $e->getMessage()), array(), array());
        }
    }
}

add_hook('InvoiceCreation', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'InvoiceCreation');
});

add_hook('InvoiceCreated', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'InvoiceCreated');
});

add_hook('InvoiceChangeGateway', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'InvoiceChangeGateway');
});

add_hook('ViewInvoiceDetailsPage', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'ViewInvoiceDetailsPage');
});

add_hook('ClientAreaPageViewInvoice', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'ClientAreaPageViewInvoice');
});

add_hook('PreCronJob', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_automation('PreCronJob');
});

add_hook('PreAutomationTask', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_automation('PreAutomationTask');
});
