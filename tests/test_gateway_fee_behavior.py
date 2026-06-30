#!/usr/bin/env python3
from decimal import Decimal, ROUND_HALF_UP
from threading import Lock, Thread


def money(value):
    return Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


class GatewayFeeScenario:
    def __init__(self, fee_percent="3.00", gateways=None):
        self.fee_percent = Decimal(str(fee_percent))
        self.gateways = set(gateways or ["stripe", "stripealipay"])
        self.invoices = {}
        self.active = {}
        self.audit = []
        self.next_item_id = 1
        self.lock = Lock()

    def add_invoice(self, invoice_id, total, gateway, status="Unpaid"):
        self.invoices[invoice_id] = {
            "status": status,
            "gateway": gateway,
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

    def sync(self, invoice_id):
        with self.lock:
            invoice = self.invoices[invoice_id]
            active = self.active.get(invoice_id)
            if invoice["status"] != "Unpaid":
                return "skipped"

            applicable = invoice["gateway"] in self.gateways

            if not applicable:
                if active:
                    invoice["items"] = [item for item in invoice["items"] if item["id"] != active["invoice_item_id"]]
                    active["status"] = "removed"
                    del self.active[invoice_id]
                    return "removed"
                return "skipped"

            base = self.total(invoice_id)
            if active:
                fee_item = next(item for item in invoice["items"] if item["id"] == active["invoice_item_id"])
                base -= fee_item["amount"]

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


def test_stripe_invoice_adds_one_fee():
    app = GatewayFeeScenario()
    app.add_invoice(1, "100.00", "stripe")
    assert app.sync(1) == "added"
    assert app.total(1) == money("103.00")
    assert len(app.fee_items(1)) == 1


def test_repeat_sync_does_not_duplicate_fee():
    app = GatewayFeeScenario()
    app.add_invoice(1, "100.00", "stripe")
    assert app.sync(1) == "added"
    assert app.sync(1) == "noop"
    assert app.total(1) == money("103.00")
    assert len(app.fee_items(1)) == 1


def test_stripealipay_adds_fee():
    app = GatewayFeeScenario()
    app.add_invoice(2, "100.00", "stripealipay")
    assert app.sync(2) == "added"
    assert app.total(2) == money("103.00")


def test_switch_to_non_stripe_removes_fee():
    app = GatewayFeeScenario()
    app.add_invoice(3, "100.00", "stripe")
    assert app.sync(3) == "added"
    app.set_gateway(3, "banktransfer")
    assert app.sync(3) == "removed"
    assert app.total(3) == money("100.00")
    assert len(app.fee_items(3)) == 0


def test_paid_invoice_is_not_modified():
    app = GatewayFeeScenario()
    app.add_invoice(4, "100.00", "stripe", status="Paid")
    assert app.sync(4) == "skipped"
    assert app.total(4) == money("100.00")
    app.set_status(4, "Unpaid")
    assert app.sync(4) == "added"
    app.set_status(4, "Paid")
    app.set_gateway(4, "banktransfer")
    assert app.sync(4) == "skipped"
    assert app.total(4) == money("103.00")
    assert len(app.fee_items(4)) == 1


def test_base_excludes_existing_fee():
    app = GatewayFeeScenario()
    app.add_invoice(5, "100.00", "stripe")
    assert app.sync(5) == "added"
    active = app.active[5]
    assert active["base_amount"] == money("100.00")
    assert active["fee_amount"] == money("3.00")
    assert app.sync(5) == "noop"
    assert app.active[5]["base_amount"] == money("100.00")


def test_concurrent_repeat_sync_does_not_duplicate_active_fee():
    app = GatewayFeeScenario()
    app.add_invoice(6, "100.00", "stripe")
    results = []
    threads = [Thread(target=lambda: results.append(app.sync(6))) for _ in range(12)]
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
    assert app.sync(7) == "added"
    assert app.total(7) == money("102.50")
    assert app.active[7]["fee_amount"] == money("2.50")


def run():
    tests = [
        test_stripe_invoice_adds_one_fee,
        test_repeat_sync_does_not_duplicate_fee,
        test_stripealipay_adds_fee,
        test_switch_to_non_stripe_removes_fee,
        test_paid_invoice_is_not_modified,
        test_base_excludes_existing_fee,
        test_concurrent_repeat_sync_does_not_duplicate_active_fee,
        test_fee_percent_is_configurable,
    ]
    for test in tests:
        test()
    print(f"ok - {len(tests)} gateway fee behavior tests passed")


if __name__ == "__main__":
    run()
