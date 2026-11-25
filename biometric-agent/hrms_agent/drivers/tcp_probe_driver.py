"""Generic LAN/Wi-Fi TCP reachability driver (never invents vendor binary commands)."""

from __future__ import annotations

import socket
import time

from .base import DeviceConfig, DeviceHealth, DriverResult, HardwareDriver


class TcpProbeDriver(HardwareDriver):
    """Checks TCP connectivity to LAN/Wi-Fi devices without vendor SDK dependencies."""

    name = "tcp_probe"
    supported_connections = ("lan", "wifi")

    def __init__(self, timeout_sec: float = 3.0) -> None:
        self.timeout_sec = timeout_sec

    def check_health(self, device: DeviceConfig) -> DeviceHealth:
        if not device.ip_address or not device.port:
            return DeviceHealth(online=False, error="Device IP address and port are required for TCP probe.")

        started = time.monotonic()
        try:
            with socket.create_connection((device.ip_address, int(device.port)), timeout=self.timeout_sec):
                latency_ms = int((time.monotonic() - started) * 1000)
                return DeviceHealth(online=True, latency_ms=latency_ms)
        except OSError as exc:
            return DeviceHealth(online=False, error=f"TCP connection failed to {device.ip_address}:{device.port}: {exc}")

    def get_info(self, device: DeviceConfig) -> DriverResult:
        health = self.check_health(device)
        if not health.online:
            return DriverResult(False, "OFFLINE", error=health.error)
        return DriverResult(True, "OK", result={"latency_ms": health.latency_ms})
