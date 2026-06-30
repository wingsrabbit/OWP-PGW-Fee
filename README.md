# TermRat Gateway Fee · WHMCS Addon Module

> 给指定 WHMCS payment gateways 自动加/移除显式的 **payment gateway processing fee** invoice item。
> 它是 Addon Module + hooks，不是 payment gateway module；不修改 WHMCS core，不修改 Stripe gateway，不依赖前端 JS 作为金额依据。

> **Fail-closed default.**
> 默认 `enabled=off`、`mode=canary`、`dry_run_only=on`，部署后不会写任何 invoice。正式上线必须显式切到 production mode 并关闭 dry-run。

![version](https://img.shields.io/badge/version-v0.1.0-blue)
![whmcs](https://img.shields.io/badge/WHMCS-9.0.4-2ea44f)
![php](https://img.shields.io/badge/PHP-8.3-777bb4)
![license](https://img.shields.io/badge/license-MIT-orange)

---

## 目录

- [功能特性](#功能特性)
- [运行模式](#运行模式)
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
| Gateway switch | `mailin` / 非 Stripe -> `stripe` / `stripealipay` 自动 add fee；切走自动 remove 本模块 fee；Stripe 类之间切换不重复收费 |
| 防重复 | 自有审计表 `active_invoice_id` 唯一索引 + invoice 级 MySQL `GET_LOCK` |
| 明示收费 | fee 作为 `tblinvoiceitems` line item 展示在 WHMCS invoice 内 |
| WHMCS 9.0.4 路径 | 使用 WHMCS `UpdateInvoice` Local API add/remove fee line item；不重开发票，不改原 service/domain line item |
| 金额精度 | PHP 内部使用 decimal string -> integer cents 计算，不使用 float 累加 |
| 管理后台 | 显示当前配置、最近 50 条 fee 记录，支持 invoice dry-run / check |

根据受控 production canary 验收结论，目标 WHMCS 9.0.4 环境中 `UpdateInvoice` 可对 published `Unpaid` invoice add/remove 本模块 fee line item。本 PR 的 production mode 沿用该可行路径，不采用 cancel/reissue 或 credit/debit note 方案。

---

## 运行模式

| 模式 | 用途 | 写入条件 |
|------|------|----------|
| `canary` | 生产受控单票验证 | `enabled=on`、`mode=canary`、`dry_run_only=off`、`emergency_kill_switch=off`，并且 invoice id 和 client id 同时命中 allowlist |
| `production` | 正式自动化 | `enabled=on`、`mode=production`、`dry_run_only=off`、`emergency_kill_switch=off`；不需要 invoice/client allowlist |

全局保护：

- `enabled=off`：所有写入阻断。
- `dry_run_only=on`：所有写入阻断，只记录/返回 would-do 结果。
- `emergency_kill_switch=on`：无条件阻断 sync、automation 和 invoice 写入。
- 默认配置就是 fail-closed：`enabled=off`、`mode=canary`、`dry_run_only=on`。

正式上线推荐配置：

```text
enabled=on
mode=production
dry_run_only=off
emergency_kill_switch=off
gateways=stripe,stripealipay
```

受控 canary 推荐配置：

```text
enabled=on
mode=canary
dry_run_only=off
production_canary_invoice_ids=<test invoice id>
production_canary_client_ids=<test client id>
emergency_kill_switch=off
```

---

## 工作机制

1. `InvoiceCreation` 触发时读取 invoice 的 `status`、`paymentmethod`、`credit`、交易入账额和当前 line items。
2. 创建阶段不依赖尚未最终化的 `tblinvoices.total/balance`，而是从当前 line items 求和，再扣除已有本模块 fee、invoice credit 和已入账金额。
3. 如果 payment method 命中配置网关，且运行模式允许写入，模块通过 WHMCS `UpdateInvoice` Local API 新增 fee line item。
4. WHMCS 在 `InvoiceCreation` hook 后重新计算 invoice totals，因此首封 invoice email 和客户看到的 invoice 应包含 fee。
5. `InvoiceChangeGateway` / invoice view / admin check 对 published `Unpaid` invoice 执行同一同步逻辑：非 Stripe -> Stripe 类 add fee，Stripe 类 -> 非 Stripe remove fee，Stripe 类之间只更新审计 gateway。
6. `PreCronJob` / `PreAutomationTask` 在 production mode 中执行安全候选扫描，覆盖保存卡/自动扣款前没有人打开 invoice 页面的问题。
7. 自有表只做审计和幂等控制；如果刷新 fee，旧记录标记为 `removed`，新记录标记为 `active`。

示例：

| 场景 | 结果 |
|------|------|
| HK$100.00 `stripe` invoice | 新增 `Payment gateway processing fee (3%)`，金额 HK$3.00，invoice total 变 HK$103.00 |
| HK$100.00 invoice 已应用 HK$20.00 credit | fee base 为 HK$80.00，fee 为 HK$2.40 |
| published `Unpaid` invoice 从 `mailin` 切到 `stripe` | 自动新增 fee line item |
| published `Unpaid` invoice 从 `stripealipay` 切到 `mailin` | 自动删除本模块 fee line item，invoice total 回到 base |
| published `Unpaid` invoice 从 `stripe` 切到 `stripealipay` | 不新增第二条 fee，只更新审计 gateway |

`UpdateInvoice` 只 add/remove 本模块 fee line item，不修改既有 service/domain renewal/provisioning line items 的 `type` / `relid`，因此不会主动改变原 invoice 的服务续费/开通语义，也不会改变 invoice id、invoice number 或支付链接。

---

## Hook 点

| Hook | 用途 |
|------|------|
| `InvoiceCreation` | 创建阶段同步 fee，目标是让初始 invoice total/email 已包含 fee |
| `InvoiceCreated` | 创建后补偿同步 |
| `InvoiceChangeGateway` | published `Unpaid` gateway switch 主路径，自动 add/remove fee |
| `ViewInvoiceDetailsPage` | 后台/查看 invoice 时补偿同步 |
| `ClientAreaPageViewInvoice` | 客户区发票查看入口补偿同步 |
| `PreCronJob` | production mode 批量同步候选 `Unpaid` invoices |
| `PreAutomationTask` | automation task 前再次同步候选 invoices；按 task key 去重，未知 task 不去重 |

Automation 候选范围：

- 仅处理 `Unpaid` invoice。
- 添加候选：`paymentmethod` 命中配置网关的 `Unpaid` invoice。
- 移除候选：本模块有 active fee，但 invoice 当前 `paymentmethod` 已不在配置网关中的 `Unpaid` invoice。
- 每次最多处理 `AUTOMATION_LIMIT = 500` 张 invoice。
- canary mode 不做批量扫描。
- `dry_run_only=on` 时可以扫描并记录 would-do 结果，但不会写 invoice。

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
2. 进入 `System Settings` -> `Addon Modules`。
3. 找到 `TermRat Gateway Fee`，点击 `Activate`。
4. 点击 `Configure`，确认默认仍为 `enabled=off`、`mode=canary`、`dry_run_only=on`。
5. 保存后进入 addon 页面执行 invoice dry-run 验证。
6. 生产上线时再显式设置 `enabled=on`、`mode=production`、`dry_run_only=off`。

WHMCS addon migration 在 TermRat 当前环境中通常由访问 `Setup -> Addon Modules` 触发。部署后如未创建表，先进入 Addon Modules 页面确认 activation 状态。

---

## 配置说明

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enabled` | `off` | 总开关；默认不写任何 invoice |
| `mode` | `canary` | `canary` 需要 invoice/client allowlist；`production` 对所有符合条件的 `Unpaid` invoice 生效 |
| `dry_run_only` | `on` | 全模式只读保护；开启时不会调用 `UpdateInvoice` 写入 |
| `fee_percent` | `3.00` | 手续费比例，支持小数 |
| `gateways` | `stripe,stripealipay` | 逗号分隔的 WHMCS gateway system names |
| `fee_description_en` | `Payment gateway processing fee ({percent}%)` | 英文客户 invoice item 描述 |
| `fee_description_zh` | `支付网关手续费（{percent}%）` | 中文客户 invoice item 描述 |
| `taxable` | `off` | 是否将 fee item 标为 taxable |
| `debug_log` | `off` | 打开后记录 skip/no-op 细节；add/remove/error 始终写 module log |
| `production_canary_invoice_ids` | 空 | `mode=canary` 时允许写入的 invoice id 列表；production mode 不使用 |
| `production_canary_client_ids` | 空 | `mode=canary` 时允许写入的 client id 列表；production mode 不使用 |
| `emergency_kill_switch` | `off` | 紧急停止开关；开启后阻断 sync、automation 和 invoice 写入 |

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
| Dry-run / Check Invoice | 输入 invoice id，执行 dry-run 或按当前模式执行 check |
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

production mode 注册并使用：

```text
PreCronJob
PreAutomationTask
```

这些 hook 会在自动扣款流程前同步候选 `Unpaid` invoice，确保：

- 保存卡自动扣款前，配置网关 invoice 应有 fee。
- 已切走 Stripe 类网关且仍有本模块 active fee 的 `Unpaid` invoice 会被移除 fee。
- `Paid` / `Cancelled` / `Refunded` / `Collections` 不会被扫描或修改。
- 每次最多处理 500 张候选 invoice，避免无界批量操作。

canary mode 下 cron/automation 批量扫描保持阻断；如需 canary 单票验证，请使用 allowlist + invoice view/admin check。

---

## 测试方式

当前仓库提供 WHMCS-free 行为测试和 PHP 语法/工具测试：

```bash
git diff --check origin/main...HEAD
python3 tests/test_gateway_fee_behavior.py
python3 -m py_compile tests/test_gateway_fee_behavior.py
npx --yes @php-wasm/cli tests/run_fee_manager_tests.php
npx --yes @php-wasm/cli tests/run_hook_tests.php
find modules/addons/termrat_gateway_fee -name '*.php' -type f -print0 | xargs -0 -n1 npx --yes @php-wasm/cli -l
```

Python 行为测试覆盖：

| # | 场景 |
|---|------|
| 1 | `stripe` invoice 添加一次 fee |
| 2 | 重复执行不会重复添加 |
| 3 | `stripealipay` 也添加 fee |
| 4 | production mode 不需要 invoice/client allowlist |
| 5 | production mode 下 published `Unpaid` 从 `mailin` 切到 `stripe` 自动 add fee |
| 6 | production mode 下 published `Unpaid` 从 `stripealipay` 切到 `mailin` 自动 remove fee |
| 7 | `stripe` / `stripealipay` 之间切换不重复收费 |
| 8 | `Paid` / `Cancelled` / `Refunded` / `Collections` 不修改 |
| 9 | 已有 fee 的 `Paid` invoice 切走 gateway 后不修改 |
| 10 | base amount 不包含已有 fee |
| 11 | 并发/重复触发不会产生两条 active fee |
| 12 | fee percent 可配置 |
| 13 | `InvoiceCreation` 阶段 base 使用 line items，不依赖未最终化 total |
| 14 | `InvoiceCreation` 阶段 base 扣除 invoice credit / 已入账金额 |
| 15 | `PreCronJob` 后触发 `PreAutomationTask` 不会被全局 static 无条件跳过 |
| 16 | 默认 config 下不写入 |
| 17 | canary mode 仍要求 invoice/client allowlist |
| 18 | canary mode 只允许精确 invoice/client pair 写入 |
| 19 | production automation 只扫 `Unpaid` 候选，并且不重复 fee |
| 20 | automation batch limit 生效 |
| 21 | canary mode 阻断 automation 批量扫描 |
| 22 | dry-run 在 canary/production mode 都阻断写入 |
| 23 | emergency kill switch 在 canary/production mode 都阻断写入和 automation |

WHMCS 9.0.4 staging / canary 验收建议：

1. 默认配置部署后确认不会写任何 invoice。
2. `mode=canary`、allowlist 内部测试 invoice/client，确认 `InvoiceCreation` 可以加 fee。
3. 同一测试 invoice 从 `mailin` 切到 `stripe`，确认 published `Unpaid` 自动 add fee。
4. 同一测试 invoice 从 `stripealipay` 切到 `mailin`，确认 published `Unpaid` 自动 remove fee。
5. 切 `stripe` <-> `stripealipay`，确认不重复收费。
6. 切到 production mode 前先 `dry_run_only=on` 观察 automation log，再关闭 dry-run。

---

## 回滚方式

紧急停止：

1. WHMCS Admin -> `System Settings` -> `Addon Modules` -> `TermRat Gateway Fee` -> `Configure`。
2. 设置 `emergency_kill_switch=on`。

恢复只读：

1. 设置 `dry_run_only=on`。
2. 保留 `enabled=on` 可继续观察 would-do 行为和 module log。

完全停用：

1. 设置 `enabled=off`，或 deactivate addon module。
2. 已写入 invoice 的历史 fee 不会被自动删除；如需回退 active fee，可在 `mode=production` 且 `dry_run_only=off` 时切到非 Stripe 网关触发 remove，或按 WHMCS 后台/会计流程处理。

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
│   ├── run_fee_manager_tests.php
│   └── run_hook_tests.php
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
