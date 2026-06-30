# 更新日志 / Changelog

本项目版本号按操作规模递增：大操作 +0.1、中型 +0.01、小型 +0.001。
格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/)。

## [Unreleased]
### 新增
- 新增 `mode=canary|production` 运行模式：canary 继续要求 invoice/client allowlist，production 对所有符合条件的 `Unpaid` invoice 自动生效。
- 新增通用 `dry_run_only` 配置：在 canary 和 production mode 中都无条件阻断 invoice 写入，只记录/返回 would-do 结果。
- production mode 启用 cron/automation 安全候选扫描：只处理 `Unpaid`，只扫描配置网关 invoice 或本模块已有 active fee 的 invoice，每次最多 500 张。

### 修复
- 将已发布 `Unpaid` invoice gateway switch 路径升级为 production mode 自动处理：使用 WHMCS `UpdateInvoice` Local API add/remove 本模块 fee line item；`stripe` / `stripealipay` 之间切换只更新审计 gateway，不重复新增 fee。
- 保留 fail-closed 默认：`enabled=off`、`mode=canary`、`dry_run_only=on`，默认部署不写 invoice；`emergency_kill_switch=on` 无条件阻断所有 sync、automation 和写入。
- 修复 production canary P0 安全问题：默认 `enabled=off`，canary mode 不再放行未 allowlist 写入，canary automation 批量扫描保持阻断；只有显式启用、关闭 dry-run、kill switch 关闭且 invoice/client allowlist 精确匹配时才允许写入。
- 新增 production canary 安全保护：默认关闭、dry-run-only 默认开启、写入必须命中 invoice/client allowlist、canary 禁止 cron/automation 批量扫描，并提供 emergency kill switch。
- 修复 automation hook 去重粒度：`PreCronJob` 不再屏蔽后续 `PreAutomationTask`，后者按 task key 去重，未知 task 不去重。
- 修复 `InvoiceCreation` 阶段使用未最终化 invoice total/balance 的风险；创建阶段改为从当前 invoice line items 计算 base，等待 WHMCS hook 后重算 total。
- 明确 WHMCS 9.0 immutable invoice 风险边界：根据受控 production canary 验收，目标 WHMCS 9.0.4 环境中 `UpdateInvoice` 可用于 published `Unpaid` add/remove 本模块 fee line item。
- 明确 fee base 为实际应付口径：创建阶段 line items 小计会扣除已有 fee、invoice credit 和已入账金额。
- 修复 PHP 8.4 nullable 参数 deprecation：`TermRatGatewayFeeManager::__construct(?array $config = null, ...)`。

## [0.1.0] - 2026-06-30
### 新增
- 首版 `termrat_gateway_fee` WHMCS Addon Module。
- 支持 `stripe` / `stripealipay` 网关在 invoice 创建阶段自动添加 3% payment gateway processing fee。
- 支持创建阶段对 Stripe 类网关写入 fee line item，并保留自有审计表记录。
- 支持 `InvoiceCreation`、`InvoiceCreated`、`InvoiceChangeGateway`、发票查看入口、`PreCronJob`、`PreAutomationTask` 等 hook 入口。
- 后台页面显示配置、最近 50 条 fee 审计记录，并提供 dry-run / 当前模式 invoice check 工具。
- 增加 WHMCS-free 行为测试脚本和 PHP 场景测试脚本。
