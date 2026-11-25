"""Main resilient daemon loop for the Local Biometric Device Communication Agent."""

from __future__ import annotations

import hashlib
import logging
import signal
import threading
import time
from typing import Any, Dict, List, Optional

from .api_client import ApiError, HrmsApiClient
from .config import AgentConfig
from .discovery import discover_lan, discover_usb
from .drivers import DeviceConfig, DriverRegistry, DriverResult
from .storage import LocalStore, utc_now_iso

logger = logging.getLogger("hrms_agent.runner")


class AgentRunner:
    """Orchestrates local device polling, SQLite queuing, command execution, health and retries."""

    def __init__(
        self,
        config: AgentConfig,
        api: HrmsApiClient,
        store: LocalStore,
        drivers: DriverRegistry,
    ) -> None:
        self.config = config
        self.api = api
        self.store = store
        self.drivers = drivers

        self._stop = threading.Event()
        self._was_offline = False
        self._settings: Dict[str, Any] = {
            "heartbeat_interval_sec": 60,
            "command_poll_interval_sec": 15,
            "log_poll_interval_sec": 60,
            "config_refresh_interval_sec": 300,
            "log_batch_size": 200,
            "discovery_enabled": False,
            "discovery_cidrs": [],
            "discovery_ports": [4370, 5005, 8080],
        }
        self._devices: List[DeviceConfig] = []
        self._load_cached_config()

        self._last_config_at = 0.0
        self._last_log_poll_at = 0.0
        self._last_command_poll_at = 0.0
        self._last_heartbeat_at = 0.0
        self._last_discovery_at = 0.0

    def request_stop(self) -> None:
        self._stop.set()

    def run_forever(self) -> None:
        """Start the daemon loop until SIGINT/SIGTERM or request_stop()."""
        def _handle_signal(signum: int, _frame: Any) -> None:
            logger.info("Received signal %s, shutting down gracefully...", signum)
            self.request_stop()

        for sig in (signal.SIGINT, signal.SIGTERM):
            try:
                signal.signal(sig, _handle_signal)
            except (ValueError, OSError):
                pass

        logger.info("HRMS Biometric Agent started (drivers=%s)", ", ".join(self.drivers.names()))

        while not self._stop.is_set():
            now = time.monotonic()
            try:
                if now - self._last_config_at >= float(self._settings.get("config_refresh_interval_sec", 300)):
                    self.refresh_config()
                    self._last_config_at = now

                if now - self._last_log_poll_at >= float(self._settings.get("log_poll_interval_sec", 60)):
                    self.poll_device_logs()
                    self._last_log_poll_at = now

                self.flush_punch_queue()

                if now - self._last_command_poll_at >= float(self._settings.get("command_poll_interval_sec", 15)):
                    self.process_commands()
                    self._last_command_poll_at = now

                if now - self._last_heartbeat_at >= float(self._settings.get("heartbeat_interval_sec", 60)):
                    self.send_heartbeat()
                    self.flush_errors()
                    self._last_heartbeat_at = now

                if (
                    self._settings.get("discovery_enabled")
                    and now - self._last_discovery_at >= 600.0
                ):
                    self.run_discovery()
                    self._last_discovery_at = now
            except Exception as exc:
                logger.exception("Unexpected error in agent loop: %s", exc)
                self.store.record_error("agent_loop_error", str(exc))

            self._stop.wait(timeout=2.0)

        logger.info("HRMS Biometric Agent stopped.")

    def run_once(self) -> Dict[str, Any]:
        """Run one complete synchronous cycle (useful for CLI diagnostics and tests)."""
        cfg_ok = self.refresh_config()
        queued = self.poll_device_logs()
        flushed = self.flush_punch_queue()
        cmds = self.process_commands()
        hb = self.send_heartbeat()
        errs = self.flush_errors()
        return {
            "config_refreshed": cfg_ok,
            "punches_polled": queued,
            "punches_flushed": flushed,
            "commands_processed": cmds,
            "heartbeat_sent": hb,
            "errors_flushed": errs,
        }

    # ------------------------------------------------------------------
    # Steps
    # ------------------------------------------------------------------

    def refresh_config(self) -> bool:
        try:
            resp = self.api.fetch_config()
            data = resp.get("data", {})
            self._apply_config(data)
            self.store.set_state("cached_config", data)
            self._on_online()
            logger.info("Configuration refreshed: %d active device(s) assigned.", len(self._devices))
            return True
        except ApiError as exc:
            self._on_api_error("config_refresh", exc)
            return False

    def poll_device_logs(self) -> int:
        total_new = 0
        for device in self._devices:
            driver = self.drivers.resolve_for(device)
            cursor = self.store.get_cursor(device.id)
            try:
                res, punches = driver.get_logs(device, since=cursor["last_log_at"])
                if not res.success:
                    if res.return_code != "UNSUPPORTED":
                        logger.warning("Log poll failed for device %s (%s): %s", device.name, device.serial_number, res.error)
                        self.store.record_error("log_poll_failed", res.error or "Failed to fetch logs", device.id, device.serial_number)
                    continue

                if punches:
                    added = self.store.enqueue_punches(device.id, device.serial_number, punches)
                    total_new += added
                    latest = max(str(p.get("punched_at", "")) for p in punches if p.get("punched_at"))
                    if latest:
                        self.store.set_cursor(device.id, last_log_at=latest)
                    if added:
                        logger.info("Queued %d new punch(es) from %s (%s).", added, device.name, device.serial_number)
            except Exception as exc:
                logger.exception("Error polling logs for device %s: %s", device.name, exc)
                self.store.record_error("log_poll_exception", str(exc), device.id, device.serial_number)
        return total_new

    def flush_punch_queue(self) -> int:
        batch_size = int(self._settings.get("log_batch_size", 200))
        synced_total = 0

        while True:
            batch = self.store.pending_punches(limit=batch_size)
            if not batch:
                break

            payload = [
                {
                    "local_uuid": p.local_uuid,
                    "device_id": p.device_id,
                    "device_serial": p.device_serial,
                    "employee_code": p.employee_code,
                    "punched_at": p.punched_at,
                    "punch_state": p.punch_state,
                    "verify_mode": p.verify_mode,
                    "original_device_log_id": p.original_device_log_id,
                }
                for p in batch
            ]

            try:
                batch_key = hashlib.sha256(",".join(sorted(p.local_uuid for p in batch)).encode("utf-8")).hexdigest()[:40]
                try:
                    resp = self.api.upload_logs(payload, idempotency_key=batch_key)
                except TypeError:
                    resp = self.api.upload_logs(payload)
                self._on_online()
                results = resp.get("data", {}).get("results", [])

                to_mark_synced: List[str] = []
                for item in results:
                    uid = str(item.get("local_uuid", ""))
                    status = item.get("status")
                    if status in ("accepted", "duplicate"):
                        to_mark_synced.append(uid)
                    elif status == "rejected":
                        self.store.mark_punch_rejected(uid, str(item.get("reason") or "Rejected by HRMS"))
                        self.store.record_error("punch_rejected", f"Punch {uid} rejected: {item.get('reason')}")

                synced_total += self.store.mark_punches_synced(to_mark_synced)

                if len(batch) < batch_size:
                    break
            except ApiError as exc:
                self._on_api_error("upload_logs", exc)
                self.store.schedule_retry([p.local_uuid for p in batch], str(exc))
                break

        return synced_total

    def process_commands(self) -> int:
        try:
            commands = self.api.poll_commands(limit=20)
            self._on_online()
        except ApiError as exc:
            self._on_api_error("poll_commands", exc)
            return 0

        if not commands:
            return 0

        devices_by_id = {d.id: d for d in self._devices}
        processed = 0

        for cmd in commands:
            cmd_id = int(cmd["command_id"])
            dev_id = int(cmd["device_id"])
            cmd_type = str(cmd["type"])
            payload = dict(cmd.get("payload") or {})

            device = devices_by_id.get(dev_id)
            if not device:
                # Refresh config once in case a device was just assigned.
                self.refresh_config()
                devices_by_id = {d.id: d for d in self._devices}
                device = devices_by_id.get(dev_id)

            if not device:
                self._ack(cmd_id, DriverResult(False, "UNKNOWN_DEVICE", error=f"Device {dev_id} is not configured on this agent."))
                processed += 1
                continue

            res = self._execute_command(device, cmd_type, payload)
            self._ack(cmd_id, res)
            processed += 1

        return processed

    def send_heartbeat(self) -> bool:
        health_list: List[Dict[str, Any]] = []
        for device in self._devices:
            driver = self.drivers.resolve_for(device)
            try:
                health = driver.check_health(device)
                health_list.append(health.to_heartbeat_dict(device))
            except Exception as exc:
                health_list.append({"device_id": device.id, "device_serial": device.serial_number, "online": False, "error": str(exc)})

        try:
            self.api.send_heartbeat(
                local_queue_depth=self.store.pending_punch_count(),
                local_error_count=self.store.pending_error_count(),
                device_health=health_list,
                capabilities={"drivers": self.drivers.names()},
            )
            self._on_online()
            return True
        except ApiError as exc:
            self._on_api_error("heartbeat", exc)
            return False

    def run_discovery(self) -> int:
        found: List[Dict[str, Any]] = []
        cidrs = self._settings.get("discovery_cidrs") or []
        ports = self._settings.get("discovery_ports") or [4370, 5005, 8080]
        if cidrs:
            found.extend(discover_lan(cidrs, ports))
        found.extend(discover_usb())

        if not found:
            return 0
        try:
            self.api.report_discovery(found)
            self._on_online()
            logger.info("Reported %d discovered local device(s) to HRMS.", len(found))
            return len(found)
        except ApiError as exc:
            self._on_api_error("discovery", exc)
            return 0

    def flush_errors(self) -> int:
        pending = self.store.pending_errors(limit=50)
        if not pending:
            return 0
        payload = [
            {
                "device_id": e["device_id"],
                "device_serial": e["device_serial"],
                "level": e["level"],
                "type": e["type"],
                "message": e["message"],
                "context": e["context"],
                "occurred_at": e["occurred_at"],
            }
            for e in pending
        ]
        try:
            self.api.report_errors(payload)
            self.store.mark_errors_synced([e["id"] for e in pending])
            self._on_online()
            return len(pending)
        except ApiError as exc:
            self._on_api_error("report_errors", exc)
            return 0

    # ------------------------------------------------------------------
    # Internals
    # ------------------------------------------------------------------

    def _execute_command(self, device: DeviceConfig, cmd_type: str, payload: Dict[str, Any]) -> DriverResult:
        driver = self.drivers.resolve_for(device)
        logger.info("Executing '%s' on device %s (%s) via driver '%s'", cmd_type, device.name, device.serial_number, driver.name)

        try:
            if cmd_type == "ping":
                h = driver.check_health(device)
                return DriverResult(h.online, "OK" if h.online else "OFFLINE", result=h.to_heartbeat_dict(device), error=h.error)
            if cmd_type == "get_info":
                return driver.get_info(device)
            if cmd_type == "query_users":
                return driver.get_users(device)
            if cmd_type == "user_upsert":
                return driver.upsert_user(device, payload)
            if cmd_type == "user_delete":
                return driver.delete_user(device, str(payload.get("pin") or ""))
            if cmd_type == "start_enrollment":
                return driver.start_enrollment(device, payload)
            if cmd_type == "template_push":
                return driver.push_template(device, payload)
            if cmd_type == "fetch_logs":
                res, punches = driver.get_logs(device, since=payload.get("from"), until=payload.get("to"))
                if res.success and punches:
                    added = self.store.enqueue_punches(device.id, device.serial_number, punches)
                    self.flush_punch_queue()
                    res.result = {**res.result, "queued": added}
                return res
            if cmd_type == "clear_logs":
                return driver.clear_logs(device)
            if cmd_type == "sync_time":
                return driver.sync_time(device, str(payload.get("datetime") or utc_now_iso()), str(payload.get("timezone") or device.timezone))
            if cmd_type == "reboot":
                return driver.restart(device)
            return DriverResult(False, "UNKNOWN_COMMAND", error=f"Unsupported command type: {cmd_type}")
        except Exception as exc:
            logger.exception("Command '%s' crashed on device %s: %s", cmd_type, device.name, exc)
            self.store.record_error("command_exception", str(exc), device.id, device.serial_number, context={"command": cmd_type})
            return DriverResult(False, "EXCEPTION", error=str(exc))

    def _ack(self, command_id: int, res: DriverResult) -> None:
        try:
            self.api.acknowledge_command(
                command_id=command_id,
                success=res.success,
                return_code=res.return_code,
                result=res.result,
                error=res.error,
            )
        except ApiError as exc:
            self._on_api_error("acknowledge_command", exc)

    def _apply_config(self, data: Dict[str, Any]) -> None:
        if isinstance(data.get("settings"), dict):
            self._settings.update(data["settings"])
        self._devices = [DeviceConfig.from_api(d) for d in data.get("devices", [])]

    def _load_cached_config(self) -> None:
        cached = self.store.get_state("cached_config")
        if isinstance(cached, dict):
            self._apply_config(cached)

    def _on_online(self) -> None:
        if self._was_offline:
            logger.info("Connectivity to HRMS restored; resetting backoff on queued punches.")
            self._was_offline = False
            self.store.reset_pending_backoff()

    def _on_api_error(self, op: str, exc: ApiError) -> None:
        if exc.transient:
            if not self._was_offline:
                logger.warning("HRMS unreachable during %s (%s). Switching to offline queue mode.", op, exc)
            self._was_offline = True
        else:
            logger.error("HRMS API error during %s: %s", op, exc)
            self.store.record_error(f"api_{op}", str(exc))
