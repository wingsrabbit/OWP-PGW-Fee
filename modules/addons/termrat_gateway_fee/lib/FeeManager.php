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

    public function __construct(array $config = null, $apiRunner = null, $logger = null)
    {
        $this->config = $config ? self::normalizeConfig($config) : $this->loadConfigFromDatabase();
        $this->apiRunner = $apiRunner;
        $this->logger = $logger;
    }

    public static function defaults()
    {
        return array(
            'enabled' => true,
            'fee_percent' => '3.00',
            'gateways' => array('stripe', 'stripealipay'),
            'fee_description_en' => 'Payment gateway processing fee ({percent}%)',
            'fee_description_zh' => '支付网关手续费（{percent}%）',
            'taxable' => false,
            'debug_log' => false,
        );
    }

    public static function normalizeConfig(array $input)
    {
        $defaults = self::defaults();
        $config = array_merge($defaults, $input);

        $config['enabled'] = self::toBool($config['enabled']);
        $config['taxable'] = self::toBool($config['taxable']);
        $config['debug_log'] = self::toBool($config['debug_log']);
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
            'fee_percent' => $this->config['fee_percent'],
            'gateways' => implode(',', $this->config['gateways']),
            'fee_description_en' => $this->config['fee_description_en'],
            'fee_description_zh' => $this->config['fee_description_zh'],
            'taxable' => $this->config['taxable'] ? 'on' : 'off',
            'debug_log' => $this->config['debug_log'] ? 'on' : 'off',
        );
    }

    public function syncInvoice($invoiceId, $reason = 'manual')
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return array('action' => 'skipped', 'message' => 'Invalid invoice id.');
        }

        return $this->withInvoiceLock($invoiceId, function () use ($invoiceId, $reason) {
            return $this->syncInvoiceLocked($invoiceId, $reason);
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
        if (!$this->isInvoiceModifiable($snapshot)) {
            $action = 'skipped';
        } elseif (!$applicable && $activeFee) {
            $action = 'would-remove';
        } elseif ($applicable && !$activeFee && self::amountToCents($feeAmount) > 0) {
            $action = 'would-add';
        } elseif ($applicable && $activeFee && $this->activeFeeNeedsRefresh($activeFee, $snapshot, $baseAmount, $feeAmount)) {
            $action = 'would-refresh';
        }

        return array(
            'action' => $action,
            'invoice_id' => $invoiceId,
            'status' => $snapshot['status'],
            'gateway' => $snapshot['paymentmethod'],
            'base_amount' => $baseAmount,
            'fee_percent' => $this->config['fee_percent'],
            'fee_amount' => $feeAmount,
            'message' => $this->explainApplicability($snapshot),
        );
    }

    public function syncAutomationInvoices($reason = 'automation')
    {
        $stats = array('synced' => 0, 'removed' => 0, 'errors' => 0);

        foreach ($this->findAutomationInvoiceIds() as $invoiceId) {
            try {
                $result = $this->syncInvoice($invoiceId, $reason);
                if (in_array($result['action'], array('added', 'refreshed', 'noop'), true)) {
                    $stats['synced']++;
                } elseif ($result['action'] === 'removed') {
                    $stats['removed']++;
                }
            } catch (Exception $e) {
                $stats['errors']++;
                $this->log('automation-error', array('invoice_id' => $invoiceId, 'reason' => $reason), array('error' => $e->getMessage()), true);
            }
        }

        foreach ($this->findStaleActiveFeeInvoiceIds() as $invoiceId) {
            try {
                $result = $this->syncInvoice($invoiceId, $reason . ':cleanup');
                if ($result['action'] === 'removed') {
                    $stats['removed']++;
                }
            } catch (Exception $e) {
                $stats['errors']++;
                $this->log('automation-cleanup-error', array('invoice_id' => $invoiceId, 'reason' => $reason), array('error' => $e->getMessage()), true);
            }
        }

        $this->debug('automation-summary', array('reason' => $reason), $stats);

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
        $applicable = $this->isInvoiceApplicable($snapshot);

        if (!$this->isInvoiceModifiable($snapshot)) {
            $this->debug('skip', array('invoice_id' => $invoiceId, 'reason' => $reason), array('message' => $this->explainApplicability($snapshot)));

            return array('action' => 'skipped', 'message' => $this->explainApplicability($snapshot));
        }

        if (!$applicable) {
            if ($activeFee) {
                return $this->removeFee($activeFee, $snapshot, $reason);
            }

            $this->debug('skip', array('invoice_id' => $invoiceId, 'reason' => $reason), array('message' => $this->explainApplicability($snapshot)));

            return array('action' => 'skipped', 'message' => $this->explainApplicability($snapshot));
        }

        $baseAmount = $this->calculateBaseAmount($snapshot, $activeFee);
        $feeAmount = self::calculateFeeAmount($baseAmount, $this->config['fee_percent']);

        if (self::amountToCents($feeAmount) <= 0) {
            if ($activeFee) {
                return $this->removeFee($activeFee, $snapshot, $reason . ':zero-fee');
            }

            return array('action' => 'skipped', 'message' => 'Calculated fee is zero.');
        }

        if ($activeFee && !$this->activeFeeNeedsRefresh($activeFee, $snapshot, $baseAmount, $feeAmount)) {
            $this->debug('noop', array('invoice_id' => $invoiceId, 'reason' => $reason), array('base_amount' => $baseAmount, 'fee_amount' => $feeAmount));

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
            $baseAmount = $this->calculateBaseAmount($snapshot, null);
            $feeAmount = self::calculateFeeAmount($baseAmount, $this->config['fee_percent']);
        }

        return $this->addFee($snapshot, $baseAmount, $feeAmount, $reason);
    }

    private function addFee(array $snapshot, $baseAmount, $feeAmount, $reason)
    {
        $invoiceId = (int) $snapshot['id'];
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

    private function removeFee($activeFee, array $snapshot, $reason)
    {
        $invoiceId = (int) $activeFee->invoice_id;
        $invoiceItemId = (int) $activeFee->invoice_item_id;

        if ($invoiceItemId > 0 && $this->invoiceItemExists($invoiceItemId)) {
            $request = array(
                'invoiceid' => $invoiceId,
                'deletelineids' => array($invoiceItemId),
            );
            $apiResult = $this->callApi('UpdateInvoice', $request);
            $this->assertApiSuccess('UpdateInvoice:remove', $apiResult);
        }

        Capsule::table(self::TABLE)
            ->where('id', (int) $activeFee->id)
            ->update(array(
                'status' => 'removed',
                'active_invoice_id' => null,
                'updated_at' => $this->now(),
            ));

        $this->recalculateInvoice($invoiceId);
        $this->log('fee-removed', array('invoice_id' => $invoiceId, 'reason' => $reason), array('gateway' => $snapshot['paymentmethod'], 'invoice_item_id' => $invoiceItemId), true);

        return array('action' => 'removed', 'invoice_id' => $invoiceId, 'invoice_item_id' => $invoiceItemId);
    }

    private function calculateBaseAmount(array $snapshot, $activeFee)
    {
        $baseCents = self::amountToCents($snapshot['balance']);

        if ($activeFee) {
            $feeItemAmount = $this->findInvoiceItemAmount($snapshot['items'], (int) $activeFee->invoice_item_id);
            if ($feeItemAmount === null) {
                $feeItemAmount = $activeFee->fee_amount;
            }
            $baseCents -= self::amountToCents($feeItemAmount);
        }

        return self::formatCents(max(0, $baseCents));
    }

    private function activeFeeNeedsRefresh($activeFee, array $snapshot, $baseAmount, $feeAmount)
    {
        if ((string) $activeFee->gateway !== (string) $snapshot['paymentmethod']) {
            return true;
        }
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

        $transactionTotals = Capsule::connection()->selectOne(
            'SELECT COALESCE(SUM(amountin), 0) AS amount_in, COALESCE(SUM(amountout), 0) AS amount_out FROM tblaccounts WHERE invoiceid = ?',
            array((int) $invoiceId)
        );
        $amountPaid = self::subtractAmounts($transactionTotals->amount_in, $transactionTotals->amount_out);
        $balance = self::subtractAmounts(self::subtractAmounts($invoice->total, isset($invoice->credit) ? $invoice->credit : '0.00'), $amountPaid);
        if (self::amountToCents($balance) < 0) {
            $balance = '0.00';
        }

        return array(
            'id' => (int) $invoice->id,
            'userid' => (int) $invoice->userid,
            'status' => (string) $invoice->status,
            'paymentmethod' => strtolower((string) $invoice->paymentmethod),
            'total' => self::formatCents(self::amountToCents($invoice->total)),
            'credit' => self::formatCents(self::amountToCents(isset($invoice->credit) ? $invoice->credit : '0.00')),
            'amount_paid' => $amountPaid,
            'balance' => $balance,
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
        if (!$this->config['enabled']) {
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

        $query = Capsule::table(self::TABLE . ' as fee')
            ->leftJoin('tblinvoices as inv', 'inv.id', '=', 'fee.invoice_id')
            ->where('fee.status', 'active')
            ->where('inv.status', 'Unpaid')
            ->limit(self::AUTOMATION_LIMIT);

        if ($this->config['enabled']) {
            $query->where(function ($inner) {
                $inner->whereNotIn('inv.paymentmethod', $this->config['gateways']);
            });
        }

        $rows = $query->get(array('fee.invoice_id'));

        return $this->pluckIds($rows, 'invoice_id');
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

    private static function toBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, array('1', 'true', 'yes', 'on', 'enabled'), true);
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
