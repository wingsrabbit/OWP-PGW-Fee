# TermRat Gateway Fee · WHMCS Addon Module

> 在 WHMCS invoice 创建阶段给指定 payment gateways 自动加 **payment gateway processing fee** 的 Addon Module。
> 它不是 payment gateway module，不修改 Stripe 网关，不修改 WHMCS core；fee 会作为 WHMCS invoice item 明示在发票中。

![version](https://img.shields.io/badge/version-v0.1.0-blue)
![whmcs](https://img.shields.io/badge/WHMCS-9.0.4-2ea44f)
![php](https://img.shields.io/badge/PHP-8.3-777bb4)
![license](https://img.shields.io/badge/license-MIT-orange)

---

## 目录

- [功能特性](#功能特性)
- [工作机制](#工作机制)
- [Hook 点](#hook-点)
- [安装路径](#安装路径)
- [WHMCS 后台启用](#whmcs-后台启用)
- [配置说明](#配置说明)
- [后台页面](#后台页面)
- [数据库](#数据库)
- [自动扣款注意事项](#自动扣款注意事项)
- [测试方式](#测试方式)
- [回滚方式](#回滚方式)
- [合规提醒](#合规提醒)
- [目录结构](#目录结构)
- [参考文档](#参考文档)
- [License](#license)

---

## 功能特性

| 类别 | 特性 |
|------|------|
| 网关范围 | 默认支持 `stripe`、`stripealipay`，可在 addon 配置中调整 |
| 费用规则 | `base = invoice 当前应付金额 - 本模块已加 fee`；`fee = round(base * fee_percent / 100, 2)` |
| 发票状态 | 只处理 `Unpaid` invoice；`Paid` / `Cancelled` / `Refunded` / `Collections` 等状态不修改 |
| 防重复 | 自有审计表 `active_invoice_id` 唯一索引 + invoice 级 MySQL `GET_LOCK` |
| 明示收费 | fee 作为 `tblinvoiceitems` line item 展示在 WHMCS invoice 内 |
| WHMCS 9.0 安全边界 | 只在 `InvoiceCreation` 阶段写入 fee；已发布 invoice 的 gateway 切换只检测并写 module log，不自动增删 line item |
| 金额精度 | PHP 内部使用 decimal string → integer cents 计算，不使用 float 累加 |
| 管理后台 | 显示当前配置、最近 50 条 fee 记录，支持 invoice dry-run / immutable-safe check |

---

## 工作机制

1. `InvoiceCreation` 触发时读取 invoice 的 `status`、`paymentmethod`、`credit`、交易入账额和当前 line items。
2. 如果 payment method 命中配置网关，就按实际应付口径计算 base：当前 line items 小计 - 已有本模块 fee - invoice credit - 已入账金额。
3. 使用 WHMCS `UpdateInvoice` Local API 在 invoice finalise / deliver 前新增 fee line item。
4. WHMCS 在 `InvoiceCreation` hook 后重新计算 invoice totals，因此首封 invoice email 和客户看到的 invoice 应包含 fee。
5. `InvoiceCreated` / `InvoiceChangeGateway` / invoice view / cron automation 阶段不再对已发布 invoice 增删 line item；只检测 missing/mismatch/stale fee 并写 module log。
6. 自有表只做审计和幂等控制；如果创建阶段刷新 fee，旧记录标记为 `removed`，新记录标记为 `active`。

示例：

| 场景 | 结果 |
|------|------|
| 创建 HK$100.00 invoice 且 payment method 是 `stripe` | `InvoiceCreation` 阶段新增 `Payment gateway processing fee (3%)`，金额 HK$3.00，invoice total 变 HK$103.00 |
| 创建 HK$100.00 invoice 且已应用 HK$20.00 credit | fee base 为 HK$80.00，fee 为 HK$2.40 |
| 已发布 invoice 后切换到 `mailin` / `banktransfer` / USDT 类网关 | 不自动删除 fee line item；module log 记录 `unsupported-immutable-remove`，需要人工重开/作废/调整 invoice |

---

## Hook 点

| Hook | 用途 |
|------|------|
| `InvoiceCreation` | 尽量在 invoice 创建流程早期同步 fee |
| `InvoiceCreated` | 创建后检测 fee 是否存在；不修改已发布 invoice |
| `InvoiceChangeGateway` | 检测已发布 invoice 的 gateway/fee mismatch；不自动增删 line item |
| `ViewInvoiceDetailsPage` | 客户/后台查看 invoice 时检测 fee 状态 |
| `ClientAreaPageViewInvoice` | 客户区发票查看入口兼容检测 |
| `PreCronJob` | cron daily automation 开始前检测命中网关的 `Unpaid` invoices |
| `PreAutomationTask` | WHMCS automation task 开始前再次检测，并按 task name 去重 |

`InvoiceCreation` 发生在 invoice finalise / deliver 前。这个阶段 `tblinvoices.total` 可能尚未最终化，所以模块不会用 total/balance 计算 base，而是从当前 `tblinvoiceitems` line items 求和，再扣除已有 fee、invoice credit 和已入账金额。WHMCS 会在该 hook 后重新计算 totals，因此初始 invoice email 理论上应包含 fee；上线前仍需在 WHMCS 9.0.4 staging 验证邮件内容。

WHMCS 9.0 系列在 invoice 离开 Draft 后限制直接编辑 invoice line items。因此，除 `InvoiceCreation` 外，本模块不再承诺自动增删已发布 `Unpaid` invoice 的 fee。`PreCronJob` 与 `PreAutomationTask` 只做检测和日志；二者不共享全局“一次性”去重，`PreAutomationTask` 会按 WHMCS 提供的 task name 去重，未知 task name 时不去重，避免被 daily cron 起点的检测挡住。

---

## 安装路径

把本仓库里的 addon 目录复制到 WHMCS 根目录：

```bash
cp -a modules/addons/termrat_gateway_fee /data/wwwroot/cloud.termrat.com/modules/addons/
```

最终路径应为：

```text
/data/wwwroot/cloud.termrat.com/modules/addons/termrat_gateway_fee/
├── termrat_gateway_fee.php
├── hooks.php
├── lib/
├── lang/
└── templates/
```

TermRat 本地工程位置：

```text
/Users/wingsrabbit/Desktop/AI-Memory/Projects/TermRat-VPS/TermRat-Gateway-Fee
```

---

## WHMCS 后台启用

1. 登录 WHMCS Admin。
2. 进入 `System Settings` → `Addon Modules`。
3. 找到 `TermRat Gateway Fee`，点击 `Activate`。
4. 点击 `Configure`，确认配置值。
5. 保存后进入 addon 页面执行一个 invoice dry-run 验证。

WHMCS addon migration 在 TermRat 当前环境中通常由访问 `Setup -> Addon Modules` 触发。部署后如未创建表，先进入 Addon Modules 页面确认 activation 状态。

---

## 配置说明

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enabled` | `on` | 总开关。关闭后不再新增 fee；已发布 invoice 上的历史 fee 不会被自动删除 |
| `fee_percent` | `3.00` | 手续费比例，支持小数 |
| `gateways` | `stripe,stripealipay` | 逗号分隔的 WHMCS gateway system names |
| `fee_description_en` | `Payment gateway processing fee ({percent}%)` | 英文客户 invoice item 描述 |
| `fee_description_zh` | `支付网关手续费（{percent}%）` | 中文客户 invoice item 描述 |
| `taxable` | `off` | 是否将 fee item 标为 taxable |
| `debug_log` | `off` | 打开后记录 skip/no-op 细节；add/remove/error 始终写 module log |

当前默认支持网关：

```text
stripe
stripealipay
```

---

## 后台页面

Addon 后台页面显示：

| 区域 | 内容 |
|------|------|
| Current Configuration | 当前配置快照 |
| Dry-run / Check Invoice | 输入 invoice id，执行 dry-run 或 immutable-safe check |
| Recent Fee Records | 最近 50 条 fee 审计记录 |

记录字段包括：

```text
invoice_id
gateway
base_amount
fee_amount
status
created_at
updated_at
```

---

## 数据库

模块创建自有表：

```text
mod_termrat_gateway_fee_items
```

字段：

| 字段 | 说明 |
|------|------|
| `id` | 自增主键 |
| `invoice_id` | WHMCS invoice id |
| `invoice_item_id` | 对应 `tblinvoiceitems.id` |
| `active_invoice_id` | active 记录唯一键；removed 记录为 `NULL` |
| `gateway` | 触发 fee 的 gateway |
| `fee_percent` | 触发时配置的 fee percent |
| `base_amount` | 排除本模块 fee 后的 base |
| `fee_amount` | 本次 fee 金额 |
| `currency_id` | 客户 currency id |
| `status` | `active` / `removed` |
| `created_at` | 创建时间 |
| `updated_at` | 更新时间 |

唯一约束：

```text
UNIQUE KEY uniq_tgf_active_invoice (active_invoice_id)
```

MySQL/MariaDB 允许多个 `NULL`，所以 removed 历史记录可保留多条，但同一 invoice 同时只能有一条 active fee。

---

## 自动扣款注意事项

不能只依赖客户打开 invoice 页面触发，因为保存卡自动扣款可能不会打开页面。

本模块在 cron/automation 前检测：

```text
PreCronJob
PreAutomationTask
```

自动化检测最多每次扫描 500 张命中条件的 invoice，并检测 500 条 stale active fee。若业务量超过该范围，可在代码中调整 `TermRatGatewayFeeManager::AUTOMATION_LIMIT`，或改成分批任务。

自动扣款前 fee 必须依赖 invoice 创建时的 `InvoiceCreation` 写入。若 invoice 已经发布后才切换到 Stripe 类网关，本模块不会自动加 fee；若已发布后从 Stripe 类网关切走，本模块不会自动删 fee。人工处理建议是作废并重开 invoice，或按业务规则开 credit/debit note，而不是直接改 WHMCS core/DB。

---

## 测试方式

当前仓库提供两类测试脚本：

```bash
python3 tests/test_gateway_fee_behavior.py
```

覆盖：

| # | 场景 |
|---|------|
| 1 | `stripe` invoice 添加一次 fee |
| 2 | 重复执行不会重复添加 |
| 3 | `stripealipay` 也添加 fee |
| 4 | 已发布 invoice 切换到非 Stripe gateway 不会自动删除 immutable fee |
| 5 | `Paid` invoice 不修改 |
| 6 | base amount 不包含已有 fee |
| 7 | 并发/重复触发不会产生两条 active fee |
| 8 | fee percent 可配置 |
| 9 | `InvoiceCreation` 阶段 base 使用 line items，不依赖未最终化 total |
| 10 | `InvoiceCreation` 阶段 base 扣除 invoice credit / 已入账金额 |
| 11 | `PreCronJob` 后触发 `PreAutomationTask` 不会被全局 static 无条件跳过 |

如果环境有 PHP CLI，可再跑：

```bash
php tests/run_fee_manager_tests.php
php tests/run_hook_tests.php
```

部署到 WHMCS staging 后建议再执行人工验收：

1. 创建 HK$100.00 invoice，创建前 payment method 设为 `stripe`。
2. 确认 `InvoiceCreation` 后首封 invoice email 与 invoice 页面都包含 fee line item，invoice total 为 HK$103.00。
3. 对已发布 invoice 再次打开 addon dry-run / check，确认不会新增第二条 fee。
4. 创建 HK$100.00 且已应用 HK$20.00 credit 的 invoice，确认 fee base 为 HK$80.00，fee 为 HK$2.40。
5. 已发布后把 payment method 切到 `mailin` 或 `banktransfer`，确认模块不自动删除 line item，并在 module log 记录 immutable unsupported。
6. 把 invoice 标记为 `Paid` 后再次 check，确认不修改。

---

## 回滚方式

短暂停用：

1. WHMCS Admin → `System Settings` → `Addon Modules` → `TermRat Gateway Fee` → `Configure`。
2. 取消 `enabled`。
3. 已发布 invoice 上的历史 fee 不会被自动删除；如需调整，作废并重开 invoice，或按业务规则开 credit/debit note。

完全停用：

1. 确认没有正在创建中的 invoice 依赖本模块写入 fee。
2. Deactivate addon module。
3. 删除 WHMCS 里的 `modules/addons/termrat_gateway_fee/`。

完全卸载：

1. WHMCS Addon Modules 里执行 uninstall。
2. uninstall 会删除 `mod_termrat_gateway_fee_items` 审计表。
3. 已经写入 WHMCS invoice 的历史 line item 不会被 uninstall 自动扫描；卸载前应先清理 active fee。

---

## 合规提醒

这是 payment gateway processing fee / surcharge。不同国家和地区对 card surcharge、payment method surcharge、披露方式、上限比例、客户同意有不同限制。

上线前必须确认 TermRat 面向地区的合规要求；本模块只负责在 invoice 中显式展示 fee，不代表该 fee 在所有地区都可收取。

---

## 目录结构

```text
TermRat-Gateway-Fee/
├── modules/
│   └── addons/
│       └── termrat_gateway_fee/
│           ├── termrat_gateway_fee.php      # WHMCS addon entrypoint
│           ├── hooks.php                    # WHMCS hook registrations
│           ├── lib/
│           │   ├── FeeManager.php           # fee sync / math / locking / Local API
│           │   └── Installer.php            # activation / uninstall schema
│           ├── lang/
│           │   ├── english.php
│           │   └── chinese.php
│           └── templates/
│               └── admin.tpl
├── tests/
│   ├── test_gateway_fee_behavior.py
│   └── run_fee_manager_tests.php
├── VERSION
├── CHANGELOG.md
├── LICENSE
└── README.md
```

---

## 参考文档

- [WHMCS Addon Modules: Getting Started](https://developers.whmcs.com/addon-modules/getting-started/)
- [WHMCS Addon Module hooks.php](https://developers.whmcs.com/addon-modules/hooks/)
- [WHMCS Invoice Hooks](https://developers.whmcs.com/hooks-reference/invoices-and-quotes/)
- [WHMCS Cron Hooks](https://developers.whmcs.com/hooks-reference/cron/)
- [WHMCS UpdateInvoice API](https://developers.whmcs.com/api-reference/updateinvoice/)
- [WHMCS 9.0 Release Notes: Invoice Immutability](https://docs.whmcs.com/releases/9-0/9-0-release-notes/)
- [WHMCS 9.0 Invoice Management](https://docs.whmcs.com/9-0/billing-and-invoicing/invoice-management/)

---

## License

[MIT](LICENSE) © wingsrabbit
