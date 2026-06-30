<?php

if (!defined('WHMCS') && !defined('TERMRAT_GATEWAY_FEE_TEST')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class TermRatGatewayFeeInstaller
{
    const TABLE = 'mod_termrat_gateway_fee_items';

    public static function activate()
    {
        try {
            self::createOrUpdateSchema();

            return array(
                'status' => 'success',
                'description' => 'TermRat Gateway Fee activated. Audit table is ready.',
            );
        } catch (Exception $e) {
            return array(
                'status' => 'error',
                'description' => 'Activation failed: ' . $e->getMessage(),
            );
        }
    }

    public static function deactivate()
    {
        return array(
            'status' => 'success',
            'description' => 'TermRat Gateway Fee deactivated. Audit records were retained.',
        );
    }

    public static function uninstall()
    {
        try {
            if (class_exists('WHMCS\\Database\\Capsule') && Capsule::schema()->hasTable(self::TABLE)) {
                Capsule::schema()->drop(self::TABLE);
            }

            return array(
                'status' => 'success',
                'description' => 'TermRat Gateway Fee uninstalled. Audit table was dropped.',
            );
        } catch (Exception $e) {
            return array(
                'status' => 'error',
                'description' => 'Uninstall failed: ' . $e->getMessage(),
            );
        }
    }

    private static function createOrUpdateSchema()
    {
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            throw new RuntimeException('WHMCS Capsule is not available.');
        }

        if (!Capsule::schema()->hasTable(self::TABLE)) {
            Capsule::schema()->create(self::TABLE, function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->unsigned();
                $table->integer('invoice_item_id')->unsigned()->nullable();
                $table->integer('active_invoice_id')->unsigned()->nullable();
                $table->string('gateway', 64);
                $table->decimal('fee_percent', 10, 4);
                $table->decimal('base_amount', 16, 2);
                $table->decimal('fee_amount', 16, 2);
                $table->integer('currency_id')->unsigned()->default(0);
                $table->string('status', 16)->default('active');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');

                $table->unique('active_invoice_id', 'uniq_tgf_active_invoice');
                $table->index('invoice_id', 'idx_tgf_invoice');
                $table->index('invoice_item_id', 'idx_tgf_invoice_item');
                $table->index('status', 'idx_tgf_status');
                $table->index('created_at', 'idx_tgf_created_at');
            });

            return;
        }

        self::ensureColumn('active_invoice_id', function ($table) {
            $table->integer('active_invoice_id')->unsigned()->nullable()->after('invoice_item_id');
        });
        self::ensureColumn('currency_id', function ($table) {
            $table->integer('currency_id')->unsigned()->default(0)->after('fee_amount');
        });

        self::tryStatement('ALTER TABLE `' . self::TABLE . '` ADD UNIQUE KEY `uniq_tgf_active_invoice` (`active_invoice_id`)');
        self::tryStatement('ALTER TABLE `' . self::TABLE . '` ADD KEY `idx_tgf_invoice` (`invoice_id`)');
        self::tryStatement('ALTER TABLE `' . self::TABLE . '` ADD KEY `idx_tgf_invoice_item` (`invoice_item_id`)');
        self::tryStatement('ALTER TABLE `' . self::TABLE . '` ADD KEY `idx_tgf_status` (`status`)');
        self::tryStatement('ALTER TABLE `' . self::TABLE . '` ADD KEY `idx_tgf_created_at` (`created_at`)');
    }

    private static function ensureColumn($column, $callback)
    {
        if (Capsule::schema()->hasColumn(self::TABLE, $column)) {
            return;
        }

        Capsule::schema()->table(self::TABLE, $callback);
    }

    private static function tryStatement($sql)
    {
        try {
            Capsule::connection()->statement($sql);
        } catch (Exception $e) {
            // Existing indexes raise duplicate-name errors on repeated activation.
        }
    }
}
