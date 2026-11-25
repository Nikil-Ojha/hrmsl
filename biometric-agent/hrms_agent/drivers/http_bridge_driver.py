"""Local LAN HTTP/JSON device driver (for Own Brand and HTTP-capable terminals)."""

from __future__ import annotations

import base64
import json
import time
from typing import Any, Dict, List, Optional, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from .base import DeviceConfig, DeviceHealth, DriverResult, HardwareDriver


class HttpBridgeDriver(HardwareDriver):
    """Speaks our vendor-neutral JSON REST contract to a local LAN biometric terminal."""

    name = "http_bridge"
    supported_connections = ("lan", "wifi")

    def __init__(self, timeout_sec: float = 10.0) -> None:
        self.timeout_sec = timeout_sec

    def check_health(self, device: DeviceConfig) -> DeviceHealth:
        started = time.monotonic()
        ok, data, err = self._call(device, "GET", "/v1/health")
        latency_ms = int((time.monotonic() - started) * 1000)
        if not ok:
            return DeviceHealth(online=False, error=err)
        return DeviceHealth(
            online=True,
            latency_ms=latency_ms,
            firmware_version=data.get("firmware_version"),
            user_count=data.get("user_count"),
            log_count=data.get("log_count"),
        )

    def get_info(self, device: DeviceConfig) -> DriverResult:
        ok, data, err = self._call(device, "GET", "/v1/info")
        return DriverResult(ok, "OK" if ok else "ERROR", result=data if ok else {}, error=err)

    def get_users(self, device: DeviceConfig) -> DriverResult:
        ok, data, err = self._call(device, "GET", "/v1/users")
        if not ok:
            return DriverResult(False, "ERROR", error=err)
        users = data.get("users", data if isinstance(data, list) else [])
        return DriverResult(True, "OK", result={"users": users})

    def upsert_user(self, device: DeviceConfig, user: Dict[str, Any]) -> DriverResult:
        ok, data, err = self._call(device, "POST", "/v1/users", body=user)
        return DriverResult(ok, "OK" if ok else "ERROR", result=data if ok else {}, error=err)

    def delete_user(self, device: DeviceConfig, pin: str) -> DriverResult:
        ok, data, err = self._call(device, "DELETE", f"/v1/users/{pin}")
        return DriverResult(ok, "OK" if ok else "ERROR", result=data if ok else {}, error=err)

    def get_logs(self, device: DeviceConfig, since: Optional[str] = None, until: Optional[str] = None) -> Tuple[DriverResult, List[Dict[str, Any]]]:
        params = {}
        if since:
            params["from"] = since
        if until:
            params["to"] = until
        query = f"?{urlencode(params)}" if params else ""
        ok, data, err = self._call(device, "GET", f"/v1/logs{query}")
        if not ok:
            return DriverResult(False, "ERROR", error=err), []
        punches = list(data.get("logs", []))
        return DriverResult(True, "OK", result={"count": len(punches)}), punches

    def clear_logs(self, device: DeviceConfig) -> DriverResult:
        ok, data, err = self._call(device, "POST", "/v1/logs/clear", body={})
        return DriverResult(ok, "OK" if ok else "ERROR", result=data if ok else {}, error=err)

    def sync_time(self, device: DeviceConfig, datetime_iso: str, timezone_name: str) -> DriverResult:
        ok, data, err = self._call(device, "POST", "/v1/time", body={"datetime": datetime_iso, "timezone": timezone_name})
        return DriverResult(ok, "OK" if ok else "ERROR", result=data if ok else {}, error=err)

    def start_enrollment(self, device: DeviceConfig, params: Dict[str, Any]) -> DriverResult:
        ok, data, err = self._call(device, "POST", "/v1/enroll", body=params)
        return DriverResult(ok, "OK" if ok else "ERROR", result=data if ok else {}, error=err)

    def push_template(self, device: DeviceConfig, params: Dict[str, Any]) -> DriverResult:
        ok, data, err = self._call(device, "POST", "/v1/templates", body=params)
        return DriverResult(ok, "OK" if ok else "ERROR", result=data if ok else {}, error=err)

    def restart(self, device: DeviceConfig) -> DriverResult:
        ok, data, err = self._call(device, "POST", "/v1/restart", body={})
        return DriverResult(ok, "OK" if ok else "ERROR", result=data if ok else {}, error=err)

    def _call(self, device: DeviceConfig, method: str, path: str, body: Optional[Dict[str, Any]] = None) -> Tuple[bool, Dict[str, Any], Optional[str]]:
        if not device.ip_address:
            return False, {}, "Device IP address is not configured."

        scheme = "https" if int(device.port or 80) == 443 else "http"
        port = f":{device.port}" if device.port else ""
        url = f"{scheme}://{device.ip_address}{port}{path}"

        raw = json.dumps(body).encode("utf-8") if body is not None else None
        headers = {"Accept": "application/json"}
        if body is not None:
            headers["Content-Type"] = "application/json"

        creds = device.credentials or {}
        if creds.get("token"):
            headers["Authorization"] = f"Bearer {creds['token']}"
        elif creds.get("username") and creds.get("password"):
            basic = base64.b64encode(f"{creds['username']}:{creds['password']}".encode("utf-8")).decode("ascii")
            headers["Authorization"] = f"Basic {basic}"

        req = Request(url=url, data=raw, headers=headers, method=method)
        try:
            with urlopen(req, timeout=self.timeout_sec) as resp:
                text = resp.read().decode("utf-8")
                return True, (json.loads(text) if text else {}), None
        except (HTTPError, URLError, TimeoutError, OSError, json.JSONDecodeError) as exc:
            return False, {}, str(exc)
