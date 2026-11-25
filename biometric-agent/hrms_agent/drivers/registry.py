"""Dynamic driver registry supporting built-in drivers and external SDK plugins."""

from __future__ import annotations

import importlib.util
import inspect
import logging
from pathlib import Path
from typing import Dict, List, Optional

from .base import DeviceConfig, HardwareDriver
from .http_bridge_driver import HttpBridgeDriver
from .mock_driver import MockDriver
from .sdk_subprocess_driver import SdkSubprocessDriver
from .tcp_probe_driver import TcpProbeDriver
from .usb_file_driver import UsbFileDriver

logger = logging.getLogger("hrms_agent.drivers")


class DriverRegistry:
    """Resolves a local HardwareDriver for each configured device."""

    def __init__(self, plugins_dir: Optional[Path] = None, overrides: Optional[Dict[str, str]] = None) -> None:
        self._drivers: Dict[str, HardwareDriver] = {}
        self._overrides = dict(overrides or {})

        for drv in (TcpProbeDriver(), HttpBridgeDriver(), UsbFileDriver(), SdkSubprocessDriver(), MockDriver()):
            self.register(drv)

        if plugins_dir and Path(plugins_dir).is_dir():
            self.load_plugins(Path(plugins_dir))

    def register(self, driver: HardwareDriver) -> None:
        self._drivers[driver.name] = driver

    def names(self) -> List[str]:
        return sorted(self._drivers.keys())

    def get(self, name: str) -> Optional[HardwareDriver]:
        return self._drivers.get(name)

    def resolve_for(self, device: DeviceConfig) -> HardwareDriver:
        """
        Determine which local driver should handle a device:
          1. Local config driver_overrides[serial_number or protocol]
          2. Explicit `credentials.driver` set on the device in HRMS
          3. `sdk_subprocess` if `credentials.sdk_command` is present
          4. `usb_file` for USB devices
          5. `mock` for mock protocol
          6. `http_bridge` for native protocol
          7. Fallback: `tcp_probe` (safe connectivity/health only)
        """
        candidates = [
            self._overrides.get(device.serial_number),
            self._overrides.get(device.device_identifier),
            self._overrides.get(device.protocol),
            (device.credentials or {}).get("driver"),
            "sdk_subprocess" if (device.credentials or {}).get("sdk_command") else None,
            "usb_file" if device.connection_type == "usb" else None,
            "mock" if device.protocol == "mock" else None,
            "http_bridge" if device.protocol == "native" else None,
            "tcp_probe",
        ]

        for name in candidates:
            if name and name in self._drivers:
                return self._drivers[name]

        return self._drivers["tcp_probe"]

    def load_plugins(self, plugins_dir: Path) -> int:
        """Load any *.py file in plugins_dir defining a HardwareDriver subclass."""
        loaded = 0
        for path in sorted(plugins_dir.glob("*.py")):
            if path.name.startswith("_"):
                continue
            try:
                spec = importlib.util.spec_from_file_location(f"hrms_agent_plugin_{path.stem}", path)
                if not spec or not spec.loader:
                    continue
                mod = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(mod)
                for _, obj in inspect.getmembers(mod, inspect.isclass):
                    if issubclass(obj, HardwareDriver) and obj is not HardwareDriver and not inspect.isabstract(obj):
                        instance = obj()
                        self.register(instance)
                        loaded += 1
                        logger.info("Loaded external biometric SDK plugin '%s' from %s", instance.name, path.name)
            except Exception as exc:
                logger.error("Failed to load plugin %s: %s", path.name, exc)
        return loaded
