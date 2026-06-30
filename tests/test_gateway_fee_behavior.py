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
        canary_enabled=False,
        canary_dry_run_only=True,
        canary_invoice_ids=None,
        canary_client_ids=None,
        emergency_kill_switch=False,
    ):
        self.fee_percent = Decimal(str(fee_percent))
        self.gateways = set(gateways or ["stripe", "stripealipay"])
        self.enabled = enabled
        self.canary_enabled = canary_enabled
        self.canary_dry_run_only = canary_dry_run_only
        self.canary_invoice_ids = set(canary_invoice_ids or [])
        self.canary_client_ids = set(canary_client_ids or [])
        self.emergency_kill_switch = emergency_kill_switch
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
        return [item for item in self.invoices[invoice_id]["items"] if item["description"].startswith("Payment gateway processing fee")]

    def canary_write_status(self, invoice_id):
        if self.emergency_kill_switch:
            return "emergency-killed"
        if not self.enabled:
            return "module-disabled"
        if not self.canary_enabled:
            return "canary-disabled"
        invoice = self.invoices[invoice_id]
        if not self.canary_invoice_ids or not self.canary_client_ids:
            return "canary-allowlist-required"
        if invoice_id not in self.canary_invoice_ids or invoice["client_id"] not in self.canary_client_ids:
            return "canary-not-allowlisted"
        if self.canary_dry_run_only:
            return "canary-dry-run-only"
        return "allowed"

    def sync_creation(self, invoice_id):
        with self.lock:
            guard = self.canary_write_status(invoice_id)
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
                invoice["items"] = [item for item in invoice["items"] if item["id"] != active["invoice_item_id"]]
                active["status"] = "removed"
                del self.active[invoice_id]

            if guard != "allowed":
                return guard

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
            return "added"

    def sync_published(self, invoice_id):
        with self.lock:
            invoice = self.invoices[invoice_id]
            active = self.active.get(invoice_id)
            if invoice["status"] != "Unpaid":
                return "skipped"
            if invoice["gateway"] not in self.gateways and active:
                return "unsupported-immutable-remove"
            if invoice["gateway"] in self.gateways and not active:
                return "unsupported-immutable-add"
            return "noop"

    def sync_automation(self):
        return {"blocked": 1, "scanned": 0}


def canary_writer(fee_percent="3.00"):
    return GatewayFeeScenario(
        fee_percent=fee_percent,
        enabled=True,
        canary_enabled=True,
        canary_dry_run_only=False,
        canary_invoice_ids=list(range(1, 100)),
        canary_client_ids=list(range(1, 100)),
    )


def test_stripe_invoice_adds_one_fee():
    app = canary_writer()
    app.add_invoice(1, "100.00", "stripe", client_id=10)
    assert app.sync_creation(1) == "added"
    assert app.total(1) == money("103.00")
    assert len(app.fee_items(1)) == 1


def test_repeat_sync_does_not_duplicate_fee():
    app = canary_writer()
    app.add_invoice(1, "100.00", "stripe", client_id=10)
    assert app.sync_creation(1) == "added"
    assert app.sync_creation(1) == "noop"
    assert app.total(1) == money("103.00")
    assert len(app.fee_items(1)) == 1


def test_stripealipay_adds_fee():
    app = canary_writer()
    app.add_invoice(2, "100.00", "stripealipay", client_id=10)
    assert app.sync_creation(2) == "added"
    assert app.total(2) == money("103.00")


def test_published_gateway_switch_does_not_mutate_immutable_invoice():
    app = canary_writer()
    app.add_invoice(3, "100.00", "stripe", client_id=10)
    assert app.sync_creation(3) == "added"
    app.set_gateway(3, "banktransfer")
    assert app.sync_published(3) == "unsupported-immutable-remove"
    assert app.total(3) == money("103.00")
    assert len(app.fee_items(3)) == 1


def test_paid_invoice_is_not_modified():
    app = canary_writer()
    app.add_invoice(4, "100.00", "stripe", status="Paid", client_id=10)
    assert app.sync_creation(4) == "skipped"
    assert app.total(4) == money("100.00")
    app.set_status(4, "Unpaid")
    assert app.sync_creation(4) == "added"
    app.set_status(4, "Paid")
    app.set_gateway(4, "banktransfer")
    assert app.sync_published(4) == "skipped"
    assert app.total(4) == money("103.00")
    assert len(app.fee_items(4)) == 1


def test_base_excludes_existing_fee():
    app = canary_writer()
    app.add_invoice(5, "100.00", "stripe", client_id=10)
    assert app.sync_creation(5) == "added"
    active = app.active[5]
    assert active["base_amount"] == money("100.00")
    assert active["fee_amount"] == money("3.00")
    assert app.sync_creation(5) == "noop"
    assert app.active[5]["base_amount"] == money("100.00")


