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

echo "ok - PHP hook context tests passed\n";
