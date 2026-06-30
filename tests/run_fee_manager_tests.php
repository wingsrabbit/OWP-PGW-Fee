<?php

define('TERMRAT_GATEWAY_FEE_TEST', true);

require_once __DIR__ . '/../modules/addons/termrat_gateway_fee/lib/FeeManager.php';

function tgf_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, 'not ok - ' . $message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual) . "\n");
        exit(1);
    }
}

function tgf_fee($base, $percent)
{
    return TermRatGatewayFeeManager::calculateFeeAmount($base, $percent);
}

tgf_assert_same('3.00', tgf_fee('100.00', '3.00'), 'stripe invoice adds 3 percent fee');
tgf_assert_same('3.00', tgf_fee('100.00', '3'), 'integer percent is normalized');
tgf_assert_same('2.50', tgf_fee('100.00', '2.50'), 'fee percent is configurable');
tgf_assert_same('3.00', tgf_fee(TermRatGatewayFeeManager::subtractAmounts('103.00', '3.00'), '3.00'), 'base excludes existing fee');
tgf_assert_same('0.03', tgf_fee('1.00', '3.00'), 'small amount rounds to cents');
tgf_assert_same('0.00', tgf_fee('0.00', '3.00'), 'zero base has zero fee');
tgf_assert_same(10000, TermRatGatewayFeeManager::amountToCents('100.00'), 'amount to cents');
tgf_assert_same('100.00', TermRatGatewayFeeManager::formatCents(10000), 'cents to amount');
tgf_assert_same(
    '100.00',
    TermRatGatewayFeeManager::calculateInvoiceItemsBaseAmount(array(
        array('id' => 1, 'amount' => '70.00'),
        array('id' => 2, 'amount' => '30.00'),
        array('id' => 3, 'amount' => '3.00'),
    ), 3),
    'creation-stage base excludes existing fee line item'
);
tgf_assert_same(
    '80.00',
    TermRatGatewayFeeManager::calculateInvoiceItemsBaseAmount(array(
        array('id' => 1, 'amount' => '100.00'),
    ), 0, '20.00', '0.00'),
    'creation-stage base excludes invoice credit'
);
tgf_assert_same(
    '0.00',
    TermRatGatewayFeeManager::calculateInvoiceItemsBaseAmount(array(
        array('id' => 1, 'amount' => '10.00'),
    ), 0, '20.00', '0.00'),
    'creation-stage base floors credit overpayment at zero'
);

$defaultConfig = TermRatGatewayFeeManager::normalizeConfig(array());
tgf_assert_same(false, $defaultConfig['enabled'], 'module defaults disabled');
tgf_assert_same(false, $defaultConfig['production_canary_enabled'], 'production canary defaults disabled');
tgf_assert_same(true, $defaultConfig['production_canary_dry_run_only'], 'production canary dry-run-only defaults on');
tgf_assert_same(array(), $defaultConfig['production_canary_invoice_ids'], 'production canary invoice allowlist defaults empty');
tgf_assert_same(false, $defaultConfig['emergency_kill_switch'], 'emergency kill switch defaults off');

$canaryConfig = TermRatGatewayFeeManager::normalizeConfig(array(
    'enabled' => 'on',
    'production_canary_enabled' => 'on',
    'production_canary_dry_run_only' => '',
    'production_canary_invoice_ids' => "1001, 1002\n1001",
    'production_canary_client_ids' => '501; 502',
    'emergency_kill_switch' => 'yes',
));
tgf_assert_same(true, $canaryConfig['enabled'], 'module can be explicitly enabled');
tgf_assert_same(true, $canaryConfig['production_canary_enabled'], 'production canary can be enabled');
tgf_assert_same(false, $canaryConfig['production_canary_dry_run_only'], 'production canary dry-run can be explicitly disabled');
tgf_assert_same(array(1001, 1002), $canaryConfig['production_canary_invoice_ids'], 'production canary invoice allowlist normalizes ids');
tgf_assert_same(array(501, 502), $canaryConfig['production_canary_client_ids'], 'production canary client allowlist normalizes ids');
tgf_assert_same(true, $canaryConfig['emergency_kill_switch'], 'emergency kill switch can be enabled');

echo "ok - PHP fee manager math tests passed\n";
