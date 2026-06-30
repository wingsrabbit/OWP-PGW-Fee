<?php

if (!defined('WHMCS') && !defined('TERMRAT_GATEWAY_FEE_TEST')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class TermRatGatewayFeeManager
{
    const MODULE = 'termrat_gateway_fee';
    const TABLE = 'mod_termrat_gateway_fee_items';
    const LOCK_TIMEOUT = 10;
    const AUTOMATION_LIMIT = 500;

    private $config;
    private $apiRunner;
    private $logger;

    public function __construct(?array $config = null, $apiRunner = null, $logger = null)
    {
        $this->config = $config ? self::normalizeConfig($config) : $this->loadConfigFromDatabase();
        $this->apiRunner = $apiRunner;
        $this->logger = $logger;
    }

    public static function defaults()
    {
        return array(
            'enabled' => false,
            'mode' => 'canary',
            'dry_run_only' => true,
            'fee_percent' => '3.00',
            'gateways' => array('stripe', 'stripealipay'),
            'fee_description_en' => 'Payment gateway processing fee ({percent}%)',
            'fee_description_zh' => '支付网关手续费（{percent}%）',
            'taxable' => false,
            'debug_log' => false,
            // Legacy canary names are retained for existing saved WHMCS settings.
            'production_canary_enabled' => false,
            'production_canary_dry_run_only' => true,
            'production_canary_invoice_ids' => array(),
            'production_canary_client_ids' => array(),
            'emergency_kill_switch' => false,
        );
    }

    public static function normalizeConfig(array $input)
    {
        $defaults = self::defaults();
        if (!array_key_exists('dry_run_only', $input) && array_key_exists('production_canary_dry_run_only', $input)) {
            $input['dry_run_only'] = $input['production_canary_dry_run_only'];
        }

        $config = array_merge($defaults, $input);

        $config['enabled'] = self::toBool($config['enabled']);
        $config['mode'] = self::normalizeMode($config['mode']);
        $config['dry_run_only'] = self::toBool($config['dry_run_only']);
        $config['taxable'] = self::toBool($config['taxable']);
        $config['debug_log'] = self::toBool($config['debug_log']);
        $config['production_canary_enabled'] = self::toBool($config['production_canary_enabled']);
        $config['production_canary_dry_run_only'] = self::toBool($config['production_canary_dry_run_only']);
        $config['emergency_kill_switch'] = self::toBool($config['emergency_kill_switch']);
        $config['production_canary_dry_run_only'] = $config['dry_run_only'];
        $config['fee_percent'] = self::normalizePercent($config['fee_percent']);

        if (is_array($config['gateways'])) {
            $gateways = $config['gateways'];
        } else {
            $gateways = explode(',', (string) $config['gateways']);
        }

        $normalizedGateways = array();
        foreach ($gateways as $gateway) {
            $gateway = strtolower(trim((string) $gateway));
            if ($gateway !== '') {
                $normalizedGateways[$gateway] = true;
            }
        }
        $config['gateways'] = array_keys($normalizedGateways);

        foreach (array('fee_description_en', 'fee_description_zh') as $key) {
            $config[$key] = trim((string) $config[$key]);
            if ($config[$key] === '') {
                $config[$key] = $defaults[$key];
            }
        }

        $config['production_canary_invoice_ids'] = self::normalizeIdList($config['production_canary_invoice_ids']);
        $config['production_canary_client_ids'] = self::normalizeIdList($config['production_canary_client_ids']);

        return $config;
    }

    public static function configFromAddonVars(array $vars)
    {
        $config = self::defaults();
        foreach (array_keys($config) as $key) {
            if (array_key_exists($key, $vars)) {
                $config[$key] = $vars[$key];
            }
        }

        return self::normalizeConfig($config);
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getConfigSummary()
    {
        return array(
            'enabled' => $this->config['enabled'] ? 'on' : 'off',
            'mode' => $this->config['mode'],
            'dry_run_only' => $this->config['dry_run_only'] ? 'on' : 'off',
            'fee_percent' => $this->config['fee_percent'],
            'gateways' => implode(',', $this->config['gateways']),
            'fee_description_en' => $this->config['fee_description_en'],
            'fee_description_zh' => $this->config['fee_description_zh'],
            'taxable' => $this->config['taxable'] ? 'on' : 'off',
            'debug_log' => $this->config['debug_log'] ? 'on' : 'off',
            'production_canary_invoice_ids' => implode(',', $this->config['production_canary_invoice_ids']),
            'production_canary_client_ids' => implode(',', $this->config['production_canary_client_ids']),
            'emergency_kill_switch' => $this->config['emergency_kill_switch'] ? 'on' : 'off',
        );
    }

    public function syncInvoice($invoiceId, $reason = 'manual')
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return array('action' => 'skipped', 'message' => 'Invalid invoice id.');
        }

        if ($this->config['emergency_kill_switch']) {
            return $this->emergencyKilled($invoiceId, $reason);
        }

        return $this->withInvoiceLock($invoiceId, function () use ($invoiceId, $reason) {
            return $this->syncInvoiceLocked($invoiceId, $reason);
        });
    }

    public function syncInvoiceCreation($invoiceId, $reason = 'InvoiceCreation')
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return array('action' => 'skipped', 'message' => 'Invalid invoice id.');
        }

        if ($this->config['emergency_kill_switch']) {
            return $this->emergencyKilled($invoiceId, $reason);
        }

        return $this->withInvoiceLock($invoiceId, function () use ($invoiceId, $reason) {
            return $this->syncInvoiceCreationLocked($invoiceId, $reason);
        });
    }

    public function dryRunInvoice($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return array('action' => 'skipped', 'message' => 'Invalid invoice id.');
        }

        $snapshot = $this->getInvoiceSnapshot($invoiceId);
        $activeFee = $this->getActiveFee($invoiceId);
        $baseAmount = $this->calculateBaseAmount($snapshot, $activeFee);
        $feeAmount = self::calculateFeeAmount($baseAmount, $this->config['fee_percent']);
        $applicable = $this->isInvoiceApplicable($snapshot);

        $action = 'noop';
        if ($this->shouldRemoveFeeForAppliedCredit($snapshot, $activeFee, $baseAmount)) {
            $action = 'would-remove';
        } elseif (!$this->isInvoiceModifiable($snapshot)) {
            $action = 'skipped';
        } elseif (!$applicable && $activeFee) {
            $action = 'would-remove';
        } elseif ($applicable && !$activeFee && self::amountToCents($feeAmount) > 0) {
            $action = 'would-add';
        } elseif ($applicable && $activeFee && $this->activeFeeNeedsInvoiceRefresh($activeFee, $baseAmount, $feeAmount)) {
            $action = 'would-refresh';
        } elseif ($applicable && $activeFee && $this->activeFeeGatewayChanged($activeFee, $snapshot)) {
            $action = 'would-update-gateway';
        }

        $writeStatus = $this->writeStatus($snapshot, 'dry-run', 'inspect');
        if (strpos($action, 'would-') === 0 && !$writeStatus['allowed']) {
            $action = $writeStatus['action'];
        }

        return array(
            'action' => $action,
            'invoice_id' => $invoiceId,
            'status' => $snapshot['status'],
            'gateway' => $snapshot['paymentmethod'],
            'base_amount' => $baseAmount,
            'fee_percent' => $this->config['fee_percent'],
            'fee_amount' => $feeAmount,
            'mode' => $this->config['mode'],
            'write_status' => $writeStatus['message'],
            'emergency_kill_switch' => $this->config['emergency_kill_switch'] ? 'on' : 'off',
            'message' => $this->explainApplicability($snapshot),
        );
    }

    public function syncAutomationInvoices($reason = 'automation')
    {
        $stats = array('scanned' => 0, 'synced' => 0, 'unsupported' => 0, 'blocked' => 0, 'errors' => 0);

        if ($this->config['emergency_kill_switch']) {
            $stats['blocked']++;
            $this->log('emergency-kill-switch', array('reason' => $reason), array('message' => 'Automation skipped because the emergency kill switch is on.'), true);

            return $stats;
        }

        if (!$this->config['enabled']) {
            $stats['blocked']++;
            $this->log('automation-blocked', array('reason' => $reason), array('message' => 'Automation skipped because the module is disabled.'), true);

            return $stats;
        }

        if ($this->config['mode'] !== 'production') {
            $stats['blocked']++;
            $this->log('automation-blocked', array('reason' => $reason, 'mode' => $this->config['mode']), array('message' => 'Automation batch scans are only enabled in production mode.'), true);

            return $stats;
        }

        foreach ($this->findAutomationCandidateInvoiceIds() as $invoiceId) {
            $stats['scanned']++;
            try {
                $result = $this->config['dry_run_only'] ? $this->dryRunInvoice($invoiceId) : $this->syncInvoice($invoiceId, $reason);
                if ($result['action'] === 'noop' || $result['action'] === 'skipped') {
                    $stats['synced']++;
                } elseif (strpos($result['action'], 'would-') === 0 || strpos($result['action'], 'dry-run') !== false || strpos($result['action'], 'mode-') === 0) {
                    $stats['blocked']++;
                } elseif (strpos($result['action'], 'unsupported') === 0) {
                    $stats['unsupported']++;
                } else {
                    $stats['synced']++;
                }
            } catch (Exception $e) {
                $stats['errors']++;
                $this->log('automation-error', array('invoice_id' => $invoiceId, 'reason' => $reason), array('error' => $e->getMessage()), true);
            }
        }

        $this->debug('automation-summary', array('reason' => $reason, 'mode' => $this->config['mode']), $stats);

        return $stats;
    }

    public function getRecentFees($limit = 50)
    {
        $limit = max(1, min(200, (int) $limit));
        $this->assertCapsule();

        return Capsule::table(self::TABLE)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public static function calculateFeeAmount($baseAmount, $feePercent)
    {
        $baseCents = max(0, self::amountToCents($baseAmount));
        $percentUnits = self::percentToUnits($feePercent);
        $feeCents = self::roundFraction($baseCents * $percentUnits, 1000000);

        return self::formatCents($feeCents);
    }

    public static function subtractAmounts($left, $right)
    {
        return self::formatCents(self::amountToCents($left) - self::amountToCents($right));
    }

    public static function addAmounts($left, $right)
    {
        return self::formatCents(self::amountToCents($left) + self::amountToCents($right));
    }

    public static function calculateInvoiceItemsBaseAmount($items, $excludeInvoiceItemId = 0, $creditAmount = '0.00', $amountPaid = '0.00')
    {
        $baseCents = self::amountToCents(self::calculateInvoiceItemsGrossAmount($items, $excludeInvoiceItemId));
        $baseCents -= self::amountToCents($creditAmount);
        $baseCents -= self::amountToCents($amountPaid);

        return self::formatCents(max(0, $baseCents));
    }

    public static function calculateInvoiceItemsGrossAmount($items, $excludeInvoiceItemId = 0)
    {
        $excludeInvoiceItemId = (int) $excludeInvoiceItemId;
        $grossCents = 0;

        foreach ($items as $item) {
            $itemId = (int) self::readValue($item, 'id', 0);
            if ($excludeInvoiceItemId > 0 && $itemId === $excludeInvoiceItemId) {
                continue;
            }

            $grossCents += self::amountToCents(self::readValue($item, 'amount', '0.00'));
        }

        return self::formatCents(max(0, $grossCents));
    }

    public static function capAppliedCreditToNonFeeAmount($items, $excludeInvoiceItemId, $creditAmount, $amountPaid = '0.00')
    {
        $maxCreditCents = self::amountToCents(self::calculateInvoiceItemsGrossAmount($items, $excludeInvoiceItemId));
        $maxCreditCents -= self::amountToCents($amountPaid);
        $maxCreditCents = max(0, $maxCreditCents);
        $creditCents = max(0, self::amountToCents($creditAmount));

        return self::formatCents(min($creditCents, $maxCreditCents));
    }

    public static function amountToCents($amount)
    {
        $value = trim(str_replace(',', '', (string) $amount));
        if ($value === '') {
            return 0;
        }

        $negative = false;
        if ($value[0] === '-') {
            $negative = true;
            $value = substr($value, 1);
        }

        if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return 0;
        }

        $parts = explode('.', $value, 2);
        $whole = (int) $parts[0];
        $fraction = isset($parts[1]) ? $parts[1] : '';
        $fraction = preg_replace('/\D/', '', $fraction);
        $fraction = str_pad($fraction, 3, '0');

        $cents = ($whole * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    public static function formatCents($cents)
    {
        $cents = (int) $cents;
        $negative = $cents < 0;
        $cents = abs($cents);
        $whole = intdiv($cents, 100);
        $fraction = $cents % 100;

        return ($negative ? '-' : '') . $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    private function syncInvoiceLocked($invoiceId, $reason)
    {
        $snapshot = $this->getInvoiceSnapshot($invoiceId);
        $activeFee = $this->getActiveFee($invoiceId);

        return $this->inspectPublishedInvoice($snapshot, $activeFee, $reason);
    }

    private function syncInvoiceCreationLocked($invoiceId, $reason)
    {
        $snapshot = $this->getInvoiceSnapshot($invoiceId);
        $activeFee = $this->getActiveFee($invoiceId);

        if (!$this->isInvoiceCreationApplicable($snapshot)) {
            $this->debug('creation-skip', array('invoice_id' => $invoiceId, 'reason' => $reason), array('message' => $this->explainCreationApplicability($snapshot)));

            return array('action' => 'skipped', 'message' => $this->explainCreationApplicability($snapshot));
        }

        $baseAmount = $this->calculateBaseAmountFromItems($snapshot, $activeFee);
        $feeAmount = self::calculateFeeAmount($baseAmount, $this->config['fee_percent']);

        if (self::amountToCents($feeAmount) <= 0) {
            return array('action' => 'skipped', 'message' => 'Calculated creation-stage fee is zero.');
        }

        if ($activeFee && !$this->activeFeeNeedsRefresh($activeFee, $snapshot, $baseAmount, $feeAmount)) {
            $this->debug('creation-noop', array('invoice_id' => $invoiceId, 'reason' => $reason), array('base_amount' => $baseAmount, 'fee_amount' => $feeAmount));

            return array(
                'action' => 'noop',
                'invoice_id' => $invoiceId,
                'base_amount' => $baseAmount,
                'fee_amount' => $feeAmount,
            );
        }

        if ($activeFee) {
            $removeResult = $this->removeFee($activeFee, $snapshot, $reason . ':refresh');
            if ($removeResult['action'] !== 'removed') {
                return $removeResult;
            }

            $snapshot = $this->getInvoiceSnapshot($invoiceId);
            $baseAmount = $this->calculateBaseAmountFromItems($snapshot, null);
            $feeAmount = self::calculateFeeAmount($baseAmount, $this->config['fee_percent']);
        }

        return $this->addFee($snapshot, $baseAmount, $feeAmount, $reason . ':line-items-base');
    }

    private function addFee(array $snapshot, $baseAmount, $feeAmount, $reason)
    {
        $invoiceId = (int) $snapshot['id'];
        $writeGuard = $this->guardInvoiceWrite($snapshot, $reason, 'add', array(
            'base_amount' => $baseAmount,
            'fee_amount' => $feeAmount,
        ));
        if ($writeGuard !== null) {
            return $writeGuard;
        }

        $description = $this->getFeeDescription($snapshot);
        $request = array(
            'invoiceid' => $invoiceId,
            'newitemdescription' => array($description),
            'newitemamount' => array($feeAmount),
            'newitemtaxed' => array($this->config['taxable'] ? '1' : '0'),
        );

        $apiResult = $this->callApi('UpdateInvoice', $request);
        $this->assertApiSuccess('UpdateInvoice:add', $apiResult);
        $invoiceItemId = $this->findLatestFeeInvoiceItemId($invoiceId, $description, $feeAmount);

        if (!$invoiceItemId) {
            throw new RuntimeException('Could not locate the inserted WHMCS invoice item.');
        }

        $now = $this->now();
        Capsule::table(self::TABLE)->insert(array(
            'invoice_id' => $invoiceId,
            'invoice_item_id' => $invoiceItemId,
            'active_invoice_id' => $invoiceId,
            'gateway' => $snapshot['paymentmethod'],
            'fee_percent' => $this->config['fee_percent'],
            'base_amount' => $baseAmount,
            'fee_amount' => $feeAmount,
            'currency_id' => $snapshot['currency_id'],
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        $this->recalculateInvoice($invoiceId);
        $this->log('fee-added', array('invoice_id' => $invoiceId, 'reason' => $reason), array('gateway' => $snapshot['paymentmethod'], 'base_amount' => $baseAmount, 'fee_amount' => $feeAmount), true);

        return array(
            'action' => 'added',
            'invoice_id' => $invoiceId,
            'invoice_item_id' => $invoiceItemId,
            'base_amount' => $baseAmount,
            'fee_amount' => $feeAmount,
        );
    }

    private function removeFee($activeFee, array $snapshot, $reason, $targetAppliedCreditAmount = null)
    {
        $invoiceId = (int) $activeFee->invoice_id;
        $invoiceItemId = (int) $activeFee->invoice_item_id;
        $guardDetails = array(
            'invoice_item_id' => $invoiceItemId,
        );
        if ($targetAppliedCreditAmount !== null) {
            $guardDetails['target_applied_credit_amount'] = $targetAppliedCreditAmount;
        }

        $writeGuard = $this->guardInvoiceWrite($snapshot, $reason, 'remove', $guardDetails);
        if ($writeGuard !== null) {
            return $writeGuard;
        }

        $request = array('invoiceid' => $invoiceId);
        if ($invoiceItemId > 0 && $this->invoiceItemExists($invoiceItemId)) {
            $request['deletelineids'] = array($invoiceItemId);
        }

        if (isset($request['deletelineids'])) {
            $apiResult = $this->callApi('UpdateInvoice', $request);
            $this->assertApiSuccess('UpdateInvoice:remove', $apiResult);
        }

        $creditAdjustment = null;
        if ($targetAppliedCreditAmount !== null) {
            $creditAdjustment = $this->capAppliedCreditSources($snapshot, $targetAppliedCreditAmount, $reason);
        }

        Capsule::table(self::TABLE)
            ->where('id', (int) $activeFee->id)
            ->update(array(
                'status' => 'removed',
                'active_invoice_id' => null,
                'updated_at' => $this->now(),
            ));

        $this->recalculateInvoice($invoiceId);
        $response = array('gateway' => $snapshot['paymentmethod'], 'invoice_item_id' => $invoiceItemId);
        if ($creditAdjustment !== null) {
            $response['credit_adjustment'] = $creditAdjustment;
        }
        $this->log('fee-removed', array('invoice_id' => $invoiceId, 'reason' => $reason), $response, true);

        $result = array('action' => 'removed', 'invoice_id' => $invoiceId, 'invoice_item_id' => $invoiceItemId);
        if ($creditAdjustment !== null) {
            $result['credit_adjustment'] = $creditAdjustment;
        }

        return $result;
    }

    private function calculateBaseAmount(array $snapshot, $activeFee)
    {
        return $this->calculateBaseAmountFromItems($snapshot, $activeFee);
    }

    private function calculateBaseAmountFromItems(array $snapshot, $activeFee)
    {
        $excludeInvoiceItemId = $activeFee ? (int) $activeFee->invoice_item_id : 0;

        return self::calculateInvoiceItemsBaseAmount($snapshot['items'], $excludeInvoiceItemId, $snapshot['applied_credit_amount'], $snapshot['external_paid_amount']);
    }

    private function inspectPublishedInvoice(array $snapshot, $activeFee, $reason)
    {
        $baseAmount = $this->calculateBaseAmount($snapshot, $activeFee);
        $feeAmount = self::calculateFeeAmount($baseAmount, $this->config['fee_percent']);

        if ($this->shouldRemoveFeeForAppliedCredit($snapshot, $activeFee, $baseAmount)) {
            return $this->removeFee($activeFee, $snapshot, $reason . ':applied-credit-remove', $this->creditAmountWithoutFeeOverage($snapshot, $activeFee));
        }

        if (!$this->isInvoiceModifiable($snapshot)) {
            $this->debug('skip', array('invoice_id' => $snapshot['id'], 'reason' => $reason), array('message' => $this->explainApplicability($snapshot)));

            return array('action' => 'skipped', 'message' => $this->explainApplicability($snapshot));
        }

        $applicable = $this->isInvoiceApplicable($snapshot);

        if (!$applicable && !$activeFee) {
            return array('action' => 'skipped', 'message' => $this->explainApplicability($snapshot));
        }

        if (!$applicable && $activeFee) {
            return $this->removeFee($activeFee, $snapshot, $reason . ':published-gateway-remove', $this->creditAmountWithoutFeeOverage($snapshot, $activeFee));
        }

        if (!$activeFee && self::amountToCents($feeAmount) > 0) {
            return $this->addFee($snapshot, $baseAmount, $feeAmount, $reason . ':published-gateway-add');
        }

        if ($activeFee && $this->activeFeeNeedsInvoiceRefresh($activeFee, $baseAmount, $feeAmount)) {
            $removeResult = $this->removeFee($activeFee, $snapshot, $reason . ':published-gateway-refresh-remove', $this->creditAmountWithoutFeeOverage($snapshot, $activeFee));
            if ($removeResult['action'] !== 'removed') {
                return $removeResult;
            }

            $snapshot = $this->getInvoiceSnapshot((int) $snapshot['id']);
            $baseAmount = $this->calculateBaseAmount($snapshot, null);
            $feeAmount = self::calculateFeeAmount($baseAmount, $this->config['fee_percent']);

            if (self::amountToCents($feeAmount) <= 0) {
                return array(
                    'action' => 'removed',
                    'invoice_id' => (int) $snapshot['id'],
                    'base_amount' => $baseAmount,
                    'fee_amount' => $feeAmount,
                    'message' => 'Calculated published-stage fee is zero after applied credit.',
                );
            }

            return $this->addFee($snapshot, $baseAmount, $feeAmount, $reason . ':published-gateway-refresh-add');
        }

        if ($activeFee && $this->activeFeeGatewayChanged($activeFee, $snapshot)) {
            return $this->updateActiveFeeGateway($activeFee, $snapshot, $baseAmount, $feeAmount, $reason . ':published-gateway-update');
        }

        return array(
            'action' => 'noop',
            'invoice_id' => (int) $snapshot['id'],
            'base_amount' => $baseAmount,
            'fee_amount' => $feeAmount,
        );
    }

    private function emergencyKilled($invoiceId, $reason)
    {
        $result = array(
            'action' => 'emergency-killed',
            'invoice_id' => (int) $invoiceId,
            'message' => 'Emergency kill switch is on; no gateway fee sync was attempted.',
        );

        $this->log('emergency-kill-switch', array('invoice_id' => (int) $invoiceId, 'reason' => $reason), $result, true);

        return $result;
    }

    private function guardInvoiceWrite(array $snapshot, $reason, $operation, array $details = array())
    {
        if ($this->config['emergency_kill_switch']) {
            $result = array(
                'action' => 'emergency-killed',
                'invoice_id' => (int) $snapshot['id'],
                'message' => 'Emergency kill switch is on; invoice write was blocked.',
            );
            $this->log('emergency-kill-switch', $this->safeWriteLogContext($snapshot, $reason, $operation), $result, true);

            return $result;
        }

        $status = $this->writeStatus($snapshot, $reason, $operation);
        if ($status['allowed']) {
            return null;
        }

        $result = array_merge(array(
            'action' => $status['action'],
            'invoice_id' => (int) $snapshot['id'],
            'client_id' => (int) $snapshot['userid'],
            'message' => $status['message'],
        ), $details);

        $this->log('gateway-fee-write-blocked', $this->safeWriteLogContext($snapshot, $reason, $operation), $result, true);

        return $result;
    }

    private function writeStatus(array $snapshot, $reason, $operation)
    {
        if (!$this->config['enabled']) {
            return array(
                'allowed' => false,
                'action' => 'module-disabled',
                'message' => 'Module is disabled; invoice writes are blocked.',
            );
        }

        if ($this->config['dry_run_only']) {
            return array(
                'allowed' => false,
                'action' => 'dry-run-only',
                'message' => 'Dry-run-only is on; invoice write was logged but not executed.',
            );
        }

        if ($this->config['mode'] === 'production') {
            return array(
                'allowed' => true,
                'action' => 'production-write-allowed',
                'message' => 'Production mode write allowed.',
            );
        }

        if ($this->config['mode'] !== 'canary') {
            return array(
                'allowed' => false,
                'action' => 'invalid-mode',
                'message' => 'Invalid mode; invoice writes are blocked.',
            );
        }

        $invoiceId = (int) $snapshot['id'];
        $clientId = (int) $snapshot['userid'];
        $invoiceAllowed = in_array($invoiceId, $this->config['production_canary_invoice_ids'], true);
        $clientAllowed = in_array($clientId, $this->config['production_canary_client_ids'], true);

        if (!$this->config['production_canary_invoice_ids'] || !$this->config['production_canary_client_ids']) {
            return array(
                'allowed' => false,
                'action' => 'canary-allowlist-required',
                'message' => 'Canary mode requires both invoice_id and client_id allowlists before any write.',
            );
        }

        if (!$invoiceAllowed || !$clientAllowed) {
            return array(
                'allowed' => false,
                'action' => 'canary-not-allowlisted',
                'message' => 'Invoice write blocked because the invoice_id/client_id pair is not allowlisted for production canary.',
            );
        }

        return array(
            'allowed' => true,
            'action' => 'canary-write-allowed',
            'message' => 'Production canary write allowed for the configured invoice_id/client_id pair.',
        );
    }

    private function safeWriteLogContext(array $snapshot, $reason, $operation)
    {
        return array(
            'invoice_id' => (int) $snapshot['id'],
            'client_id' => (int) $snapshot['userid'],
            'reason' => $reason,
            'operation' => $operation,
            'gateway' => $snapshot['paymentmethod'],
        );
    }

    private function shouldRemoveFeeForAppliedCredit(array $snapshot, $activeFee, $baseAmount)
    {
        if (!$activeFee) {
            return false;
        }

        if (!in_array(strtolower((string) $snapshot['status']), array('unpaid', 'paid'), true)) {
            return false;
        }

        if (self::amountToCents($snapshot['applied_credit_amount']) <= 0) {
            return false;
        }

        if (self::amountToCents($snapshot['external_paid_amount']) !== 0) {
            return false;
        }

        return self::amountToCents($baseAmount) <= 0;
    }

    private function capAppliedCreditSources(array $snapshot, $targetAppliedCreditAmount, $reason)
    {
        $currentAppliedCreditCents = self::amountToCents($snapshot['applied_credit_amount']);
        $targetAppliedCreditCents = max(0, self::amountToCents($targetAppliedCreditAmount));

        if ($targetAppliedCreditCents >= $currentAppliedCreditCents) {
            return null;
        }

        $transactionCreditCents = 0;
        foreach ($snapshot['applied_credit_transactions'] as $transaction) {
            $transactionCreditCents += self::amountToCents($transaction['amount']);
        }

        $invoiceCreditCents = self::amountToCents($snapshot['invoice_credit_amount']);
        $targetInvoiceCreditCents = min($invoiceCreditCents, $targetAppliedCreditCents);
        $reducedInvoiceCreditCents = 0;

        if ($targetInvoiceCreditCents < $invoiceCreditCents) {
            $apiResult = $this->callApi('UpdateInvoice', array(
                'invoiceid' => (int) $snapshot['id'],
                'credit' => self::formatCents($targetInvoiceCreditCents),
            ));
            $this->assertApiSuccess('UpdateInvoice:applied-credit-cap', $apiResult);
            $reducedInvoiceCreditCents = $invoiceCreditCents - $targetInvoiceCreditCents;
        }

        $targetTransactionCreditCents = max(0, $targetAppliedCreditCents - $targetInvoiceCreditCents);
        $remainingReductionCents = max(0, $transactionCreditCents - $targetTransactionCreditCents);
        $reducedTransactionCents = 0;
        $updatedTransactions = array();

        $transactions = array_reverse($snapshot['applied_credit_transactions']);
        foreach ($transactions as $transaction) {
            if ($remainingReductionCents <= 0) {
                break;
            }

            $transactionCents = self::amountToCents($transaction['amount']);
            if ($transactionCents <= 0) {
                continue;
            }

            $reduceCents = min($transactionCents, $remainingReductionCents);
            $newAmount = self::formatCents($transactionCents - $reduceCents);
            $apiResult = $this->callApi('UpdateTransaction', array(
                'transactionid' => (int) $transaction['id'],
                'amountin' => $newAmount,
            ));
            $this->assertApiSuccess('UpdateTransaction:applied-credit-cap', $apiResult);

            $remainingReductionCents -= $reduceCents;
            $reducedTransactionCents += $reduceCents;
            $updatedTransactions[] = (int) $transaction['id'];
        }

        if ($reducedTransactionCents > 0) {
            $refundAmount = self::formatCents($reducedTransactionCents);
            $apiResult = $this->callApi('AddCredit', array(
                'clientid' => (int) $snapshot['userid'],
                'amount' => $refundAmount,
                'description' => 'Refund gateway fee credit overage for invoice #' . (int) $snapshot['id'],
            ));
            $this->assertApiSuccess('AddCredit:applied-credit-overage', $apiResult);
        }

        $adjustment = array(
            'target_applied_credit_amount' => self::formatCents($targetAppliedCreditCents),
            'reduced_invoice_credit_amount' => self::formatCents($reducedInvoiceCreditCents),
            'reduced_transaction_amount' => self::formatCents($reducedTransactionCents),
            'updated_transaction_ids' => $updatedTransactions,
        );
        $this->log('applied-credit-capped', array('invoice_id' => (int) $snapshot['id'], 'reason' => $reason), $adjustment, true);

        return $adjustment;
    }

    private function creditAmountWithoutFeeOverage(array $snapshot, $activeFee)
    {
        if (!$activeFee) {
            return null;
        }

        $creditAmount = self::capAppliedCreditToNonFeeAmount(
            $snapshot['items'],
            (int) $activeFee->invoice_item_id,
            $snapshot['applied_credit_amount'],
            $snapshot['external_paid_amount']
        );

        if (self::amountToCents($creditAmount) < self::amountToCents($snapshot['applied_credit_amount'])) {
            return $creditAmount;
        }

        return null;
    }

    private function activeFeeNeedsRefresh($activeFee, array $snapshot, $baseAmount, $feeAmount)
    {
        if ($this->activeFeeGatewayChanged($activeFee, $snapshot)) {
            return true;
        }
        if ($this->activeFeeNeedsInvoiceRefresh($activeFee, $baseAmount, $feeAmount)) {
            return true;
        }

        return false;
    }

    private function activeFeeGatewayChanged($activeFee, array $snapshot)
    {
        return (string) $activeFee->gateway !== (string) $snapshot['paymentmethod'];
    }

    private function activeFeeNeedsInvoiceRefresh($activeFee, $baseAmount, $feeAmount)
    {
        if (self::amountToCents($activeFee->base_amount) !== self::amountToCents($baseAmount)) {
            return true;
        }
        if (self::amountToCents($activeFee->fee_amount) !== self::amountToCents($feeAmount)) {
            return true;
        }
        if (self::normalizePercent($activeFee->fee_percent) !== $this->config['fee_percent']) {
            return true;
        }
        if (!$this->invoiceItemExists((int) $activeFee->invoice_item_id)) {
            return true;
        }

        return false;
    }

    private function updateActiveFeeGateway($activeFee, array $snapshot, $baseAmount, $feeAmount, $reason)
    {
        $writeGuard = $this->guardInvoiceWrite($snapshot, $reason, 'metadata-update', array(
            'invoice_item_id' => (int) $activeFee->invoice_item_id,
        ));
        if ($writeGuard !== null) {
            return $writeGuard;
        }

        Capsule::table(self::TABLE)
            ->where('id', (int) $activeFee->id)
            ->update(array(
                'gateway' => $snapshot['paymentmethod'],
                'base_amount' => $baseAmount,
                'fee_amount' => $feeAmount,
                'fee_percent' => $this->config['fee_percent'],
                'updated_at' => $this->now(),
            ));

        $this->log('fee-gateway-updated', array('invoice_id' => (int) $snapshot['id'], 'reason' => $reason), array('gateway' => $snapshot['paymentmethod'], 'invoice_item_id' => (int) $activeFee->invoice_item_id), true);

        return array(
            'action' => 'gateway-updated',
            'invoice_id' => (int) $snapshot['id'],
            'invoice_item_id' => (int) $activeFee->invoice_item_id,
            'base_amount' => $baseAmount,
            'fee_amount' => $feeAmount,
        );
    }

    private function isInvoiceApplicable(array $snapshot)
    {
        if (!$this->config['enabled']) {
            return false;
        }

        if (strcasecmp((string) $snapshot['status'], 'Unpaid') !== 0) {
            return false;
        }

        return in_array(strtolower((string) $snapshot['paymentmethod']), $this->config['gateways'], true);
    }

    private function isInvoiceCreationApplicable(array $snapshot)
    {
        if (!$this->config['enabled']) {
            return false;
        }

        if (!in_array(strtolower((string) $snapshot['status']), array('', 'draft', 'unpaid'), true)) {
            return false;
        }

        return in_array(strtolower((string) $snapshot['paymentmethod']), $this->config['gateways'], true);
    }

    private function isInvoiceModifiable(array $snapshot)
    {
        return strcasecmp((string) $snapshot['status'], 'Unpaid') === 0;
    }

    private function explainApplicability(array $snapshot)
    {
        if (!$this->config['enabled']) {
            return 'Module config is disabled.';
        }

        if (strcasecmp((string) $snapshot['status'], 'Unpaid') !== 0) {
            return 'Invoice status is not Unpaid.';
        }

        if (!in_array(strtolower((string) $snapshot['paymentmethod']), $this->config['gateways'], true)) {
            return 'Invoice gateway is not configured for gateway fee.';
        }

        return 'Invoice is applicable.';
    }

    private function explainCreationApplicability(array $snapshot)
    {
        if (!$this->config['enabled']) {
            return 'Module config is disabled.';
        }

        if (!in_array(strtolower((string) $snapshot['status']), array('', 'draft', 'unpaid'), true)) {
            return 'Invoice creation status is not Draft or Unpaid.';
        }

        if (!in_array(strtolower((string) $snapshot['paymentmethod']), $this->config['gateways'], true)) {
            return 'Invoice gateway is not configured for gateway fee.';
        }

        return 'Invoice creation is applicable.';
    }

    private function getInvoiceSnapshot($invoiceId)
    {
        $this->assertCapsule();

        $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
        if (!$invoice) {
            throw new RuntimeException('Invoice not found: ' . (int) $invoiceId);
        }

        $client = Capsule::table('tblclients')->where('id', (int) $invoice->userid)->first();
        $items = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', (int) $invoiceId)
            ->orderBy('id', 'asc')
            ->get();

        $transactions = Capsule::table('tblaccounts')
            ->where('invoiceid', (int) $invoiceId)
            ->orderBy('id', 'asc')
            ->get(array('id', 'gateway', 'transid', 'amountin', 'amountout'));
        $transactionAmounts = $this->classifyTransactionAmounts($transactions);
        $invoiceCreditAmount = self::formatCents(self::amountToCents(isset($invoice->credit) ? $invoice->credit : '0.00'));
        $appliedCreditAmount = self::addAmounts($invoiceCreditAmount, $transactionAmounts['applied_credit_amount']);
        $externalPaidAmount = $transactionAmounts['external_paid_amount'];
        $amountPaid = self::addAmounts($appliedCreditAmount, $externalPaidAmount);
        $balance = self::subtractAmounts($invoice->total, $amountPaid);
        if (self::amountToCents($balance) < 0) {
            $balance = '0.00';
        }

        return array(
            'id' => (int) $invoice->id,
            'userid' => (int) $invoice->userid,
            'status' => (string) $invoice->status,
            'paymentmethod' => strtolower((string) $invoice->paymentmethod),
            'total' => self::formatCents(self::amountToCents($invoice->total)),
            'invoice_credit_amount' => $invoiceCreditAmount,
            'applied_credit_amount' => $appliedCreditAmount,
            'external_paid_amount' => $externalPaidAmount,
            'credit' => $appliedCreditAmount,
            'amount_paid' => $amountPaid,
            'balance' => $balance,
            'applied_credit_transactions' => $transactionAmounts['applied_credit_transactions'],
            'currency_id' => $client && isset($client->currency) ? (int) $client->currency : 0,
            'client_language' => $client && isset($client->language) ? strtolower((string) $client->language) : '',
            'items' => $items,
        );
    }

    private function getActiveFee($invoiceId)
    {
        $this->assertCapsule();

        return Capsule::table(self::TABLE)
            ->where('invoice_id', (int) $invoiceId)
            ->where('status', 'active')
            ->orderBy('id', 'desc')
            ->first();
    }

    private function findAutomationInvoiceIds()
    {
        $this->assertCapsule();

        if (!$this->config['enabled'] || $this->config['mode'] !== 'production') {
            return array();
        }

        $rows = Capsule::table('tblinvoices')
            ->where('status', 'Unpaid')
            ->whereIn('paymentmethod', $this->config['gateways'])
            ->orderBy('id', 'asc')
            ->limit(self::AUTOMATION_LIMIT)
            ->get(array('id'));

        return $this->pluckIds($rows);
    }

    private function findStaleActiveFeeInvoiceIds()
    {
        $this->assertCapsule();

        if (!$this->config['enabled'] || $this->config['mode'] !== 'production') {
            return array();
        }

        $rows = Capsule::table(self::TABLE . ' as fee')
            ->leftJoin('tblinvoices as inv', 'inv.id', '=', 'fee.invoice_id')
            ->where('fee.status', 'active')
            ->where('inv.status', 'Unpaid')
            ->whereNotIn('inv.paymentmethod', $this->config['gateways'])
            ->orderBy('fee.invoice_id', 'asc')
            ->limit(self::AUTOMATION_LIMIT)
            ->get(array('fee.invoice_id'));

        return $this->pluckIds($rows, 'invoice_id');
    }

    private function findAutomationCandidateInvoiceIds()
    {
        $ids = array_merge($this->findAutomationInvoiceIds(), $this->findStaleActiveFeeInvoiceIds());
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return array_slice($ids, 0, self::AUTOMATION_LIMIT);
    }

    private function pluckIds($rows, $field = 'id')
    {
        $ids = array();
        foreach ($rows as $row) {
            if (isset($row->{$field})) {
                $ids[] = (int) $row->{$field};
            }
        }

        return array_values(array_unique($ids));
    }

    private function classifyTransactionAmounts($transactions)
    {
        $appliedCreditCents = 0;
        $externalPaidCents = 0;
        $appliedCreditTransactions = array();

        foreach ($transactions as $transaction) {
            $netCents = self::amountToCents(self::readValue($transaction, 'amountin', '0.00'));
            $netCents -= self::amountToCents(self::readValue($transaction, 'amountout', '0.00'));

            if ($netCents <= 0) {
                continue;
            }

            if ($this->isAppliedCreditTransaction($transaction)) {
                $appliedCreditCents += $netCents;
                $appliedCreditTransactions[] = array(
                    'id' => (int) self::readValue($transaction, 'id', 0),
                    'amount' => self::formatCents($netCents),
                );
            } else {
                $externalPaidCents += $netCents;
            }
        }

        return array(
            'applied_credit_amount' => self::formatCents($appliedCreditCents),
            'external_paid_amount' => self::formatCents($externalPaidCents),
            'applied_credit_transactions' => $appliedCreditTransactions,
        );
    }

    private function isAppliedCreditTransaction($transaction)
    {
        $gateway = trim((string) self::readValue($transaction, 'gateway', ''));
        $transId = trim((string) self::readValue($transaction, 'transid', ''));

        return $gateway === '' && $transId === '';
    }

    private function findInvoiceItemAmount($items, $invoiceItemId)
    {
        foreach ($items as $item) {
            if ((int) $item->id === (int) $invoiceItemId) {
                return $item->amount;
            }
        }

        return null;
    }

    private function invoiceItemExists($invoiceItemId)
    {
        if ($invoiceItemId <= 0) {
            return false;
        }

        return Capsule::table('tblinvoiceitems')->where('id', (int) $invoiceItemId)->exists();
    }

    private function findLatestFeeInvoiceItemId($invoiceId, $description, $amount)
    {
        $item = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', (int) $invoiceId)
            ->where('description', $description)
            ->where('amount', self::formatCents(self::amountToCents($amount)))
            ->orderBy('id', 'desc')
            ->first();

        if (!$item) {
            $item = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', (int) $invoiceId)
                ->where('description', $description)
                ->orderBy('id', 'desc')
                ->first();
        }

        return $item ? (int) $item->id : 0;
    }

    private function getFeeDescription(array $snapshot)
    {
        $language = strtolower((string) $snapshot['client_language']);
        $key = (strpos($language, 'chinese') !== false || strpos($language, 'zh') !== false) ? 'fee_description_zh' : 'fee_description_en';

        return str_replace('{percent}', self::formatPercentForDisplay($this->config['fee_percent']), $this->config[$key]);
    }

    private function callApi($command, array $params)
    {
        if ($this->apiRunner) {
            return call_user_func($this->apiRunner, $command, $params);
        }

        if (!function_exists('localAPI')) {
            throw new RuntimeException('WHMCS localAPI function is not available.');
        }

        return localAPI($command, $params);
    }

    private function assertApiSuccess($action, $result)
    {
        if (is_array($result) && isset($result['result']) && strtolower((string) $result['result']) === 'success') {
            return;
        }

        $message = is_array($result) && isset($result['message']) ? $result['message'] : json_encode($result);
        throw new RuntimeException($action . ' failed: ' . $message);
    }

    private function recalculateInvoice($invoiceId)
    {
        if (function_exists('updateInvoiceTotal')) {
            updateInvoiceTotal((int) $invoiceId);

            return;
        }

        $invoiceClass = '\\WHMCS\\Billing\\Invoice';
        if (class_exists($invoiceClass)) {
            $invoice = $invoiceClass::find((int) $invoiceId);
            if ($invoice && method_exists($invoice, 'updateInvoiceTotal')) {
                $invoice->updateInvoiceTotal();
            }
        }
    }

    private function withInvoiceLock($invoiceId, $callback)
    {
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return call_user_func($callback);
        }

        $lockName = self::MODULE . '_invoice_' . (int) $invoiceId;
        $lockRow = Capsule::connection()->selectOne('SELECT GET_LOCK(?, ?) AS got_lock', array($lockName, self::LOCK_TIMEOUT));
        if (!$lockRow || (string) $lockRow->got_lock !== '1') {
            throw new RuntimeException('Could not acquire invoice lock for invoice ' . (int) $invoiceId);
        }

        try {
            return call_user_func($callback);
        } finally {
            Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS released_lock', array($lockName));
        }
    }

    private function loadConfigFromDatabase()
    {
        $config = self::defaults();

        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return self::normalizeConfig($config);
        }

        try {
            $rows = Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->get(array('setting', 'value'));

            foreach ($rows as $row) {
                if (array_key_exists($row->setting, $config)) {
                    $config[$row->setting] = $row->value;
                }
            }
        } catch (Exception $e) {
            $this->log('config-load-error', array(), array('error' => $e->getMessage()), true);
        }

        return self::normalizeConfig($config);
    }

    private function assertCapsule()
    {
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            throw new RuntimeException('WHMCS Capsule is not available.');
        }
    }

    private function debug($action, array $request, array $response)
    {
        if ($this->config['debug_log']) {
            $this->log($action, $request, $response, false);
        }
    }

    private function log($action, array $request, array $response, $force)
    {
        if ($this->logger) {
            call_user_func($this->logger, $action, $request, $response);

            return;
        }

        if (!$force && !$this->config['debug_log']) {
            return;
        }

        if (function_exists('logModuleCall')) {
            logModuleCall(self::MODULE, $action, $request, $response, array(), array());
        }
    }

    private function now()
    {
        return date('Y-m-d H:i:s');
    }

    private static function readValue($source, $key, $default = null)
    {
        if (is_array($source) && array_key_exists($key, $source)) {
            return $source[$key];
        }

        if (is_object($source) && isset($source->{$key})) {
            return $source->{$key};
        }

        return $default;
    }

    private static function toBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, array('1', 'true', 'yes', 'on', 'enabled'), true);
    }

    private static function normalizeIdList($value)
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[\s,;]+/', (string) $value);
        }

        $ids = array();
        foreach ($parts as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private static function normalizeMode($mode)
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, array('canary', 'production'), true) ? $mode : 'canary';
    }

    private static function normalizePercent($percent)
    {
        $units = self::percentToUnits($percent);
        $whole = intdiv($units, 10000);
        $fraction = $units % 10000;

        return $whole . '.' . str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);
    }

    private static function formatPercentForDisplay($percent)
    {
        $normalized = self::normalizePercent($percent);
        $normalized = rtrim(rtrim($normalized, '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }

    private static function percentToUnits($percent)
    {
        $value = trim(str_replace(',', '', (string) $percent));
        if ($value === '' || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return 0;
        }

        $parts = explode('.', $value, 2);
        $whole = (int) $parts[0];
        $fraction = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : '';
        $fraction = str_pad($fraction, 5, '0');
        $units = ($whole * 10000) + (int) substr($fraction, 0, 4);
        if ((int) $fraction[4] >= 5) {
            $units++;
        }

        return $units;
    }

    private static function roundFraction($numerator, $denominator)
    {
        if ($denominator <= 0) {
            return 0;
        }

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