def test_concurrent_repeat_sync_does_not_duplicate_active_fee():
    app = canary_writer()
    app.add_invoice(6, "100.00", "stripe", client_id=10)
    results = []
    threads = [Thread(target=lambda: results.append(app.sync_creation(6))) for _ in range(12)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()
    assert results.count("added") == 1
    assert len(app.fee_items(6)) == 1
    assert len(app.active) == 1


def test_fee_percent_is_configurable():
    app = canary_writer(fee_percent="2.50")
    app.add_invoice(7, "100.00", "stripe", client_id=10)
    assert app.sync_creation(7) == "added"
    assert app.total(7) == money("102.50")
    assert app.active[7]["fee_amount"] == money("2.50")


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
    app = canary_writer()
    app.add_invoice(8, "100.00", "stripe", credit="20.00", client_id=10)
    assert app.sync_creation(8) == "added"
    assert app.active[8]["base_amount"] == money("80.00")
    assert app.active[8]["fee_amount"] == money("2.40")


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
    assert unknown_task == ""  # empty key means no dedupe, so WHMCS task still runs


def test_default_config_does_not_write_invoice():
    app = GatewayFeeScenario()
    app.add_invoice(9, "100.00", "stripe")
    assert app.sync_creation(9) == "module-disabled"
    assert app.total(9) == money("100.00")
    assert len(app.fee_items(9)) == 0


def test_canary_disabled_does_not_write_invoice():
    app = GatewayFeeScenario(enabled=True, canary_enabled=False)
    app.add_invoice(10, "100.00", "stripe", client_id=20)
    assert app.sync_creation(10) == "canary-disabled"
    assert app.total(10) == money("100.00")
    assert len(app.fee_items(10)) == 0


def test_enabled_on_but_canary_disabled_does_not_write_invoice():
    app = GatewayFeeScenario(enabled=True, canary_enabled=False, canary_dry_run_only=False, canary_invoice_ids=[11], canary_client_ids=[21])
    app.add_invoice(11, "100.00", "stripe", client_id=21)
    assert app.sync_creation(11) == "canary-disabled"
    assert app.total(11) == money("100.00")
    assert len(app.fee_items(11)) == 0


def test_canary_dry_run_only_blocks_even_allowlisted_invoice_write():
    app = GatewayFeeScenario(enabled=True, canary_enabled=True, canary_dry_run_only=True, canary_invoice_ids=[12], canary_client_ids=[22])
    app.add_invoice(12, "100.00", "stripe", client_id=22)
    assert app.sync_creation(12) == "canary-dry-run-only"
    assert app.total(12) == money("100.00")
    assert len(app.fee_items(12)) == 0


def test_canary_requires_invoice_and_client_allowlists():
    app = GatewayFeeScenario(enabled=True, canary_enabled=True, canary_dry_run_only=False, canary_invoice_ids=[13], canary_client_ids=[])
    app.add_invoice(13, "100.00", "stripe", client_id=23)
    assert app.sync_creation(13) == "canary-allowlist-required"
    assert len(app.fee_items(13)) == 0


def test_canary_allows_only_exact_invoice_client_pair_when_dry_run_disabled():
    app = GatewayFeeScenario(enabled=True, canary_enabled=True, canary_dry_run_only=False, canary_invoice_ids=[14], canary_client_ids=[24])
    app.add_invoice(14, "100.00", "stripe", client_id=24)
    app.add_invoice(15, "100.00", "stripe", client_id=24)
    app.add_invoice(16, "100.00", "stripe", client_id=25)
    assert app.sync_creation(14) == "added"
    assert app.sync_creation(15) == "canary-not-allowlisted"
    assert app.sync_creation(16) == "canary-not-allowlisted"
    assert len(app.fee_items(14)) == 1
    assert len(app.fee_items(15)) == 0
    assert len(app.fee_items(16)) == 0


def test_production_canary_build_always_blocks_automation_scans():
    app = GatewayFeeScenario(enabled=True, canary_enabled=False)
    app.add_invoice(17, "100.00", "stripe", client_id=27)
    assert app.sync_automation() == {"blocked": 1, "scanned": 0}


def test_emergency_kill_switch_blocks_writes_and_automation():
    app = GatewayFeeScenario(enabled=True, canary_enabled=True, canary_dry_run_only=False, canary_invoice_ids=[18], canary_client_ids=[28], emergency_kill_switch=True)
    app.add_invoice(18, "100.00", "stripe", client_id=28)
    assert app.sync_creation(18) == "emergency-killed"
    assert app.sync_automation() == {"blocked": 1, "scanned": 0}
    assert len(app.fee_items(18)) == 0


def run():
    tests = [
        test_stripe_invoice_adds_one_fee,
        test_repeat_sync_does_not_duplicate_fee,
        test_stripealipay_adds_fee,
        test_published_gateway_switch_does_not_mutate_immutable_invoice,
        test_paid_invoice_is_not_modified,
        test_base_excludes_existing_fee,
        test_concurrent_repeat_sync_does_not_duplicate_active_fee,
        test_fee_percent_is_configurable,
        test_invoice_creation_base_uses_line_items_not_unfinalized_total,
        test_invoice_creation_base_excludes_credit_and_paid_amounts,
        test_preautomationtask_after_precronjob_is_not_globally_skipped,
        test_default_config_does_not_write_invoice,
        test_canary_disabled_does_not_write_invoice,
        test_enabled_on_but_canary_disabled_does_not_write_invoice,
        test_canary_dry_run_only_blocks_even_allowlisted_invoice_write,
        test_canary_requires_invoice_and_client_allowlists,
        test_canary_allows_only_exact_invoice_client_pair_when_dry_run_disabled,
        test_production_canary_build_always_blocks_automation_scans,
        test_emergency_kill_switch_blocks_writes_and_automation,
    ]
    for test in tests:
        test()
    print(f"ok - {len(tests)} gateway fee behavior tests passed")


if __name__ == "__main__":
    run()
