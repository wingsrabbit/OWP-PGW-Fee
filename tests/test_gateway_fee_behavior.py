#!/usr/bin/env python3
from decimal import Decimal, ROUND_HALF_UP
from threading import Lock, Thread


def money(value):
    return Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def invoice_items_base_amount(items, exclude_item_id=0, credit="0.00", paid="0.00"):
    total = Decimal("0.00")
    for item in items:
        if exclude_item_id and item["id"] == exclude_item_id:
            continue
        total += money(item["amount"])
    total -= money(credit)
    total -= money(paid)
    return max(money(total), money("0.00"))


def automation_run_key(reason, task_name=""):
    if reason == "PreCronJob":
        return "PreCronJob"
    if reason == "PreAutomationTask":
        task_key = "".join(ch.lower() if ch.isalnum() or ch in "_.:-" else "-" for ch in task_name).strip("-")
        return f"PreAutomationTask:{task_key}" if task_key else ""
    return reason


class GatewayFeeScenario:
    def __init__(
        self,
        fee_percent="3.00",
        gateways=None,
        enabled=False,
        mode="canary",
        dry_run_only=True,
        canary_invoice_ids=None,
        canary_client_ids=None,
        emergency_kill_switch=False,
        automation_limit=500,
    ):
        self.fee_percent = Decimal(str(fee_percent))
        self.gateways = set(gateways or ["stripe", "stripealipay"])
        self.enabled = enabled
        self.mode = mode
        self.dry_run_only = dry_run_only
        self.canary_invoice_ids = set(canary_invoice_ids or [])
        self.canary_client_ids = set(canary_client_ids or [])
        self.emergency_kill_switch = emergency_kill_switch
        self.automation_limit = automation_limit
        self.invoices = {}
        self.active = {}
        self.audit = []
        self.next_item_id = 1
        self.lock = Lock()

    def add_invoice(self, invoice_id, total, gateway, status="Unpaid", credit="0.00", paid="0.00", client_id=10):
        self.invoices[invoice_id] = {
            "client_id": client_id,
            "status": status,
            "gateway": gateway,
            "credit": money(credit),
            "paid": money(paid),
            "items": [{"id": self.next_item_id, "description": "base", "amount": money(total)}],
        }
        self.next_item_id += 1

    def set_gateway(self, invoice_id, gateway):
        self.invoices[invoice_id]["gateway"] = gateway

    def set_status(self, invoice_id, status):
        self.invoices[invoice_id]["status"] = status

    def total(self, invoice_id):
        return money(sum(item["amount"] for item in self.invoices[invoice_id]["items"]))

    def fee_items(self, invoice_id):
        return [
            item
            for item in self.invoices[invoice_id]["items"]
            if item["description"].startswith("Payment gateway processing fee")
        ]

    def write_status(self, invoice_id):
        if self.emergency_kill_switch:
            return "emergency-killed"
        if not self.enabled:
            return "module-disabled"
        if self.dry_run_only:
            return "dry-run-only"
        if self.mode == "production":
            return "allowed"
        if self.mode != "canary":
            return "invalid-mode"

        invoice = self.invoices[invoice_id]
        if not self.canary_invoice_ids or not self.canary_client_ids:
            return "canary-allowlist-required"
        if invoice_id not in self.canary_invoice_ids or invoice["client_id"] not in self.canary_client_ids:
            return "canary-not-allowlisted"
        return "allowed"

    def sync_creation(self, invoice_id):
        with self.lock:
            guard = self.write_status(invoice_id)
            if guard in ("emergency-killed", "module-disabled"):
                return guard

            invoice = self.invoices[invoice_id]
            active = self.active.get(invoice_id)
            if invoice["status"] not in ("", "Draft", "Unpaid"):
                return "skipped"

            if invoice["gateway"] not in self.gateways:
                return "skipped"

            base = invoice_items_base_amount(
                invoice["items"],
                exclude_item_id=active["invoice_item_id"] if active else 0,
                credit=invoice["credit"],
                paid=invoice["paid"],
            )
            fee = money(base * self.fee_percent / Decimal("100"))
            if active:
                if active["base_amount"] == base and active["fee_amount"] == fee and active["fee_percent"] == self.fee_percent:
                    return "noop"
                if guard != "allowed":
                    return guard
                self._remove_active_fee(invoice_id)

            if guard != "allowed":
                return guard
            if fee <= money("0.00"):
                return "skipped"

            self._add_fee(invoice_id, base, fee)
            return "added"

    def sync_published(self, invoice_id):
        with self.lock:
            invoice = self.invoices[invoice_id]
            active = self.active.get(invoice_id)
            if invoice["status"] != "Unpaid":
                return "skipped"

            applicable = invoice["gateway"] in self.gateways
            if not applicable and not active:
                return "skipped"

            guard = self.write_status(invoice_id)
            if not applicable and active:
                if guard != "allowed":
                    return guard
                self._remove_active_fee(invoice_id)
                return "removed"

            if applicable and not active:
                base = invoice_items_base_amount(invoice["items"], credit=invoice["credit"], paid=invoice["paid"])
                fee = money(base * self.fee_percent / Decimal("100"))
                if fee <= money("0.00"):
                    return "skipped"
                if guard != "allowed":
                    return guard
                self._add_fee(invoice_id, base, fee)
                return "added"

            base = invoice_items_base_amount(
                invoice["items"],
                exclude_item_id=active["invoice_item_id"],
                credit=invoice["credit"],
                paid=invoice["paid"],
            )
            fee = money(base * self.fee_percent / Decimal("100"))
            if active["base_amount"] != base or active["fee_amount"] != fee or active["fee_percent"] != self.fee_percent:
                if guard != "allowed":
                    return guard
                self._remove_active_fee(invoice_id)
                if fee <= money("0.00"):
                    return "skipped"
                self._add_fee(invoice_id, base, fee)
                return "refreshed"

            if active["gateway"] != invoice["gateway"]:
                if guard != "allowed":
                    return guard
                active["gateway"] = invoice["gateway"]
                return "gateway-updated"

            return "noop"

    def dry_run_invoice(self, invoice_id):
        invoice = self.invoices[invoice_id]
        active = self.active.get(invoice_id)
        if invoice["status"] != "Unpaid":
            return "skipped"
        applicable = invoice["gateway"] in self.gateways
        if not applicable and active:
            action = "would-remove"
        elif applicable and not active:
            base = invoice_items_base_amount(invoice["items"], credit=invoice["credit"], paid=invoice["paid"])
            fee = money(base * self.fee_percent / Decimal("100"))
            action = "would-add" if fee > money("0.00") else "skipped"
        elif applicable and active:
            base = invoice_items_base_amount(
                invoice["items"],
                exclude_item_id=active["invoice_item_id"],
                credit=invoice["credit"],
                paid=invoice["paid"],
            )
            fee = money(base * self.fee_percent / Decimal("100"))
            if active["base_amount"] != base or active["fee_amount"] != fee or active["fee_percent"] != self.fee_percent:
                action = "would-refresh"
            elif active["gateway"] != invoice["gateway"]:
                action = "would-update-gateway"
            else:
                action = "noop"
        else:
            action = "skipped"

        if action.startswith("would-"):
            guard = self.write_status(invoice_id)
            return action if guard == "allowed" else guard
        return action

    def sync_automation(self):
        stats = {"scanned": 0, "synced": 0, "unsupported": 0, "blocked": 0, "errors": 0}
        if self.emergency_kill_switch:
            stats["blocked"] += 1
            return stats
        if not self.enabled:
            stats["blocked"] += 1
            return stats
        if self.mode != "production":
            stats["blocked"] += 1
            return stats

        candidate_ids = []
        for invoice_id, invoice in sorted(self.invoices.items()):
            if invoice["status"] != "Unpaid":
                continue
            if invoice["gateway"] in self.gateways or invoice_id in self.active:
                candidate_ids.append(invoice_id)
            if len(candidate_ids) >= self.automation_limit:
                break

        for invoice_id in candidate_ids:
            stats["scanned"] += 1
            result = self.dry_run_invoice(invoice_id) if self.dry_run_only else self.sync_published(invoice_id)
            if result in ("noop", "skipped", "added", "removed", "refreshed", "gateway-updated"):
                stats["synced"] += 1
            elif result.startswith("would-") or result in (
                "dry-run-only",
                "module-disabled",
                "canary-allowlist-required",
                "canary-not-allowlisted",
                "invalid-mode",
                "emergency-killed",
            ):
                stats["blocked"] += 1
            else:
                stats["unsupported"] += 1
        return stats

    def _add_fee(self, invoice_id, base, fee):
        invoice = self.invoices[invoice_id]
        item = {
            "id": self.next_item_id,
            "description": f"Payment gateway processing fee ({self.fee_percent.normalize()}%)",
            "amount": fee,
        }
        self.next_item_id += 1
        invoice["items"].append(item)
        record = {
            "invoice_id": invoice_id,
            "invoice_item_id": item["id"],
            "gateway": invoice["gateway"],
            "base_amount": base,
            "fee_amount": fee,
            "fee_percent": self.fee_percent,
            "status": "active",
        }
        self.active[invoice_id] = record
        self.audit.append(record)

    def _remove_active_fee(self, invoice_id):
        active = self.active[invoice_id]
        invoice = self.invoices[invoice_id]
        invoice["items"] = [item for item in invoice["items"] if item["id"] != active["invoice_item_id"]]
        active["status"] = "removed"
        del self.active[invoice_id]


