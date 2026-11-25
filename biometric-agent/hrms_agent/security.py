"""HMAC-SHA256 request signing and TLS context helpers for agent-to-HRMS HTTPS APIs."""

from __future__ import annotations

import hashlib
import hmac
import secrets
import ssl
import time
from typing import Dict, Optional
from urllib.parse import urlparse


def canonical_string(method: str, path: str, timestamp: str, nonce: str, body: bytes) -> str:
    """Build the exact canonical string verified by Laravel AgentCredentialManager."""
    normalized_path = "/" + path.lstrip("/")
    body_hash = hashlib.sha256(body).hexdigest()
    return "\n".join([
        method.upper(),
        normalized_path,
        timestamp,
        nonce,
        body_hash,
    ])


def sign_request(
    agent_identifier: str,
    access_token: str,
    hmac_secret: str,
    method: str,
    url: str,
    body: bytes = b"",
    timestamp: Optional[int] = None,
    nonce: Optional[str] = None,
) -> Dict[str, str]:
    """Return authentication and HMAC signature headers for one HTTP request."""
    ts = str(int(time.time() if timestamp is None else timestamp))
    req_nonce = nonce or secrets.token_hex(16)
    parsed = urlparse(url)
    path = parsed.path or "/"
    canonical = canonical_string(method, path, ts, req_nonce, body)
    signature = hmac.new(hmac_secret.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256).hexdigest()

    return {
        "Authorization": f"Bearer {access_token}",
        "X-Agent-Id": agent_identifier,
        "X-Agent-Timestamp": ts,
        "X-Agent-Nonce": req_nonce,
        "X-Agent-Signature": signature,
    }


def validate_server_url(server_url: str, allow_insecure_http: bool = False) -> str:
    """Ensure the configured HRMS server URL uses HTTPS unless explicitly overridden for dev."""
    cleaned = server_url.strip().rstrip("/")
    parsed = urlparse(cleaned)
    if parsed.scheme not in ("https", "http") or not parsed.netloc:
        raise ValueError(f"Invalid server_url: {server_url!r}")
    if parsed.scheme != "https" and not allow_insecure_http:
        raise ValueError("HTTPS is required for HRMS communication. Use --allow-insecure-http only for local testing.")
    return cleaned


def build_ssl_context(verify_tls: bool = True, ca_bundle: Optional[str] = None) -> ssl.SSLContext:
    """Build a strict TLS 1.2+ SSL context."""
    if not verify_tls:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        return ctx

    ctx = ssl.create_default_context(cafile=ca_bundle) if ca_bundle else ssl.create_default_context()
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    return ctx
