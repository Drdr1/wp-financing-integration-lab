"""A stand-in for the financing platform's API, with the awkward parts on purpose.

It behaves like a real consumer-financing API as far as the plugin can see:
approval codes with limits and expiry, purchase orders, delivery codes sent
"by SMS" (readable here through a test hook), cancellations, idempotency keys,
and HMAC-signed webhooks back to WordPress. It can also misbehave on request:
fail before processing, process and then lose the response, or deliver the
same webhook twice or out of order.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import secrets
import threading
import time
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

API_KEY = "test-api-key-not-a-real-secret"
WEBHOOK_SECRET = "whsec_" + "x" * 40

PHONE = "01012345678"


@dataclass
class Approval:
    code: str
    phone: str
    limit: int
    currency: str = "EGP"
    expired: bool = False
    approval_id: str = field(default_factory=lambda: "apr_" + secrets.token_hex(6))
    used: bool = False


@dataclass
class PurchaseOrder:
    po_id: str
    approval_id: str
    merchant_order_ref: str
    amount: int
    status: str = "pending_delivery"
    delivery_code: str = field(default_factory=lambda: f"{secrets.randbelow(10000):04d}")
    attempts: int = 0


class Platform:
    def __init__(self, port: int) -> None:
        self.port = port
        self.webhook_url = ""
        self.reset()
        self._server = ThreadingHTTPServer(("127.0.0.1", port), self._handler())
        self._thread = threading.Thread(target=self._server.serve_forever, daemon=True)

    # --- test controls -------------------------------------------------------

    def reset(self) -> None:
        self.approvals: dict[str, Approval] = {
            "482913": Approval("482913", PHONE, limit=2_000_000),
            "111111": Approval("111111", PHONE, limit=2_000_000, expired=True),
            "222222": Approval("222222", PHONE, limit=300_000),
        }
        self.orders: dict[str, PurchaseOrder] = {}
        self.idempotency: dict[str, tuple[str, int, dict[str, Any]]] = {}
        self.requests: list[dict[str, Any]] = []
        self.faults: list[tuple[str, str]] = []

    def fail_next(self, path_contains: str, mode: str) -> None:
        """mode 'before': 503 without processing. 'lost': process, then answer 503."""
        self.faults.append((path_contains, mode))

    def delivery_code(self, po_id: str) -> str:
        return self.orders[po_id].delivery_code

    def set_status(self, po_id: str, status: str) -> None:
        """A change made on the platform side (customer cancelled in the app, expiry, ...)."""
        self.orders[po_id].status = status

    def calls(self, method: str, path_contains: str) -> int:
        return sum(1 for r in self.requests if r["method"] == method and path_contains in r["path"])

    def send_webhook(
        self,
        event_type: str,
        po_id: str,
        *,
        event_id: str | None = None,
        timestamp: int | None = None,
        secret: str = WEBHOOK_SECRET,
        tamper: bool = False,
    ) -> tuple[int, dict[str, Any]]:
        event_id = event_id or "evt_" + secrets.token_hex(8)
        body = json.dumps({"id": event_id, "type": event_type, "created": int(time.time()), "data": {"po_id": po_id}})
        ts = str(timestamp if timestamp is not None else int(time.time()))
        sig = "sha256=" + hmac.new(secret.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
        if tamper:
            body = body.replace(po_id, po_id + "x")
        req = urllib.request.Request(
            self.webhook_url,
            data=body.encode(),
            method="POST",
            headers={
                "Content-Type": "application/json",
                "X-Fin-Event-Id": event_id,
                "X-Fin-Timestamp": ts,
                "X-Fin-Signature": sig,
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return resp.status, json.loads(resp.read() or b"{}")
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read() or b"{}")

    def start(self) -> None:
        self._thread.start()

    def stop(self) -> None:
        self._server.shutdown()

    # --- API -----------------------------------------------------------------

    def _handler(self) -> type[BaseHTTPRequestHandler]:
        platform = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args: Any) -> None:
                pass

            def _send(self, status: int, payload: dict[str, Any]) -> None:
                data = json.dumps(payload).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("X-Request-Id", "req_" + secrets.token_hex(4))
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)

            def _handle(self, method: str) -> None:
                length = int(self.headers.get("Content-Length") or 0)
                raw = self.rfile.read(length) if length else b""
                body = json.loads(raw or b"{}")
                platform.requests.append({"method": method, "path": self.path, "headers": dict(self.headers), "body": body})

                if self.headers.get("Authorization") != f"Bearer {API_KEY}":
                    return self._send(401, {"error": {"code": "unauthorized", "message": "bad api key"}})

                fault = next((f for f in platform.faults if f[0] in self.path), None)
                if fault:
                    platform.faults.remove(fault)
                    if fault[1] == "before":
                        return self._send(503, {"error": {"code": "unavailable", "message": "try again"}})

                key = self.headers.get("Idempotency-Key")
                body_hash = hashlib.sha256(raw).hexdigest()
                if method == "POST" and key and key in platform.idempotency:
                    stored_hash, status, payload = platform.idempotency[key]
                    if stored_hash != body_hash:
                        return self._send(422, {"error": {"code": "idempotency_mismatch", "message": "key reused"}})
                    if fault and fault[1] == "lost":
                        return self._send(503, {"error": {"code": "unavailable", "message": "response lost"}})
                    return self._send(status, payload)

                status, payload = platform._route(method, self.path, body)
                if method == "POST" and key and status < 500:
                    platform.idempotency[key] = (body_hash, status, payload)
                if fault and fault[1] == "lost":
                    return self._send(503, {"error": {"code": "unavailable", "message": "response lost"}})
                return self._send(status, payload)

            def do_POST(self) -> None:
                self._handle("POST")

            def do_GET(self) -> None:
                self._handle("GET")

        return Handler

    def _route(self, method: str, path: str, body: dict[str, Any]) -> tuple[int, dict[str, Any]]:
        def err(status: int, code: str) -> tuple[int, dict[str, Any]]:
            return status, {"error": {"code": code, "message": code.replace("_", " ")}}

        parts = path.strip("/").split("/")
        if method == "POST" and path == "/v1/approvals/verify":
            a = self.approvals.get(body.get("approval_code", ""))
            if a is None:
                return err(404, "invalid_code")
            if a.phone != body.get("customer_phone"):
                return err(422, "phone_mismatch")
            if a.expired:
                return err(422, "approval_expired")
            if a.used:
                return err(409, "approval_used")
            return 200, {
                "approval_id": a.approval_id,
                "status": "approved",
                "available_limit": a.limit,
                "currency": a.currency,
                "expires_at": "2026-12-31T23:59:59Z",
                "customer_ref": "cus_" + hashlib.sha256(a.phone.encode()).hexdigest()[:10],
            }

        if method == "POST" and path == "/v1/purchase-orders":
            a = next((x for x in self.approvals.values() if x.approval_id == body.get("approval_id")), None)
            if a is None:
                return err(404, "unknown_approval")
            if a.used:
                return err(409, "approval_used")
            if body["amount"] > a.limit:
                return err(422, "limit_exceeded")
            if sum(i["quantity"] * i["unit_price"] for i in body["items"]) != body["amount"]:
                return err(422, "amount_mismatch")
            a.used = True
            po = PurchaseOrder("po_" + secrets.token_hex(6), a.approval_id, body["merchant_order_ref"], body["amount"])
            self.orders[po.po_id] = po
            return 201, {"po_id": po.po_id, "status": po.status}

        if len(parts) >= 3 and parts[:2] == ["v1", "purchase-orders"]:
            po = self.orders.get(parts[2])
            if po is None:
                return err(404, "unknown_purchase_order")
            if method == "GET" and len(parts) == 3:
                return 200, {"po_id": po.po_id, "status": po.status}
            action = parts[3] if len(parts) > 3 else ""
            if method == "POST" and action == "deliver":
                if po.status == "cancelled":
                    return err(409, "already_cancelled")
                if po.status == "delivered":
                    return err(409, "already_delivered")
                if po.attempts >= 3:
                    return err(423, "delivery_code_locked")
                if body.get("delivery_code") != po.delivery_code:
                    po.attempts += 1
                    return err(423, "delivery_code_locked") if po.attempts >= 3 else err(422, "invalid_delivery_code")
                po.status = "delivered"
                return 200, {"po_id": po.po_id, "status": po.status}
            if method == "POST" and action == "cancel":
                if po.status == "delivered":
                    return err(409, "already_delivered")
                po.status = "cancelled"
                return 200, {"po_id": po.po_id, "status": po.status}
        return err(404, "not_found")
