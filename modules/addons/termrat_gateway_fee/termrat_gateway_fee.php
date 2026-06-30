<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Installer.php';
require_once __DIR__ . '/lib/FeeManager.php';

function termrat_gateway_fee_config()
{
    return array(
        'name' => 'TermRat Gateway Fee',
        'description' => 'Adds explicit invoice payment gateway processing fees for configured WHMCS payment gateways.',
        'version' => '0.1.0',
        'author' => 'TermRat',
        'language' => 'english',
        'fields' => array(
            'enabled' => array(
                'FriendlyName' => 'Enabled',
                'Type' => 'yesno',
                'Description' => 'Master switch. Defaults off; invoice writes also require dry-run-only off, kill switch off, and the selected mode rules.',
                'Default' => '',
            ),
            'mode' => array(
                'FriendlyName' => 'Mode',
                'Type' => 'dropdown',
                'Options' => 'canary,production',
                'Default' => 'canary',
                'Description' => 'canary requires exact invoice/client allowlists; production applies to all eligible Unpaid invoices.',
            ),
            'dry_run_only' => array(
                'FriendlyName' => 'Dry-run Only',
                'Type' => 'yesno',
                'Description' => 'Block all invoice writes in every mode and only log/report what would happen. Default on.',
                'Default' => 'on',
            ),
            'fee_percent' => array(
                'FriendlyName' => 'Fee Percent',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '3.00',
                'Description' => 'Percentage charged on the current invoice balance, excluding this module fee.',
            ),
            'gateways' => array(
                'FriendlyName' => 'Gateways',
                'Type' => 'text',
                'Size' => '48',
                'Default' => 'stripe,stripealipay',
                'Description' => 'Comma-separated WHMCS gateway system names.',
            ),
            'fee_description_en' => array(
                'FriendlyName' => 'English Fee Description',
                'Type' => 'text',
                'Size' => '80',
                'Default' => 'Payment gateway processing fee ({percent}%)',
                'Description' => 'Invoice item description for English clients. Use {percent} for the configured rate.',
            ),
            'fee_description_zh' => array(
                'FriendlyName' => 'Chinese Fee Description',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '支付网关手续费（{percent}%）',
                'Description' => 'Invoice item description for Chinese clients. Use {percent} for the configured rate.',
            ),
            'taxable' => array(
                'FriendlyName' => 'Taxable',
                'Type' => 'yesno',
                'Description' => 'Mark the fee invoice item as taxable.',
                'Default' => '',
            ),
            'debug_log' => array(
                'FriendlyName' => 'Debug Log',
                'Type' => 'yesno',
                'Description' => 'Write skip/no-op details to the WHMCS module log. Add/remove/error events are always logged.',
                'Default' => '',
            ),
            'production_canary_invoice_ids' => array(
                'FriendlyName' => 'Canary Invoice IDs',
                'Type' => 'text',
                'Size' => '48',
                'Default' => '',
                'Description' => 'Comma-separated invoice ids allowed for writes when mode=canary. Not used in production mode.',
            ),
            'production_canary_client_ids' => array(
                'FriendlyName' => 'Canary Client IDs',
                'Type' => 'text',
                'Size' => '48',
                'Default' => '',
                'Description' => 'Comma-separated client ids allowed for writes when mode=canary. Not used in production mode.',
            ),
            'emergency_kill_switch' => array(
                'FriendlyName' => 'Emergency Kill Switch',
                'Type' => 'yesno',
                'Description' => 'Immediately block sync, automation, and invoice writes while retaining module log visibility.',
                'Default' => '',
            ),
        ),
    );
}

function termrat_gateway_fee_activate()
{
    return TermRatGatewayFeeInstaller::activate();
}

function termrat_gateway_fee_deactivate()
{
    return TermRatGatewayFeeInstaller::deactivate();
}

function termrat_gateway_fee_uninstall()
{
    return TermRatGatewayFeeInstaller::uninstall();
}

