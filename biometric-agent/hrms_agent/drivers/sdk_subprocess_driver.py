"""
Out-of-process hardware SDK bridge (JSON over stdin/stdout).

Allows proprietary vendor SDKs (e.g. 32-bit Windows COM DLLs such as
zkemkeeper.dll, Suprema BioStar SDK, Hikvision SDK, or custom C++/C# bridges)
to integrate independently without modifying or crashing the core agent.

Contract:
  The agent launches `sdk_command` with one JSON object on stdin:
    {"action": "check_health|get_info|get_users|upsert_user|delete_user|get_logs|clear_logs|sync_time|restart",
     "device": {...}, "params": {...}}
  The SDK executable writes one JSON object on stdout and exits 0:
    {"success": true, "return_code": "OK", "result": {...}, "logs": [...], "health": {...}, "error": null}
"""

from __future__ import annotations

from dataclasses import asdict
import json
import shlex
import subprocess
from typing import Any, Dict, List, Optional, Tuple

from .base import DeviceConfig, DeviceHealth, DriverResult, HardwareDriver


class SdkSubprocessDriver(HardwareDriver):
    name = "sdk_subprocess"
    supported_connections = ("lan", "wifi", "usb")

    def __init__(self, timeout_sec: int = 30) -> None:
        self.timeout_sec = timeout_sec

    def check_health(self, device: DeviceConfig) -> DeviceHealth:
        res, raw = self._invoke(device, "check_health", {})
        if not res.success:
            return DeviceHealth(online=False, error=res.error)
        h = raw.get("health") or res.result
        return DeviceHealth(
            online=bool(h.get("online", True)),
            latency_ms=h.get("latency_ms"),
            firmware_version=h.get("firmware_version"),
            user_count=h.get("user_count"),
            log_count=h.get("log_count"),
            error=h.get("error"),
        )

    def get_info(self, device: DeviceConfig) -> DriverResult:
        return self._invoke(device, "get_info", {})[0]

    def get_users(self, device: DeviceConfig) -> DriverResult:
        return self._invoke(device, "get_users", {})[0]

    def upsert_user(self, device: DeviceConfig, user: Dict[str, Any]) -> DriverResult:
        return self._invoke(device, "upsert_user", {"user": user})[0]

    def delete_user(self, device: DeviceConfig, pin: str) -> DriverResult:
        return self._invoke(device, "delete_user", {"pin": pin})[0]

    def get_logs(self, device: DeviceConfig, since: Optional[str] = None, until: Optional[str] = None) -> Tuple[DriverResult, List[Dict[str, Any]]]:
        res, raw = self._invoke(device, "get_logs", {"from": since, "to": until})
        return res, list(raw.get("logs", [])) if res.success else []

    def clear_logs(self, device: DeviceConfig) -> DriverResult:
        return self._invoke(device, "clear_logs", {})[0]

    def sync_time(self, device: DeviceConfig, datetime_iso: str, timezone_name: str) -> DriverResult:
        return self._invoke(device, "sync_time", {"datetime": datetime_iso, "timezone": timezone_name})[0]

    def start_enrollment(self, device: DeviceConfig, params: Dict[str, Any]) -> DriverResult:
        return self._invoke(device, "start_enrollment", params)[0]

    def push_template(self, device: DeviceConfig, params: Dict[str, Any]) -> DriverResult:
        return self._invoke(device, "push_template", params)[0]

    def restart(self, device: DeviceConfig) -> DriverResult:
        return self._invoke(device, "restart", {})[0]

    def _invoke(self, device: DeviceConfig, action: str, params: Dict[str, Any]) -> Tuple[DriverResult, Dict[str, Any]]:
        cmd_str = (device.credentials or {}).get("sdk_command") or (device.settings or {}).get("sdk_command")
        if not cmd_str:
            return DriverResult(False, "NO_SDK", error="sdk_command is not configured for sdk_subprocess driver."), {}

        request = json.dumps({"action": action, "device": asdict(device), "params": params})
        try:
            proc = subprocess.run(
                shlex.split(str(cmd_str)),
                input=request,
                capture_output=True,
                text=True,
                timeout=self.timeout_sec,
                check=False,
            )
        except (OSError, subprocess.SubprocessError) as exc:
            return DriverResult(False, "EXEC_ERROR", error=f"Failed to execute SDK bridge: {exc}"), {}

        if proc.returncode != 0 and not proc.stdout.strip():
            return DriverResult(False, f"EXIT_{proc.returncode}", error=(proc.stderr or f"SDK exited with code {proc.returncode}").strip()[:1000]), {}

        try:
            data = json.loads(proc.stdout.strip())
        except json.JSONDecodeError as exc:
            return DriverResult(False, "BAD_JSON", error=f"SDK bridge returned invalid JSON: {exc}"), {}

        return DriverResult(
            success=bool(data.get("success", False)),
            return_code=str(data.get("return_code") or ("OK" if data.get("success") else "ERROR")),
            result=dict(data.get("result") or {}),
            error=data.get("error"),
        ), data
