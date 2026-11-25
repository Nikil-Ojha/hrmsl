"""Simulated local biometric device driver for development and automated tests."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, Dict, List, Optional, Tuple

from .base import DeviceConfig, DeviceHealth, DriverResult, HardwareDriver


class MockDriver(HardwareDriver):
    name = "mock"
    supported_connections = ("lan", "wifi", "usb")

    def __init__(self) -> None:
        self._state: Dict[int, Dict[str, Any]] = {}

    def _dev(self, device_id: int) -> Dict[str, Any]:
        return self._state.setdefault(device_id, {
            "offline": False,
            "users": {},
            "logs": [],
            "clock": None,
            "restarts": 0,
        })

    def inject_punch(self, device_id: int, employee_code: str, punched_at: Optional[str] = None, punch_state: str = "0") -> None:
        self._dev(device_id)["logs"].append({
            "employee_code": str(employee_code),
            "punched_at": punched_at or datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
            "punch_state": punch_state,
            "verify_mode": "fingerprint",
        })

    def set_offline(self, device_id: int, offline: bool) -> None:
        self._dev(device_id)["offline"] = offline

    def check_health(self, device: DeviceConfig) -> DeviceHealth:
        st = self._dev(device.id)
        if st["offline"]:
            return DeviceHealth(online=False, error="Simulated local device is offline.")
        return DeviceHealth(
            online=True,
            latency_ms=2,
            firmware_version="agent-mock-1.0",
            user_count=len(st["users"]),
            log_count=len(st["logs"]),
        )

    def get_info(self, device: DeviceConfig) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        return DriverResult(True, "OK", result={
            "serial_number": device.serial_number,
            "manufacturer": "Agent Simulator",
            "model": device.model or "LOCAL-MOCK-1",
            "firmware_version": "agent-mock-1.0",
            "user_count": len(st["users"]),
            "log_count": len(st["logs"]),
        })

    def get_users(self, device: DeviceConfig) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        return DriverResult(True, "OK", result={"users": list(st["users"].values())})

    def upsert_user(self, device: DeviceConfig, user: Dict[str, Any]) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        pin = str(user.get("pin") or "")
        st["users"][pin] = {"pin": pin, "name": str(user.get("name") or ""), "card": user.get("card"), "privilege": int(user.get("privilege") or 0)}
        return DriverResult(True, "OK", result={"pin": pin})

    def delete_user(self, device: DeviceConfig, pin: str) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        st["users"].pop(str(pin), None)
        return DriverResult(True, "OK", result={"pin": pin})

    def get_logs(self, device: DeviceConfig, since: Optional[str] = None, until: Optional[str] = None) -> Tuple[DriverResult, List[Dict[str, Any]]]:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline."), []
        logs = [
            log for log in st["logs"]
            if (not since or log["punched_at"] >= since) and (not until or log["punched_at"] <= until)
        ]
        return DriverResult(True, "OK", result={"count": len(logs)}), logs

    def clear_logs(self, device: DeviceConfig) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        count = len(st["logs"])
        st["logs"].clear()
        return DriverResult(True, "OK", result={"cleared": count})

    def sync_time(self, device: DeviceConfig, datetime_iso: str, timezone_name: str) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        st["clock"] = {"datetime": datetime_iso, "timezone": timezone_name}
        return DriverResult(True, "OK", result=st["clock"])

    def restart(self, device: DeviceConfig) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        st["restarts"] += 1
        return DriverResult(True, "OK", result={"restarts": st["restarts"]})

    def start_enrollment(self, device: DeviceConfig, params: Dict[str, Any]) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        pin = str(params.get("pin") or "")
        modality = str(params.get("modality") or "fingerprint")
        slot = int(params.get("slot_index") or 0)
        st["users"].setdefault(pin, {"pin": pin, "name": str(params.get("name") or ""), "privilege": 0})
        return DriverResult(True, "OK", result={
            "captured": True,
            "credential_ref": f"local-mock-{modality}-{pin}-{slot}",
            "quality_score": 96,
        })

    def push_template(self, device: DeviceConfig, params: Dict[str, Any]) -> DriverResult:
        st = self._dev(device.id)
        if st["offline"]:
            return DriverResult(False, "OFFLINE", error="Simulated local device is offline.")
        return DriverResult(True, "OK", result={"stored": True, "slot_index": int(params.get("slot_index") or 0)})
