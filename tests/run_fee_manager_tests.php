<?php

define('TERMRAT_GATEWAY_FEE_TEST', true);

require_once __DIR__ . '/../modules/addons/termrat_gateway_fee/lib/FeeManager.php';

function tgf_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "not ok - {$message}: expected {$expected}, got {$actual}\n");
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

echo "ok - PHP fee manager math tests passed\n";