def canary_writer(fee_percent="3.00"):
    return GatewayFeeScenario(
        fee_percent=fee_percent,
        enabled=True,
        mode="canary",
        dry_run_only=False,
        canary_invoice_ids=list(range(1, 1000)),
        canary_client_ids=list(range(1, 1000)),
    )


def production_writer(fee_percent="3.00"):
    return GatewayFeeScenario(
        fee_percent=fee_percent,
        enabled=True,
        mode="production",
        dry_run_only=False,
    )


def test_stripe_invoice_adds_one_fee():
    app = production_writer()
    app.add_invoice(1, "100.00", "stripe", client_id=10)
    assert app.sync_creation(1) == "added"
    assert app.total(1) == money("103.00")
    assert len(app.fee_items(1)) == 1


def test_repeat_sync_does_not_duplicate_fee():
    app = production_writer()
    app.add_invoice(1, "100.00", "stripe", client_id=10)
    assert app.sync_creation(1) == "added"
    assert app.sync_creation(1) == "noop"
    assert app.total(1) == money("103.00")
    assert len(app.fee_items(1)) == 1


def test_stripealipay_adds_fee():
    app = production_writer()
    app.add_invoice(2, "100.00", "stripealipay", client_id=10)
    assert app.sync_creation(2) == "added"
    assert app.total(2) == money("103.00")


