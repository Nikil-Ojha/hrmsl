"""USB mass-storage / export-file attendance reader driver."""

from __future__ import annotations

import csv
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from .base import DeviceConfig, DeviceHealth, DriverResult, HardwareDriver


class UsbFileDriver(HardwareDriver):
    """
    Reads attendance logs from a mounted USB flash drive or export folder.
    Supports:
      - Standard tab-separated ATTLOG files (.dat / .txt): PIN \\t YYYY-MM-DD HH:MM:SS \\t State \\t Verify
      - CSV files (.csv) with headers (employee_code/pin, punched_at/datetime, punch_state?, verify_mode?)
    """

    name = "usb_file"
    supported_connections = ("usb",)

    def check_health(self, device: DeviceConfig) -> DeviceHealth:
        path = self._usb_path(device)
        if not path:
            return DeviceHealth(online=False, error="usb_path is not configured on this device.")
        if not path.exists():
            return DeviceHealth(online=False, error=f"USB path not mounted or not found: {path}")
        return DeviceHealth(online=True, latency_ms=0)

    def get_logs(self, device: DeviceConfig, since: Optional[str] = None, until: Optional[str] = None) -> Tuple[DriverResult, List[Dict[str, Any]]]:
        path = self._usb_path(device)
        if not path or not path.exists():
            return DriverResult(False, "NOT_FOUND", error=f"USB path not found: {path}"), []

        files = [path] if path.is_file() else sorted(
            p for p in path.iterdir() if p.is_file() and p.suffix.lower() in (".dat", ".txt", ".csv")
        )

        punches: List[Dict[str, Any]] = []
        for file_path in files:
            if file_path.suffix.lower() == ".csv":
                punches.extend(self._parse_csv(file_path))
            else:
                punches.extend(self._parse_attlog(file_path))

        if since or until:
            punches = [
                p for p in punches
                if (not since or p["punched_at"] >= since[:19].replace("T", " "))
                and (not until or p["punched_at"] <= until[:19].replace("T", " "))
            ]

        return DriverResult(True, "OK", result={"files": len(files), "count": len(punches)}), punches

    def _usb_path(self, device: DeviceConfig) -> Optional[Path]:
        raw = (device.credentials or {}).get("usb_path") or (device.settings or {}).get("usb_path")
        return Path(str(raw)).expanduser() if raw else None

    def _parse_attlog(self, file_path: Path) -> List[Dict[str, Any]]:
        out: List[Dict[str, Any]] = []
        for line in file_path.read_text(encoding="utf-8", errors="ignore").splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            parts = [p.strip() for p in line.split("\t")]
            if len(parts) >= 2 and parts[0] and len(parts[1]) >= 16:
                out.append({
                    "employee_code": parts[0],
                    "punched_at": parts[1],
                    "punch_state": parts[2] if len(parts) > 2 else None,
                    "verify_mode": parts[3] if len(parts) > 3 else None,
                })
        return out

    def _parse_csv(self, file_path: Path) -> List[Dict[str, Any]]:
        out: List[Dict[str, Any]] = []
        with file_path.open("r", encoding="utf-8", errors="ignore", newline="") as fh:
            reader = csv.DictReader(fh)
            for row in reader:
                norm = {k.strip().lower(): (v or "").strip() for k, v in row.items() if k}
                code = norm.get("employee_code") or norm.get("pin") or norm.get("user_id")
                dt = norm.get("punched_at") or norm.get("log_datetime") or norm.get("datetime") or norm.get("timestamp")
                if code and dt:
                    out.append({
                        "employee_code": code,
                        "punched_at": dt,
                        "punch_state": norm.get("punch_state") or norm.get("state") or None,
                        "verify_mode": norm.get("verify_mode") or norm.get("verify") or None,
                    })
        return out
