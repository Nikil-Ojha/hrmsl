"""Vendor-independent hardware driver interface for the Local Agent."""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Tuple


@dataclass
class DeviceConfig:
    id: int
    name: str
    serial_number: str
    device_identifier: str
    protocol: str
    connection_type: str
    ip_address: Optional[str] = None
    port: Optional[int] = None
    timezone: str = "UTC"
    auth_type: str = "none"
    credentials: Dict[str, Any] = field(default_factory=dict)
    settings: Dict[str, Any] = field(default_factory=dict)
    manufacturer: Optional[str] = None
    model: Optional[str] = None

    @classmethod
    def from_api(cls, data: Dict[str, Any]) -> "DeviceConfig":
        return cls(
            id=int(data["id"]),
            name=str(data.get("name", "")),
            serial_number=str(data.get("serial_number", "")),
            device_identifier=str(data.get("device_identifier", "")),
            protocol=str(data.get("protocol", "")),
            connection_type=str(data.get("connection_type", "lan")),
            ip_address=data.get("ip_address"),
            port=int(data["port"]) if data.get("port") is not None else None,
            timezone=str(data.get("timezone") or "UTC"),
            auth_type=str(data.get("auth_type") or "none"),
            credentials=dict(data.get("credentials") or {}),
            settings=dict(data.get("settings") or {}),
            manufacturer=data.get("manufacturer"),
            model=data.get("model"),
        )


@dataclass
class DeviceHealth:
    online: bool
    latency_ms: Optional[int] = None
    firmware_version: Optional[str] = None
    user_count: Optional[int] = None
    log_count: Optional[int] = None
    error: Optional[str] = None

    def to_heartbeat_dict(self, device: DeviceConfig) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            "device_id": device.id,
            "device_serial": device.serial_number,
            "online": self.online,
        }
        if self.latency_ms is not None:
            payload["latency_ms"] = self.latency_ms
        if self.firmware_version:
            payload["firmware_version"] = self.firmware_version
        if self.user_count is not None:
            payload["user_count"] = self.user_count
        if self.log_count is not None:
            payload["log_count"] = self.log_count
        if self.error:
            payload["error"] = self.error[:1000]
        return payload


@dataclass
class DriverResult:
    success: bool
    return_code: Optional[str] = None
    result: Dict[str, Any] = field(default_factory=dict)
    error: Optional[str] = None


class HardwareDriver(ABC):
    """Contract implemented by built-in drivers and external hardware SDK plugins."""

    name: str = "base"
    supported_connections: Tuple[str, ...] = ("lan", "wifi", "usb")

    @abstractmethod
    def check_health(self, device: DeviceConfig) -> DeviceHealth:
        """Probe connectivity and return local device health."""

    def get_info(self, device: DeviceConfig) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support get_info.")

    def get_users(self, device: DeviceConfig) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support get_users.")

    def upsert_user(self, device: DeviceConfig, user: Dict[str, Any]) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support upsert_user.")

    def delete_user(self, device: DeviceConfig, pin: str) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support delete_user.")

    def get_logs(self, device: DeviceConfig, since: Optional[str] = None, until: Optional[str] = None) -> Tuple[DriverResult, List[Dict[str, Any]]]:
        """Return (DriverResult, list_of_punches). Each punch has employee_code, punched_at, punch_state?, verify_mode?."""
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support get_logs."), []

    def clear_logs(self, device: DeviceConfig) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support clear_logs.")

    def sync_time(self, device: DeviceConfig, datetime_iso: str, timezone_name: str) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support sync_time.")

    def restart(self, device: DeviceConfig) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support restart.")

    def start_enrollment(self, device: DeviceConfig, params: Dict[str, Any]) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support start_enrollment.")

    def push_template(self, device: DeviceConfig, params: Dict[str, Any]) -> DriverResult:
        return DriverResult(False, "UNSUPPORTED", error=f"Driver '{self.name}' does not support push_template.")