def test_production_mode_does_not_require_allowlist():
    app = production_writer()
    app.add_invoice(20, "100.00", "stripe", client_id=999)
    assert app.sync_creation(20) == "added"
    assert len(app.fee_items(20)) == 1


def test_production_published_unpaid_switch_from_mailin_to_stripe_adds_fee():
    app = production_writer()
    app.add_invoice(3, "100.00", "mailin", client_id=10)
    assert app.sync_creation(3) == "skipped"
    app.set_gateway(3, "stripe")
    assert app.sync_published(3) == "added"
    assert app.total(3) == money("103.00")
    assert len(app.fee_items(3)) == 1


def test_production_published_unpaid_switch_from_stripealipay_to_mailin_removes_fee():
    app = production_writer()
    app.add_invoice(4, "100.00", "stripealipay", client_id=10)
    assert app.sync_creation(4) == "added"
    app.set_gateway(4, "mailin")
    assert app.sync_published(4) == "removed"
    assert app.total(4) == money("100.00")
    assert len(app.fee_items(4)) == 0


def test_published_unpaid_switch_between_stripe_gateways_does_not_duplicate_fee():
    app = production_writer()
    app.add_invoice(5, "100.00", "stripe", client_id=10)
    assert app.sync_creation(5) == "added"
    first_item_id = app.fee_items(5)[0]["id"]
    app.set_gateway(5, "stripealipay")
    assert app.sync_published(5) == "gateway-updated"
    assert app.total(5) == money("103.00")
    assert len(app.fee_items(5)) == 1
    assert app.fee_items(5)[0]["id"] == first_item_id


def test_non_unpaid_statuses_are_not_modified():
    for idx, status in enumerate(["Paid", "Cancelled", "Refunded", "Collections"], start=30):
        app = production_writer()
        app.add_invoice(idx, "100.00", "stripe", status=status, client_id=10)
        assert app.sync_creation(idx) == "skipped"
        assert app.sync_published(idx) == "skipped"
        assert app.total(idx) == money("100.00")
        assert len(app.fee_items(idx)) == 0


def test_paid_invoice_with_existing_fee_is_not_modified_after_gateway_change():
    app = production_writer()
    app.add_invoice(40, "100.00", "stripe", status="Unpaid", client_id=10)
    assert app.sync_creation(40) == "added"
    app.set_status(40, "Paid")
    app.set_gateway(40, "banktransfer")
    assert app.sync_published(40) == "skipped"
    assert app.total(40) == money("103.00")
    assert len(app.fee_items(40)) == 1


