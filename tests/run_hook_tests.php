<?php

define('WHMCS', true);
define('TERMRAT_GATEWAY_FEE_TEST', true);

$registeredHooks = array();

function add_hook($name, $priority, $callback)
{
    global $registeredHooks;
    $registeredHooks[] = array($name, $priority, $callback);
}

require_once __DIR__ . '/../modules/addons/termrat_gateway_fee/hooks.php';

function tgf_hook_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "not ok - {$message}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

$preCronContext = termrat_gateway_fee_hook_automation_context('PreCronJob', array());
$captureContext = termrat_gateway_fee_hook_automation_context('PreAutomationTask', array(
    'task' => array('name' => 'Credit Card Charges'),
));
$unknownTaskContext = termrat_gateway_fee_hook_automation_context('PreAutomationTask', array());

tgf_hook_assert_same('PreCronJob', termrat_gateway_fee_hook_automation_run_key('PreCronJob', $preCronContext), 'pre-cron has its own run key');
tgf_hook_assert_same('credit-card-charges', $captureContext['task_key'], 'task name is normalized');
tgf_hook_assert_same('PreAutomationTask:credit-card-charges', termrat_gateway_fee_hook_automation_run_key('PreAutomationTask', $captureContext), 'pre-automation dedupes by task');
tgf_hook_assert_same('', termrat_gateway_fee_hook_automation_run_key('PreAutomationTask', $unknownTaskContext), 'unknown automation task is not deduped');
tgf_hook_assert_same(1234, termrat_gateway_fee_hook_transaction_invoice_id(array('invocieid' => 1234, 'id' => 99)), 'transaction hook uses WHMCS typo invoice id key before transaction id');
tgf_hook_assert_same(0, termrat_gateway_fee_hook_transaction_invoice_id(array('id' => 99)), 'transaction hook does not treat transaction id as invoice id');

$hookNames = array();
foreach ($registeredHooks as $registeredHook) {
    $hookNames[] = $registeredHook[0];
}
tgf_hook_assert_same(true, in_array('AddTransaction', $hookNames, true), 'add transaction hook is registered for ApplyCredit transaction sync');
tgf_hook_assert_same(true, in_array('AddInvoicePayment', $hookNames, true), 'add invoice payment hook is registered for payment sync');
tgf_hook_assert_same(true, in_array('InvoicePaidPreEmail', $hookNames, true), 'invoice paid pre-email hook is registered for credit-only Apply Credit cleanup');

echo "ok - PHP hook context tests passed\n";
