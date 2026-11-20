from __future__ import annotations

import json
import os
import subprocess
import time
import urllib.request
import uuid
from collections.abc import Iterator
from typing import Any

import pytest
from mock_platform import Platform

WP_PATH = os.environ.get("WP_PATH", "/tmp/finb-wp")
WP_URL = os.environ.get("WP_URL", "http://127.0.0.1:8080")
MOCK_PORT = int(os.environ.get("MOCK_PORT", "8099"))


@pytest.fixture(scope="session")
def wp_server() -> Iterator[str]:
    """WordPress served by PHP's built-in server (multiple workers for webhook + API traffic)."""
    host_port = WP_URL.split("//", 1)[1]
    env = {**os.environ, "PHP_CLI_SERVER_WORKERS": "4"}
    proc = subprocess.Popen(
        ["php", "-S", host_port, "-t", WP_PATH], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env
    )
    for _ in range(50):
        try:
            urllib.request.urlopen(WP_URL + "/?rest_route=/", timeout=2)
            break
        except Exception:
            time.sleep(0.2)
    else:
        proc.kill()
        raise RuntimeError("WordPress did not start")
    yield WP_URL
    proc.terminate()


@pytest.fixture(scope="session")
def _platform(wp_server: str) -> Iterator[Platform]:
    p = Platform(MOCK_PORT)
    p.webhook_url = wp_server + "/?rest_route=/financing/v1/webhook"
    p.start()
    yield p
    p.stop()


@pytest.fixture
def platform(_platform: Platform) -> Platform:
    _platform.reset()
    return _platform


@pytest.fixture
def order_ref() -> str:
    return "ORD-" + uuid.uuid4().hex[:10].upper()


def wp(*args: str) -> dict[str, Any]:
    """Run `wp financing ...` and return its JSON output (ok or error)."""
    out = subprocess.run(
        ["wp", f"--path={WP_PATH}", "--allow-root", "financing", *args],
        capture_output=True,
        text=True,
        timeout=120,
    )
    lines = [line for line in out.stdout.splitlines() if line.startswith("{")]
    if not lines:
        raise AssertionError(f"no JSON from wp {args}: {out.stdout} {out.stderr}")
    return json.loads(lines[-1])


def wp_reconcile() -> dict[str, str]:
    out = subprocess.run(
        ["wp", f"--path={WP_PATH}", "--allow-root", "financing", "reconcile"],
        capture_output=True,
        text=True,
        timeout=120,
        check=True,
    )
    result: dict[str, str] = json.loads(out.stdout.strip().splitlines()[-1])
    return result


def wp_sql(query: str) -> str:
    out = subprocess.run(
        ["wp", f"--path={WP_PATH}", "--allow-root", "db", "query", query, "--skip-column-names"],
        capture_output=True,
        text=True,
        timeout=60,
        check=True,
    )
    return out.stdout