def test_base_excludes_existing_fee():
    app = production_writer()
    app.add_invoice(50, "100.00", "stripe", client_id=10)
    assert app.sync_creation(50) == "added"
    active = app.active[50]
    assert active["base_amount"] == money("100.00")
    assert active["fee_amount"] == money("3.00")
    assert app.sync_creation(50) == "noop"
    assert app.active[50]["base_amount"] == money("100.00")


def test_concurrent_repeat_sync_does_not_duplicate_active_fee():
    app = production_writer()
    app.add_invoice(60, "100.00", "stripe", client_id=10)
    results = []
    threads = [Thread(target=lambda: results.append(app.sync_creation(60))) for _ in range(12)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()
    assert results.count("added") == 1
    assert len(app.fee_items(60)) == 1
    assert len(app.active) == 1


def test_fee_percent_is_configurable():
    app = production_writer(fee_percent="2.50")
    app.add_invoice(70, "100.00", "stripe", client_id=10)
    assert app.sync_creation(70) == "added"
    assert app.total(70) == money("102.50")
    assert app.active[70]["fee_amount"] == money("2.50")


def test_invoice_creation_base_uses_line_items_not_unfinalized_total():
    items = [
        {"id": 1, "description": "service", "amount": "70.00"},
        {"id": 2, "description": "setup", "amount": "30.00"},
        {"id": 3, "description": "Payment gateway processing fee (3%)", "amount": "3.00"},
    ]
    assert invoice_items_base_amount(items) == money("103.00")
    assert invoice_items_base_amount(items, exclude_item_id=3) == money("100.00")


def test_invoice_creation_base_excludes_credit_and_paid_amounts():
    items = [
        {"id": 1, "description": "service", "amount": "100.00"},
    ]
    assert invoice_items_base_amount(items, credit="20.00", paid="0.00") == money("80.00")
    app = production_writer()
    app.add_invoice(80, "100.00", "stripe", credit="20.00", client_id=10)
    assert app.sync_creation(80) == "added"
    assert app.active[80]["base_amount"] == money("80.00")
    assert app.active[80]["fee_amount"] == money("2.40")


def test_preautomationtask_after_precronjob_is_not_globally_skipped():
    seen = set()
    precron = automation_run_key("PreCronJob")
    capture = automation_run_key("PreAutomationTask", "Credit Card Charges")
    unknown_task = automation_run_key("PreAutomationTask")

    assert precron == "PreCronJob"
    assert capture == "PreAutomationTask:credit-card-charges"
    assert unknown_task == ""

    seen.add(precron)
    assert capture not in seen
    assert unknown_task == ""


def test_default_config_does_not_write_invoice():
    app = GatewayFeeScenario()
    app.add_invoice(90, "100.00", "stripe")
    assert app.sync_creation(90) == "module-disabled"
    assert app.total(90) == money("100.00")
    assert len(app.fee_items(90)) == 0


def test_canary_mode_still_requires_invoice_and_client_allowlists():
    app = GatewayFeeScenario(enabled=True, mode="canary", dry_run_only=False)
    app.add_invoice(91, "100.00", "stripe", client_id=91)
    assert app.sync_creation(91) == "canary-allowlist-required"
    assert len(app.fee_items(91)) == 0


def test_canary_mode_allows_only_exact_invoice_client_pair():
    app = GatewayFeeScenario(
        enabled=True,
        mode="canary",
        dry_run_only=False,
        canary_invoice_ids=[92],
        canary_client_ids=[920],
    )
    app.add_invoice(92, "100.00", "stripe", client_id=920)
    app.add_invoice(93, "100.00", "stripe", client_id=920)
    app.add_invoice(94, "100.00", "stripe", client_id=921)
    assert app.sync_creation(92) == "added"
    assert app.sync_creation(93) == "canary-not-allowlisted"
    assert app.sync_creation(94) == "canary-not-allowlisted"
    assert len(app.fee_items(92)) == 1
    assert len(app.fee_items(93)) == 0
    assert len(app.fee_items(94)) == 0


def test_production_mode_automation_scans_only_unpaid_candidates_and_no_duplicates():
    app = production_writer()
    app.add_invoice(100, "100.00", "stripe", client_id=100)
    app.add_invoice(101, "100.00", "stripe", status="Paid", client_id=101)
    app.add_invoice(102, "100.00", "stripealipay", client_id=102)
    app.add_invoice(103, "100.00", "stripe", client_id=103)
    assert app.sync_creation(102) == "added"
    assert app.sync_creation(103) == "added"
    app.set_gateway(102, "mailin")

    stats = app.sync_automation()
    assert stats == {"scanned": 3, "synced": 3, "unsupported": 0, "blocked": 0, "errors": 0}
    assert len(app.fee_items(100)) == 1
    assert len(app.fee_items(101)) == 0
    assert len(app.fee_items(102)) == 0
    assert len(app.fee_items(103)) == 1
    assert app.total(100) == money("103.00")
    assert app.total(101) == money("100.00")
    assert app.total(102) == money("100.00")
    assert app.total(103) == money("103.00")


def test_automation_batch_limit_is_respected():
    app = GatewayFeeScenario(enabled=True, mode="production", dry_run_only=False, automation_limit=2)
    for invoice_id in (110, 111, 112):
        app.add_invoice(invoice_id, "100.00", "stripe", client_id=invoice_id)
    stats = app.sync_automation()
    assert stats["scanned"] == 2
    assert len(app.fee_items(110)) == 1
    assert len(app.fee_items(111)) == 1
    assert len(app.fee_items(112)) == 0


def test_automation_is_blocked_outside_production_mode():
    app = canary_writer()
    app.add_invoice(120, "100.00", "stripe", client_id=120)
    assert app.sync_automation() == {"scanned": 0, "synced": 0, "unsupported": 0, "blocked": 1, "errors": 0}
    assert len(app.fee_items(120)) == 0


def test_dry_run_blocks_writes_in_canary_and_production_modes():
    canary = GatewayFeeScenario(
        enabled=True,
        mode="canary",
        dry_run_only=True,
        canary_invoice_ids=[130],
        canary_client_ids=[130],
    )
    canary.add_invoice(130, "100.00", "stripe", client_id=130)
    assert canary.sync_creation(130) == "dry-run-only"
    assert len(canary.fee_items(130)) == 0

    production = GatewayFeeScenario(enabled=True, mode="production", dry_run_only=True)
    production.add_invoice(131, "100.00", "stripe", client_id=131)
    assert production.sync_creation(131) == "dry-run-only"
    assert len(production.fee_items(131)) == 0

    stats = production.sync_automation()
    assert stats == {"scanned": 1, "synced": 0, "unsupported": 0, "blocked": 1, "errors": 0}
    assert len(production.fee_items(131)) == 0


def test_emergency_kill_switch_blocks_writes_and_automation_in_all_modes():
    for mode in ("canary", "production"):
        app = GatewayFeeScenario(
            enabled=True,
            mode=mode,
            dry_run_only=False,
            canary_invoice_ids=[140],
            canary_client_ids=[140],
            emergency_kill_switch=True,
        )
        app.add_invoice(140, "100.00", "stripe", client_id=140)
        assert app.sync_creation(140) == "emergency-killed"
        assert app.sync_automation() == {"scanned": 0, "synced": 0, "unsupported": 0, "blocked": 1, "errors": 0}
        assert len(app.fee_items(140)) == 0


def run():
    tests = [
        test_stripe_invoice_adds_one_fee,
        test_repeat_sync_does_not_duplicate_fee,
        test_stripealipay_adds_fee,
        test_production_mode_does_not_require_allowlist,
        test_production_published_unpaid_switch_from_mailin_to_stripe_adds_fee,
        test_production_published_unpaid_switch_from_stripealipay_to_mailin_removes_fee,
        test_published_unpaid_switch_between_stripe_gateways_does_not_duplicate_fee,
        test_non_unpaid_statuses_are_not_modified,
        test_paid_invoice_with_existing_fee_is_not_modified_after_gateway_change,
        test_base_excludes_existing_fee,
        test_concurrent_repeat_sync_does_not_duplicate_active_fee,
        test_fee_percent_is_configurable,
        test_invoice_creation_base_uses_line_items_not_unfinalized_total,
        test_invoice_creation_base_excludes_credit_and_paid_amounts,
        test_preautomationtask_after_precronjob_is_not_globally_skipped,
        test_default_config_does_not_write_invoice,
        test_canary_mode_still_requires_invoice_and_client_allowlists,
        test_canary_mode_allows_only_exact_invoice_client_pair,
        test_production_mode_automation_scans_only_unpaid_candidates_and_no_duplicates,
        test_automation_batch_limit_is_respected,
        test_automation_is_blocked_outside_production_mode,
        test_dry_run_blocks_writes_in_canary_and_production_modes,
        test_emergency_kill_switch_blocks_writes_and_automation_in_all_modes,
    ]
    for test in tests:
        test()
    print(f"ok - {len(tests)} gateway fee behavior tests passed")


if __name__ == "__main__":
    run()
