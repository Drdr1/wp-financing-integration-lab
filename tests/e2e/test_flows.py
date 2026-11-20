"""End-to-end: real WordPress + the plugin, against the mock financing platform."""

from __future__ import annotations

import json
import time
import urllib.error
import urllib.request

from conftest import WP_URL, wp, wp_reconcile, wp_sql
from mock_platform import PHONE, Platform

AMOUNT = 1_500_000  # EGP 15,000.00 in piastres
ITEMS = json.dumps([{"sku": "TV-55", "name": "55 inch TV", "quantity": 1, "unit_price": AMOUNT}])


def approved_with_po(platform: Platform, ref: str) -> str:
    assert wp("verify", ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")["state"] == "approved"
    po = wp("po", ref, f"--items={ITEMS}")
    assert po["state"] == "pending_delivery"
    return str(po["po_id"])


# --- the happy path -------------------------------------------------------------


def test_full_flow_approval_po_delivery(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    done = wp("deliver", order_ref, f"--code={platform.delivery_code(po_id)}")
    assert done["state"] == "delivered"
    assert platform.orders[po_id].status == "delivered"


def test_every_write_carries_an_idempotency_key(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    wp("deliver", order_ref, f"--code={platform.delivery_code(po_id)}")
    writes = [r for r in platform.requests if r["method"] == "POST"]
    assert writes and all(r["headers"].get("Idempotency-Key", "").startswith("finb-") for r in writes)


def test_no_codes_or_phone_numbers_are_stored_or_logged(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    code = platform.delivery_code(po_id)
    wp("deliver", order_ref, "--code=0000" if code != "0000" else "--code=1111")
    wp("deliver", order_ref, f"--code={code}")
    dump = wp_sql("SELECT * FROM wp_finb_orders") + wp_sql("SELECT message, context FROM wp_finb_log")
    assert "482913" not in dump
    assert PHONE not in dump
    assert code not in dump.replace(po_id, "")
    trail = wp("show", order_ref)["log"]
    assert any("*********78" in row["context"] for row in trail)  # masked phone, last 2 digits kept


# --- business rules ---------------------------------------------------------------


def test_invalid_and_expired_approval_codes(platform: Platform, order_ref: str) -> None:
    bad = wp("verify", order_ref, f"--amount={AMOUNT}", "--code=999999", f"--phone={PHONE}")
    assert bad == {"ok": False, "error": "invalid_code", "message": "The approval code is not valid."}
    expired = wp("verify", order_ref + "-2", f"--amount={AMOUNT}", "--code=111111", f"--phone={PHONE}")
    assert expired["error"] == "approval_expired"


def test_wrong_code_can_be_corrected_on_the_same_order(platform: Platform, order_ref: str) -> None:
    assert wp("verify", order_ref, f"--amount={AMOUNT}", "--code=999999", f"--phone={PHONE}")["ok"] is False
    assert wp("verify", order_ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")["state"] == "approved"


def test_limit_below_order_amount_is_refused(platform: Platform, order_ref: str) -> None:
    r = wp("verify", order_ref, f"--amount={AMOUNT}", "--code=222222", f"--phone={PHONE}")
    assert r["error"] == "limit_exceeded"
    assert platform.calls("POST", "/v1/purchase-orders") == 0


def test_items_must_add_up_to_the_order_amount(platform: Platform, order_ref: str) -> None:
    wp("verify", order_ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")
    wrong = json.dumps([{"sku": "TV-55", "name": "TV", "quantity": 1, "unit_price": AMOUNT - 100}])
    assert wp("po", order_ref, f"--items={wrong}")["error"] == "amount_mismatch"
    assert platform.calls("POST", "/v1/purchase-orders") == 0


def test_three_wrong_delivery_codes_lock_the_order_for_review(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    wrong = "0000" if platform.delivery_code(po_id) != "0000" else "1111"
    for _ in (1, 2):
        r = wp("deliver", order_ref, f"--code={wrong}")
        assert r["error"] == "invalid_delivery_code"
    r = wp("deliver", order_ref, f"--code={wrong}")
    assert r["error"] == "delivery_code_locked"
    shown = wp("show", order_ref)
    assert shown["state"] == "needs_review"
    assert shown["delivery_attempts"] == 3
    assert "locked" in shown["review_reason"]


def test_cancel_before_delivery_and_refuse_after(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    assert wp("cancel", order_ref, "--reason=customer changed mind")["state"] == "cancelled"
    assert platform.orders[po_id].status == "cancelled"

    other = order_ref + "-B"
    platform.approvals["482913"].used = False
    po2 = approved_with_po(platform, other)
    wp("deliver", other, f"--code={platform.delivery_code(po2)}")
    refused = wp("cancel", other)
    assert refused["error"] == "wrong_state"
    assert wp("show", other)["state"] == "delivered"


def test_cancel_before_purchase_order_is_local_only(platform: Platform, order_ref: str) -> None:
    wp("verify", order_ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")
    assert wp("cancel", order_ref)["state"] == "cancelled"
    assert platform.calls("POST", "/cancel") == 0


# --- failures: the reason this lab exists ------------------------------------------


def test_lost_response_on_purchase_order_creates_exactly_one_po(platform: Platform, order_ref: str) -> None:
    """The platform creates the PO but the response never arrives. The plugin
    retries with the same Idempotency-Key and gets the same PO back."""
    wp("verify", order_ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")
    platform.fail_next("/v1/purchase-orders", "lost")
    r = wp("po", order_ref, f"--items={ITEMS}")
    assert r["state"] == "pending_delivery"
    assert len(platform.orders) == 1
    assert platform.calls("POST", "/v1/purchase-orders") == 2
    assert r["po_id"] == next(iter(platform.orders))


def test_platform_outage_retries_then_succeeds(platform: Platform, order_ref: str) -> None:
    platform.fail_next("/v1/approvals/verify", "before")
    platform.fail_next("/v1/approvals/verify", "before")
    assert wp("verify", order_ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")["state"] == "approved"
    assert platform.calls("POST", "/v1/approvals/verify") == 3


def test_long_outage_leaves_the_order_unchanged(platform: Platform, order_ref: str) -> None:
    wp("verify", order_ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")
    for _ in range(4):
        platform.fail_next("/v1/purchase-orders", "before")
    r = wp("po", order_ref, f"--items={ITEMS}")
    assert r["ok"] is False and r["error"] == "unavailable"
    assert "Nothing was changed" in r["message"]
    assert wp("show", order_ref)["state"] == "approved"
    assert wp("po", order_ref, f"--items={ITEMS}")["state"] == "pending_delivery"  # retry later works


def test_double_click_on_purchase_order_is_harmless(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    again = wp("po", order_ref, f"--items={ITEMS}")
    assert again["po_id"] == po_id
    assert len(platform.orders) == 1


# --- webhooks ------------------------------------------------------------------


def test_signed_webhook_moves_the_order(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    status, body = platform.send_webhook("purchase_order.cancelled", po_id)
    assert (status, body["outcome"]) == (200, "applied")
    assert wp("show", order_ref)["state"] == "cancelled"


def test_duplicate_webhook_is_acknowledged_once(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    first = platform.send_webhook("purchase_order.expired", po_id, event_id="evt_dup_" + order_ref)
    second = platform.send_webhook("purchase_order.expired", po_id, event_id="evt_dup_" + order_ref)
    assert first == (200, {"received": True, "outcome": "applied"})
    assert second == (200, {"received": True, "outcome": "duplicate"})


def test_unsigned_tampered_or_stale_webhooks_are_rejected(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    assert platform.send_webhook("purchase_order.cancelled", po_id, secret="whsec_" + "y" * 40)[0] == 401
    assert platform.send_webhook("purchase_order.cancelled", po_id, tamper=True)[0] == 401
    assert platform.send_webhook("purchase_order.cancelled", po_id, timestamp=int(time.time()) - 3600)[0] == 401
    assert wp("show", order_ref)["state"] == "pending_delivery"


def test_late_contradicting_webhook_flags_review_and_never_overwrites(platform: Platform, order_ref: str) -> None:
    """Delivered in the store, then a 'cancelled' event arrives: a person must look."""
    po_id = approved_with_po(platform, order_ref)
    wp("deliver", order_ref, f"--code={platform.delivery_code(po_id)}")
    status, body = platform.send_webhook("purchase_order.cancelled", po_id)
    assert (status, body["outcome"]) == (200, "review")
    shown = wp("show", order_ref)
    assert shown["state"] == "delivered"
    assert "Platform says cancelled" in shown["review_reason"]


def test_late_confirmation_of_what_we_already_did_is_ignored(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    wp("deliver", order_ref, f"--code={platform.delivery_code(po_id)}")
    status, body = platform.send_webhook("purchase_order.delivered", po_id)
    assert (status, body["outcome"]) == (200, "ignored")


def test_missed_webhook_is_caught_by_reconciliation(platform: Platform, order_ref: str) -> None:
    po_id = approved_with_po(platform, order_ref)
    platform.set_status(po_id, "cancelled")  # customer cancelled in the app; webhook never arrived
    out = wp_reconcile()
    assert out[po_id] == "applied"
    assert wp("show", order_ref)["state"] == "cancelled"


# --- the staff REST API --------------------------------------------------------


def test_staff_api_requires_authentication(platform: Platform, order_ref: str) -> None:
    req = urllib.request.Request(
        f"{WP_URL}/?rest_route=/financing/v1/orders/{order_ref}/cancel",
        data=b"{}",
        method="POST",
        headers={"Content-Type": "application/json"},
    )
    try:
        urllib.request.urlopen(req, timeout=30)
        raise AssertionError("anonymous request was accepted")
    except urllib.error.HTTPError as e:
        assert e.code == 401


def test_retry_after_failed_call_reuses_the_key_and_the_po(platform: Platform, order_ref: str) -> None:
    """Every attempt reached the platform but every response was lost. A later
    retry (a new request, minutes later) must still map to the same PO."""
    wp("verify", order_ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")
    for _ in range(4):
        platform.fail_next("/v1/purchase-orders", "lost")
    assert wp("po", order_ref, f"--items={ITEMS}")["ok"] is False
    assert len(platform.orders) == 1  # the platform did create it
    later = wp("po", order_ref, f"--items={ITEMS}")
    assert later["state"] == "pending_delivery"
    assert later["po_id"] == next(iter(platform.orders))
    assert len(platform.orders) == 1


def test_malformed_items_are_refused_before_any_call(platform: Platform, order_ref: str) -> None:
    wp("verify", order_ref, f"--amount={AMOUNT}", "--code=482913", f"--phone={PHONE}")
    bad = json.dumps([{"sku": "TV-55", "name": "TV", "quantity": "1", "unit_price": AMOUNT}])
    assert wp("po", order_ref, f"--items={bad}")["error"] == "invalid_items"
    assert platform.calls("POST", "/v1/purchase-orders") == 0


def test_staff_api_full_flow_with_application_password(platform: Platform, order_ref: str) -> None:
    import base64
    import subprocess

    from conftest import WP_PATH

    pw = subprocess.run(
        [
            "wp",
            f"--path={WP_PATH}",
            "--allow-root",
            "user",
            "application-password",
            "create",
            "admin",
            "e2e-" + order_ref,
            "--porcelain",
        ],
        capture_output=True,
        text=True,
        check=True,
    ).stdout.strip()
    auth = "Basic " + base64.b64encode(f"admin:{pw}".encode()).decode()

    def call(method: str, path: str, payload: dict | None = None) -> tuple[int, dict]:
        req = urllib.request.Request(
            f"{WP_URL}/?rest_route=/financing/v1/orders/{order_ref}{path}",
            data=json.dumps(payload).encode() if payload is not None else None,
            method=method,
            headers={"Content-Type": "application/json", "Authorization": auth},
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.status, json.loads(r.read())
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read())

    assert call("POST", "/verify", {"amount": AMOUNT, "approval_code": "000000", "customer_phone": PHONE}) == (
        422,
        {"error": "invalid_code", "message": "The approval code is not valid."},
    )
    status, body = call("POST", "/verify", {"amount": AMOUNT, "approval_code": "482913", "customer_phone": PHONE})
    assert (status, body["state"]) == (200, "approved")
    status, body = call("POST", "/purchase-order", {"items": json.loads(ITEMS)})
    assert (status, body["state"]) == (200, "pending_delivery")
    status, body = call("POST", "/deliver", {"delivery_code": platform.delivery_code(body["po_id"])})
    assert (status, body["state"]) == (200, "delivered")
    assert call("POST", "/cancel", {})[0] == 409
    assert call("GET", "")[1]["state"] == "delivered"
