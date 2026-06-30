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
    def __init__(self, fee_percent="3.00", gateways=None):
        self.fee_percent = Decimal(str(fee_percent))
        self.gateways = set(gateways or ["stripe", "stripealipay"])
        self.invoices = {}
        self.active = {}
        self.audit = []
        self.next_item_id = 1
        self.lock = Lock()

    def add_invoice(self, invoice_id, total, gateway, status="Unpaid", credit="0.00", paid="0.00"):
        self.invoices[invoice_id] = {
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

    def sync_creation(self, invoice_id):
        with self.lock:
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
                invoice["items"] = [item for item in invoice["items"] if item["id"] != active["invoice_item_id"]]
                active["status"] = "removed"
                del self.active[invoice_id]

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


def test_stripe_invoice_adds_one_fee():
    app = GatewayFeeScenario()
    app.add_invoice(1, "100.00", "stripe")
    assert app.sync_creation(1) == "added"
    assert app.total(1) == money("103.00")
    assert len(app.fee_items(1)) == 1


def test_repeat_sync_does_not_duplicate_fee():
    app = GatewayFeeScenario()
    app.add_invoice(1, "100.00", "stripe")
    assert app.sync_creation(1) == "added"
    assert app.sync_creation(1) == "noop"
    assert app.total(1) == money("103.00")
    assert len(app.fee_items(1)) == 1


def test_stripealipay_adds_fee():
    app = GatewayFeeScenario()
    app.add_invoice(2, "100.00", "stripealipay")
    assert app.sync_creation(2) == "added"
    assert app.total(2) == money("103.00")


def test_published_gateway_switch_does_not_mutate_immutable_invoice():
    app = GatewayFeeScenario()
    app.add_invoice(3, "100.00", "stripe")
    assert app.sync_creation(3) == "added"
    app.set_gateway(3, "banktransfer")
    assert app.sync_published(3) == "unsupported-immutable-remove"
    assert app.total(3) == money("103.00")
    assert len(app.fee_items(3)) == 1


def test_paid_invoice_is_not_modified():
    app = GatewayFeeScenario()
    app.add_invoice(4, "100.00", "stripe", status="Paid")
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
    app = GatewayFeeScenario()
    app.add_invoice(5, "100.00", "stripe")
    assert app.sync_creation(5) == "added"
    active = app.active[5]
    assert active["base_amount"] == money("100.00")
    assert active["fee_amount"] == money("3.00")
    assert app.sync_creation(5) == "noop"
    assert app.active[5]["base_amount"] == money("100.00")


def test_concurrent_repeat_sync_does_not_duplicate_active_fee():
    app = GatewayFeeScenario()
    app.add_invoice(6, "100.00", "stripe")
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
    app = GatewayFeeScenario(fee_percent="2.50")
    app.add_invoice(7, "100.00", "stripe")
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
    app = GatewayFeeScenario()
    app.add_invoice(8, "100.00", "stripe", credit="20.00")
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
    ]
    for test in tests:
        test()
    print(f"ok - {len(tests)} gateway fee behavior tests passed")


if __name__ == "__main__":
    run()