function termrat_gateway_fee_output($vars)
{
    $manager = new TermRatGatewayFeeManager(TermRatGatewayFeeManager::configFromAddonVars($vars));
    $notice = '';
    $dryRunRows = '';

    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($requestMethod === 'POST' && isset($_POST['termrat_gateway_fee_action'])) {
        termrat_gateway_fee_verify_admin_token();
        $invoiceId = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0;
        $action = (string) $_POST['termrat_gateway_fee_action'];

        try {
            if ($action === 'dry_run') {
                $result = $manager->dryRunInvoice($invoiceId);
                $dryRunRows = termrat_gateway_fee_render_result_rows($result);
                $notice = termrat_gateway_fee_notice('info', 'Dry-run completed for invoice #' . $invoiceId . '.');
            } elseif ($action === 'resync' || $action === 'check') {
                $result = $manager->syncInvoice($invoiceId, 'admin-check');
                $dryRunRows = termrat_gateway_fee_render_result_rows($result);
                $notice = termrat_gateway_fee_notice('success', 'Check completed for invoice #' . $invoiceId . '.');
            }
        } catch (Exception $e) {
            $notice = termrat_gateway_fee_notice('danger', $e->getMessage());
        }
    }

    $template = file_get_contents(__DIR__ . '/templates/admin.tpl');
    $replacements = array(
        '{{notice}}' => $notice,
        '{{modulelink}}' => termrat_gateway_fee_h(isset($vars['modulelink']) ? $vars['modulelink'] : ''),
        '{{token}}' => termrat_gateway_fee_h(termrat_gateway_fee_generate_admin_token()),
        '{{config_rows}}' => termrat_gateway_fee_render_config_rows($manager->getConfigSummary()),
        '{{fee_rows}}' => termrat_gateway_fee_render_fee_rows($manager),
        '{{dry_run_rows}}' => $dryRunRows,
    );

    echo strtr($template, $replacements);
}

function termrat_gateway_fee_render_config_rows(array $config)
{
    $html = '';
    foreach ($config as $key => $value) {
        $html .= '<tr><th>' . termrat_gateway_fee_h($key) . '</th><td><code>' . termrat_gateway_fee_h($value) . '</code></td></tr>';
    }

    return $html;
}

function termrat_gateway_fee_render_fee_rows(TermRatGatewayFeeManager $manager)
{
    try {
        $rows = $manager->getRecentFees(50);
    } catch (Exception $e) {
        return '<tr><td colspan="7" class="text-danger">' . termrat_gateway_fee_h($e->getMessage()) . '</td></tr>';
    }

    $html = '';
    foreach ($rows as $row) {
        $html .= '<tr>';
        $html .= '<td><a href="invoices.php?action=edit&id=' . (int) $row->invoice_id . '">#' . (int) $row->invoice_id . '</a></td>';
        $html .= '<td><code>' . termrat_gateway_fee_h($row->gateway) . '</code></td>';
        $html .= '<td>' . termrat_gateway_fee_h($row->base_amount) . '</td>';
        $html .= '<td>' . termrat_gateway_fee_h($row->fee_amount) . '</td>';
        $html .= '<td><span class="label label-' . ($row->status === 'active' ? 'success' : 'default') . '">' . termrat_gateway_fee_h($row->status) . '</span></td>';
        $html .= '<td>' . termrat_gateway_fee_h($row->created_at) . '</td>';
        $html .= '<td>' . termrat_gateway_fee_h($row->updated_at) . '</td>';
        $html .= '</tr>';
    }

    if ($html === '') {
        $html = '<tr><td colspan="7" class="text-muted">No fee records yet.</td></tr>';
    }

    return $html;
}

function termrat_gateway_fee_render_result_rows(array $result)
{
    $html = '<table class="table table-condensed table-striped termrat-gateway-fee-result"><tbody>';
    foreach ($result as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        $html .= '<tr><th>' . termrat_gateway_fee_h($key) . '</th><td><code>' . termrat_gateway_fee_h($value) . '</code></td></tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function termrat_gateway_fee_notice($type, $message)
{
    return '<div class="alert alert-' . termrat_gateway_fee_h($type) . '">' . termrat_gateway_fee_h($message) . '</div>';
}

function termrat_gateway_fee_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function termrat_gateway_fee_generate_admin_token()
{
    if (function_exists('generate_token')) {
        return generate_token('plain');
    }

    return '';
}

function termrat_gateway_fee_verify_admin_token()
{
    if (function_exists('check_token')) {
        check_token('WHMCS.admin.default');
    }
}
