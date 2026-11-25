"""HTTPS client for the HRMS Local Biometric Agent API (v1)."""

from __future__ import annotations

import json
import platform
import socket
from typing import Any, Dict, List, Optional
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from . import __version__
from .config import AgentConfig, AgentCredentials
from .security import build_ssl_context, sign_request


class ApiError(RuntimeError):
    def __init__(self, message: str, status_code: Optional[int] = None, transient: bool = False) -> None:
        super().__init__(message)
        self.status_code = status_code
        self.transient = transient


class HrmsApiClient:
    """Thin, zero-dependency HTTPS client with HMAC-SHA256 request signing."""

    PREFIX = "/api/biometric/agent/v1"

    def __init__(self, config: AgentConfig, credentials: Optional[AgentCredentials] = None) -> None:
        self.config = config
        self.credentials = credentials
        self._ssl_ctx = build_ssl_context(config.verify_tls, config.ca_bundle)

    def provision(self, agent_identifier: str, provisioning_token: str, capabilities: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        payload = {
            "agent_identifier": agent_identifier,
            "provisioning_token": provisioning_token,
            "agent_version": __version__,
            "os_info": f"{platform.system()} {platform.release()} ({platform.machine()})",
            "hostname": socket.gethostname(),
            "capabilities": capabilities or {},
        }
        return self._request("POST", f"{self.PREFIX}/provision", body=payload, signed=False)

    def fetch_config(self) -> Dict[str, Any]:
        return self._request("GET", f"{self.PREFIX}/config")

    def send_heartbeat(
        self,
        local_queue_depth: int,
        local_error_count: int,
        device_health: List[Dict[str, Any]],
        capabilities: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        payload = {
            "agent_version": __version__,
            "os_info": f"{platform.system()} {platform.release()} ({platform.machine()})",
            "hostname": socket.gethostname(),
            "local_queue_depth": local_queue_depth,
            "local_error_count": local_error_count,
            "capabilities": capabilities or {},
            "devices": device_health,
        }
        return self._request("POST", f"{self.PREFIX}/heartbeat", body=payload)

    def report_discovery(self, devices: List[Dict[str, Any]]) -> Dict[str, Any]:
        return self._request("POST", f"{self.PREFIX}/discovery", body={"devices": devices})

    def upload_logs(self, logs: List[Dict[str, Any]], idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        extra = {"X-Idempotency-Key": idempotency_key} if idempotency_key else None
        return self._request("POST", f"{self.PREFIX}/logs", body={"logs": logs}, extra_headers=extra)

    def fetch_employees(self, device_id: Optional[int] = None, since: Optional[str] = None) -> Dict[str, Any]:
        params = {}
        if device_id is not None:
            params["device_id"] = str(device_id)
        if since:
            params["since"] = since
        query = f"?{urlencode(params)}" if params else ""
        return self._request("GET", f"{self.PREFIX}/employees{query}")

    def poll_commands(self, limit: int = 20) -> List[Dict[str, Any]]:
        resp = self._request("GET", f"{self.PREFIX}/commands?limit={limit}")
        return list(resp.get("data", {}).get("commands", []))

    def acknowledge_command(
        self,
        command_id: int,
        success: bool,
        return_code: Optional[str] = None,
        result: Optional[Dict[str, Any]] = None,
        error: Optional[str] = None,
    ) -> Dict[str, Any]:
        payload: Dict[str, Any] = {"success": bool(success)}
        if return_code is not None:
            payload["return_code"] = str(return_code)
        if result is not None:
            payload["result"] = result
        if error is not None:
            payload["error"] = error[:2000]
        return self._request("POST", f"{self.PREFIX}/commands/{command_id}/ack", body=payload)

    def report_errors(self, errors: List[Dict[str, Any]]) -> Dict[str, Any]:
        return self._request("POST", f"{self.PREFIX}/errors", body={"errors": errors})

    # ------------------------------------------------------------------

    def _request(self, method: str, path: str, body: Optional[Dict[str, Any]] = None, signed: bool = True, extra_headers: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
        url = f"{self.config.server_url}{path}"
        raw_body = json.dumps(body, separators=(",", ":")).encode("utf-8") if body is not None else b""

        headers = {
            "Accept": "application/json",
            "User-Agent": f"HRMS-Biometric-Agent/{__version__}",
        }
        if body is not None:
            headers["Content-Type"] = "application/json"
        if extra_headers:
            headers.update(extra_headers)

        if signed:
            if not self.credentials:
                raise ApiError("Agent is not provisioned. Run `provision` first.", transient=False)
            headers.update(
                sign_request(
                    agent_identifier=self.credentials.agent_identifier,
                    access_token=self.credentials.access_token,
                    hmac_secret=self.credentials.hmac_secret,
                    method=method,
                    url=url,
                    body=raw_body,
                )
            )

        req = Request(url=url, data=raw_body if method != "GET" else None, headers=headers, method=method)

        try:
            with urlopen(req, timeout=self.config.request_timeout_sec, context=self._ssl_ctx) as resp:
                data = resp.read().decode("utf-8")
                return json.loads(data) if data else {}
        except HTTPError as exc:
            raw_err = exc.read().decode("utf-8", errors="replace")
            msg = raw_err
            try:
                parsed = json.loads(raw_err)
                msg = parsed.get("message") or raw_err
            except Exception:
                pass
            transient = exc.code in (408, 429) or exc.code >= 500
            raise ApiError(f"HTTP {exc.code}: {msg}", status_code=exc.code, transient=transient) from exc
        except (URLError, TimeoutError, OSError) as exc:
            raise ApiError(f"Network error contacting HRMS: {exc}", status_code=None, transient=True) from exc
