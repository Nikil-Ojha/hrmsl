import json
import sys
import tempfile
import unittest
from pathlib import Path

from hrms_agent.drivers import DeviceConfig, DriverRegistry
from hrms_agent.drivers.sdk_subprocess_driver import SdkSubprocessDriver
from hrms_agent.drivers.usb_file_driver import UsbFileDriver


class DriversTest(unittest.TestCase):
    def test_usb_file_driver_reads_attlog_and_csv(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "attlog.dat").write_text("1001\t2026-11-20 09:01:00\t0\t1\n1002\t2026-11-20 09:05:00\t0\t1\n", encoding="utf-8")
            (root / "export.csv").write_text("pin,datetime,state\n1003,2026-11-20 18:00:00,1\n", encoding="utf-8")

            dev = DeviceConfig(1, "USB Reader", "USB-1", "D-USB", "local_agent", "usb", credentials={"usb_path": str(root)})
            drv = UsbFileDriver()
            self.assertTrue(drv.check_health(dev).online)
            res, punches = drv.get_logs(dev)
            self.assertTrue(res.success)
            self.assertEqual(len(punches), 3)
            self.assertEqual({p["employee_code"] for p in punches}, {"1001", "1002", "1003"})

    def test_sdk_subprocess_driver_executes_external_bridge(self):
        with tempfile.TemporaryDirectory() as tmp:
            bridge = Path(tmp) / "fake_sdk.py"
            bridge.write_text(
                "import json, sys\n"
                "req = json.loads(sys.stdin.read())\n"
                "if req['action'] == 'get_logs':\n"
                "    print(json.dumps({'success': True, 'logs': [{'employee_code': '55', 'punched_at': '2026-11-20T08:30:00Z'}]}))\n"
                "else:\n"
                "    print(json.dumps({'success': True, 'result': {'echo_action': req['action'], 'pin': req['params'].get('pin')}}))\n",
                encoding="utf-8",
            )
            dev = DeviceConfig(2, "SDK Term", "SDK-1", "D-SDK", "local_agent", "lan", credentials={"sdk_command": f"{sys.executable} {bridge}"})
            drv = SdkSubprocessDriver()
            res, logs = drv.get_logs(dev)
            self.assertTrue(res.success)
            self.assertEqual(logs[0]["employee_code"], "55")
            del_res = drv.delete_user(dev, "55")
            self.assertTrue(del_res.success)
            self.assertEqual(del_res.result["pin"], "55")

    def test_dynamic_plugin_loading(self):
        with tempfile.TemporaryDirectory() as tmp:
            plugin = Path(tmp) / "acme_sdk.py"
            plugin.write_text(
                "from hrms_agent.drivers.base import DeviceConfig, DeviceHealth, HardwareDriver\n"
                "class AcmeDriver(HardwareDriver):\n"
                "    name = 'acme_sdk'\n"
                "    def check_health(self, device: DeviceConfig) -> DeviceHealth:\n"
                "        return DeviceHealth(online=True, firmware_version='acme-2.1')\n",
                encoding="utf-8",
            )
            reg = DriverRegistry(plugins_dir=Path(tmp))
            self.assertIn("acme_sdk", reg.names())
            dev = DeviceConfig(3, "Acme", "AC-1", "D-AC", "local_agent", "lan", credentials={"driver": "acme_sdk"})
            resolved = reg.resolve_for(dev)
            self.assertEqual(resolved.name, "acme_sdk")
            self.assertEqual(resolved.check_health(dev).firmware_version, "acme-2.1")


if __name__ == "__main__":
    unittest.main()
