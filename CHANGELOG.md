# 更新日志 / Changelog

本项目版本号按操作规模递增：大操作 +0.1、中型 +0.01、小型 +0.001。
格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/)。

## [Unreleased]
### Blocked
- The original requirement for fully automatic fee add/remove after customer gateway switching remains blocked under WHMCS 9.0.4 invoice immutability. This draft PR must not be treated as satisfying that requirement without staging proof of a supported mutation path or an approved automatic reissue / credit-debit-note design.

### 修复
- 修复 automation hook 去重粒度：`PreCronJob` 不再屏蔽后续 `PreAutomationTask`，后者按 task key 去重，未知 task 不去重。
- 修复 `InvoiceCreation` 阶段使用未最终化 invoice total/balance 的风险；创建阶段改为从当前 invoice line items 计算 base，等待 WHMCS hook 后重算 total。
- 明确 WHMCS 9.0 immutable invoice 边界：已发布 invoice 的 gateway 切换只检测和记录日志，不自动增删 line item。
- 明确 fee base 为实际应付口径：创建阶段 line items 小计会扣除已有 fee、invoice credit 和已入账金额。
- 修复 PHP 8.4 nullable 参数 deprecation：`TermRatGatewayFeeManager::__construct(?array $config = null, ...)`。

## [0.1.0] - 2026-06-30
### 新增
- 首版 `termrat_gateway_fee` WHMCS Addon Module。
- 支持 `stripe` / `stripealipay` 网关在 invoice 创建阶段自动添加 3% payment gateway processing fee。
- 支持创建阶段对 Stripe 类网关写入 fee line item，并保留自有审计表记录。
- 支持 `InvoiceCreation`、`InvoiceCreated`、`InvoiceChangeGateway`、发票查看入口、`PreCronJob`、`PreAutomationTask` 等 hook 入口。
- 后台页面显示配置、最近 50 条 fee 审计记录，并提供 dry-run / immutable-safe invoice check 工具。
- 增加 WHMCS-free 行为测试脚本和 PHP 场景测试脚本。
