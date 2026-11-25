import tempfile
import unittest
from pathlib import Path
from typing import Any, Dict, List, Optional

from hrms_agent.api_client import ApiError
from hrms_agent.config import AgentConfig
from hrms_agent.drivers import DriverRegistry
from hrms_agent.drivers.mock_driver import MockDriver
from hrms_agent.runner import AgentRunner
from hrms_agent.storage import LocalStore


class FakeApi:
    def __init__(self) -> None:
        self.online = True
        self.devices = [{
            "id": 10,
            "name": "Local Gate",
            "serial_number": "LOC-10",
            "device_identifier": "DEV-10",
            "protocol": "mock",
            "connection_type": "lan",
            "timezone": "UTC",
            "credentials": {},
        }]
        self.uploaded_logs: List[Dict[str, Any]] = []
        self.pending_commands: List[Dict[str, Any]] = []
        self.acks: List[Dict[str, Any]] = []
        self.heartbeats: List[Dict[str, Any]] = []
        self.errors: List[Dict[str, Any]] = []

    def _guard(self) -> None:
        if not self.online:
            raise ApiError("Internet offline", status_code=None, transient=True)

    def fetch_config(self) -> Dict[str, Any]:
        self._guard()
        return {"status": True, "data": {"settings": {"log_batch_size": 50}, "devices": self.devices}}

    def upload_logs(self, logs: List[Dict[str, Any]]) -> Dict[str, Any]:
        self._guard()
        self.uploaded_logs.extend(logs)
        return {"status": True, "data": {"results": [{"local_uuid": l["local_uuid"], "status": "accepted"} for l in logs]}}

    def poll_commands(self, limit: int = 20) -> List[Dict[str, Any]]:
        self._guard()
        out = list(self.pending_commands)
        self.pending_commands.clear()
        return out

    def acknowledge_command(self, command_id: int, success: bool, return_code: Optional[str] = None, result: Optional[Dict[str, Any]] = None, error: Optional[str] = None) -> Dict[str, Any]:
        self._guard()
        self.acks.append({"command_id": command_id, "success": success, "return_code": return_code, "result": result, "error": error})
        return {"status": True}

    def send_heartbeat(self, local_queue_depth: int, local_error_count: int, device_health: List[Dict[str, Any]], capabilities: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        self._guard()
        self.heartbeats.append({"queue": local_queue_depth, "errors": local_error_count, "devices": device_health})
        return {"status": True}

    def report_errors(self, errors: List[Dict[str, Any]]) -> Dict[str, Any]:
        self._guard()
        self.errors.extend(errors)
        return {"status": True}


class RunnerTest(unittest.TestCase):
    def test_offline_queue_recovery_and_commands(self):
        with tempfile.TemporaryDirectory() as tmp:
            cfg = AgentConfig(server_url="https://hrms.example.com", data_dir=Path(tmp))
            store = LocalStore(cfg.db_path)
            drivers = DriverRegistry()
            mock: MockDriver = drivers.get("mock")  # type: ignore[assignment]
            api = FakeApi()
            runner = AgentRunner(cfg, api, store, drivers)  # type: ignore[arg-type]

            try:
                # 1. Online cycle caches config
                self.assertTrue(runner.refresh_config())

                # 2. Internet goes offline; device keeps recording punches locally
                api.online = False
                mock.inject_punch(10, "1001", "2026-11-20T09:00:00Z")
                mock.inject_punch(10, "1002", "2026-11-20T09:02:00Z")
                summary_offline = runner.run_once()
                self.assertFalse(summary_offline["config_refreshed"])
                self.assertEqual(summary_offline["punches_polled"], 2)
                self.assertEqual(summary_offline["punches_flushed"], 0)
                self.assertEqual(store.pending_punch_count(), 2)

                # 3. Internet returns -> backoff resets & queued punches flush automatically!
                api.online = True
                api.pending_commands = [
                    {"command_id": 701, "device_id": 10, "type": "user_upsert", "payload": {"pin": "1001", "name": "Alice"}},
                    {"command_id": 702, "device_id": 10, "type": "query_users", "payload": {}},
                    {"command_id": 703, "device_id": 10, "type": "sync_time", "payload": {"datetime": "2026-11-20T12:00:00+05:30", "timezone": "Asia/Kolkata"}},
                    {"command_id": 704, "device_id": 10, "type": "start_enrollment", "payload": {"pin": "1001", "name": "Alice", "modality": "fingerprint", "slot_index": 6}},
                    {"command_id": 705, "device_id": 10, "type": "template_push", "payload": {"pin": "1001", "modality": "fingerprint", "slot_index": 6, "template_data": "bWluaQ=="}},
                ]
                summary_online = runner.run_once()
                self.assertTrue(summary_online["config_refreshed"])
                self.assertEqual(summary_online["punches_flushed"], 2)
                self.assertEqual(store.pending_punch_count(), 0)
                self.assertEqual(len(api.uploaded_logs), 2)
                self.assertEqual(summary_online["commands_processed"], 5)
                self.assertTrue(all(a["success"] for a in api.acks))
                self.assertEqual(api.acks[1]["result"]["users"][0]["name"], "Alice")
                self.assertTrue(summary_online["heartbeat_sent"])
                self.assertTrue(api.heartbeats[-1]["devices"][0]["online"])
            finally:
                store.close()


if __name__ == "__main__":
    unittest.main()
