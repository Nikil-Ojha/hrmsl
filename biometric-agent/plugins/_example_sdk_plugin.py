"""
Example external hardware SDK plugin (rename without leading underscore to enable).

Drop any Python module defining a HardwareDriver subclass into the agent's
`plugins/` directory to integrate a new hardware manufacturer SDK without
modifying the core agent or the HRMS backend.
"""

from typing import Any, Dict, List, Optional, Tuple
from hrms_agent.drivers.base import DeviceConfig, DeviceHealth, DriverResult, HardwareDriver


class ExampleVendorSdkDriver(HardwareDriver):
    name = "example_vendor_sdk"
    supported_connections = ("lan", "wifi", "usb")

    def check_health(self, device: DeviceConfig) -> DeviceHealth:
        # Call your vendor SDK / ctypes DLL here
        return DeviceHealth(online=False, error="Example plugin not bound to hardware SDK.")

    def get_logs(self, device: DeviceConfig, since: Optional[str] = None, until: Optional[str] = None) -> Tuple[DriverResult, List[Dict[str, Any]]]:
        return DriverResult(False, "UNIMPLEMENTED", error="Implement vendor SDK log fetch here."), []
