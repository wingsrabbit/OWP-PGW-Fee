<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/FeeManager.php';

function termrat_gateway_fee_hook_sync_invoice($vars, $reason, $creationStage = false)
{
    $invoiceId = termrat_gateway_fee_hook_invoice_id($vars);
    if (!$invoiceId) {
        return;
    }

    try {
        $manager = new TermRatGatewayFeeManager();
        if ($creationStage) {
            $manager->syncInvoiceCreation($invoiceId, $reason);
        } else {
            $manager->syncInvoice($invoiceId, $reason);
        }
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

function termrat_gateway_fee_hook_sync_automation($reason, $vars = array())
{
    static $automationRuns = array();

    $context = termrat_gateway_fee_hook_automation_context($reason, $vars);
    $runKey = termrat_gateway_fee_hook_automation_run_key($reason, $context);

    if ($runKey !== '' && isset($automationRuns[$runKey])) {
        return;
    }
    if ($runKey !== '') {
        $automationRuns[$runKey] = true;
    }

    try {
        $manager = new TermRatGatewayFeeManager();
        $manager->syncAutomationInvoices($context['manager_reason']);
    } catch (Exception $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('termrat_gateway_fee', $context['manager_reason'] . ':error', $context, array('error' => $e->getMessage()), array(), array());
        }
    }
}

function termrat_gateway_fee_hook_automation_context($reason, $vars = array())
{
    $task = termrat_gateway_fee_hook_automation_task_source($vars);
    $taskName = termrat_gateway_fee_hook_automation_task_name($task);
    $taskKey = termrat_gateway_fee_hook_automation_task_key($taskName);
    $managerReason = $reason;

    if ($taskKey !== '') {
        $managerReason .= ':' . $taskKey;
    }

    return array(
        'hook' => $reason,
        'task_name' => $taskName,
        'task_key' => $taskKey,
        'manager_reason' => $managerReason,
    );
}

function termrat_gateway_fee_hook_automation_run_key($reason, array $context)
{
    if ($reason === 'PreCronJob') {
        return 'PreCronJob';
    }

    if ($reason === 'PreAutomationTask') {
        return $context['task_key'] !== '' ? 'PreAutomationTask:' . $context['task_key'] : '';
    }

    return $reason;
}

function termrat_gateway_fee_hook_automation_task_source($vars)
{
    if (is_array($vars) && array_key_exists('task', $vars)) {
        return $vars['task'];
    }

    return $vars;
}

function termrat_gateway_fee_hook_automation_task_name($task)
{
    if (is_string($task)) {
        return trim($task);
    }

    if (is_array($task)) {
        foreach (array('name', 'task', 'taskName', 'task_name', 'description') as $key) {
            if (isset($task[$key]) && trim((string) $task[$key]) !== '') {
                return trim((string) $task[$key]);
            }
        }
    }

    if (is_object($task)) {
        foreach (array('name', 'task', 'taskName', 'task_name', 'description') as $key) {
            if (isset($task->{$key}) && trim((string) $task->{$key}) !== '') {
                return trim((string) $task->{$key});
            }
        }

        if (method_exists($task, 'get')) {
            foreach (array('name', 'task', 'taskName', 'task_name', 'description') as $key) {
                try {
                    $value = $task->get($key);
                    if (trim((string) $value) !== '') {
                        return trim((string) $value);
                    }
                } catch (Exception $e) {
                }
            }
        }

        if (method_exists($task, 'toArray')) {
            try {
                return termrat_gateway_fee_hook_automation_task_name($task->toArray());
            } catch (Exception $e) {
            }
        }
    }

    return '';
}

function termrat_gateway_fee_hook_automation_task_key($taskName)
{
    $taskName = strtolower(trim((string) $taskName));
    if ($taskName === '') {
        return '';
    }

    $taskName = preg_replace('/[^a-z0-9_.:-]+/', '-', $taskName);
    $taskName = trim($taskName, '-');

    return substr($taskName, 0, 120);
}

add_hook('InvoiceCreation', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'InvoiceCreation', true);
});

add_hook('InvoiceCreated', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'InvoiceCreated');
});

add_hook('InvoiceChangeGateway', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'InvoiceChangeGateway');
});

add_hook('InvoicePaidPreEmail', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'InvoicePaidPreEmail');
});

add_hook('ViewInvoiceDetailsPage', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'ViewInvoiceDetailsPage');
});

add_hook('ClientAreaPageViewInvoice', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_invoice($vars, 'ClientAreaPageViewInvoice');
});

add_hook('PreCronJob', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_automation('PreCronJob', $vars);
});

add_hook('PreAutomationTask', 1, function ($vars) {
    termrat_gateway_fee_hook_sync_automation('PreAutomationTask', $vars);
});
