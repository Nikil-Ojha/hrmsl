"""Agent configuration and OS-protected credential storage (Windows DPAPI / POSIX 0600)."""

from __future__ import annotations

import base64
import ctypes
from dataclasses import dataclass, field
import json
import os
from pathlib import Path
import sys
from typing import Any, Dict, Optional

from .security import validate_server_url


def default_data_dir() -> Path:
    if sys.platform == "win32":
        base = os.environ.get("PROGRAMDATA") or os.environ.get("LOCALAPPDATA") or str(Path.home())
        return Path(base) / "HRMSBiometricAgent"
    return Path(os.environ.get("HRMS_AGENT_DATA_DIR", str(Path.home() / ".hrms-biometric-agent")))


@dataclass
class AgentConfig:
    server_url: str
    data_dir: Path = field(default_factory=default_data_dir)
    plugins_dir: Optional[Path] = None
    allow_insecure_http: bool = False
    verify_tls: bool = True
    ca_bundle: Optional[str] = None
    request_timeout_sec: int = 20
    log_level: str = "INFO"
    log_max_bytes: int = 5 * 1024 * 1024
    log_backup_count: int = 5
    driver_overrides: Dict[str, str] = field(default_factory=dict)

    def __post_init__(self) -> None:
        self.server_url = validate_server_url(self.server_url, self.allow_insecure_http)
        self.data_dir = Path(self.data_dir).expanduser().resolve()
        if self.plugins_dir is not None:
            self.plugins_dir = Path(self.plugins_dir).expanduser().resolve()
        else:
            self.plugins_dir = self.data_dir / "plugins"

    @property
    def db_path(self) -> Path:
        return self.data_dir / "agent_queue.db"

    @property
    def credentials_path(self) -> Path:
        return self.data_dir / "credentials.dat"

    @property
    def log_path(self) -> Path:
        return self.data_dir / "logs" / "agent.log"

    @classmethod
    def from_file(cls, path: Path) -> "AgentConfig":
        raw = json.loads(Path(path).read_text(encoding="utf-8"))
        return cls(
            server_url=raw["server_url"],
            data_dir=Path(raw.get("data_dir", default_data_dir())),
            plugins_dir=Path(raw["plugins_dir"]) if raw.get("plugins_dir") else None,
            allow_insecure_http=bool(raw.get("allow_insecure_http", False)),
            verify_tls=bool(raw.get("verify_tls", True)),
            ca_bundle=raw.get("ca_bundle"),
            request_timeout_sec=int(raw.get("request_timeout_sec", 20)),
            log_level=str(raw.get("log_level", "INFO")),
            log_max_bytes=int(raw.get("log_max_bytes", 5 * 1024 * 1024)),
            log_backup_count=int(raw.get("log_backup_count", 5)),
            driver_overrides=dict(raw.get("driver_overrides") or {}),
        )

    def to_file(self, path: Path) -> None:
        path = Path(path)
        path.parent.mkdir(parents=True, exist_ok=True)
        payload = {
            "server_url": self.server_url,
            "data_dir": str(self.data_dir),
            "plugins_dir": str(self.plugins_dir) if self.plugins_dir else None,
            "allow_insecure_http": self.allow_insecure_http,
            "verify_tls": self.verify_tls,
            "ca_bundle": self.ca_bundle,
            "request_timeout_sec": self.request_timeout_sec,
            "log_level": self.log_level,
            "log_max_bytes": self.log_max_bytes,
            "log_backup_count": self.log_backup_count,
            "driver_overrides": self.driver_overrides,
        }
        path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


@dataclass
class AgentCredentials:
    agent_identifier: str
    access_token: str
    hmac_secret: str
    server_url: str

    def to_dict(self) -> Dict[str, Any]:
        return {
            "agent_identifier": self.agent_identifier,
            "access_token": self.access_token,
            "hmac_secret": self.hmac_secret,
            "server_url": self.server_url,
        }

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "AgentCredentials":
        return cls(
            agent_identifier=str(data["agent_identifier"]),
            access_token=str(data["access_token"]),
            hmac_secret=str(data["hmac_secret"]),
            server_url=str(data["server_url"]),
        )


def _dpapi_protect(data: bytes) -> bytes:
    """Encrypt bytes with Windows DPAPI (LocalMachine scope for Windows services)."""
    class DATA_BLOB(ctypes.Structure):
        _fields_ = [("cbData", ctypes.c_uint32), ("pbData", ctypes.POINTER(ctypes.c_char))]

    crypt32 = ctypes.windll.crypt32  # type: ignore[attr-defined]
    kernel32 = ctypes.windll.kernel32  # type: ignore[attr-defined]
    buf = ctypes.create_string_buffer(data, len(data))
    in_blob = DATA_BLOB(len(data), buf)
    out_blob = DATA_BLOB()
    # CRYPTPROTECT_LOCAL_MACHINE = 0x4 (readable by the Windows service account on this machine)
    if not crypt32.CryptProtectData(ctypes.byref(in_blob), "HRMSAgent", None, None, None, 0x4, ctypes.byref(out_blob)):
        raise OSError("Windows DPAPI CryptProtectData failed")
    try:
        return ctypes.string_at(out_blob.pbData, out_blob.cbData)
    finally:
        kernel32.LocalFree(out_blob.pbData)


def _dpapi_unprotect(data: bytes) -> bytes:
    class DATA_BLOB(ctypes.Structure):
        _fields_ = [("cbData", ctypes.c_uint32), ("pbData", ctypes.POINTER(ctypes.c_char))]

    crypt32 = ctypes.windll.crypt32  # type: ignore[attr-defined]
    kernel32 = ctypes.windll.kernel32  # type: ignore[attr-defined]
    buf = ctypes.create_string_buffer(data, len(data))
    in_blob = DATA_BLOB(len(data), buf)
    out_blob = DATA_BLOB()
    if not crypt32.CryptUnprotectData(ctypes.byref(in_blob), None, None, None, None, 0, ctypes.byref(out_blob)):
        raise OSError("Windows DPAPI CryptUnprotectData failed")
    try:
        return ctypes.string_at(out_blob.pbData, out_blob.cbData)
    finally:
        kernel32.LocalFree(out_blob.pbData)


class CredentialStore:
    """Persists provisioned credentials using DPAPI on Windows and 0600 files on Linux/macOS."""

    def __init__(self, path: Path) -> None:
        self.path = Path(path)

    def save(self, creds: AgentCredentials) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        raw = json.dumps(creds.to_dict()).encode("utf-8")

        if sys.platform == "win32":
            envelope = {"scheme": "dpapi", "blob": base64.b64encode(_dpapi_protect(raw)).decode("ascii")}
        else:
            envelope = {"scheme": "posix-0600", "data": creds.to_dict()}

        tmp = self.path.with_suffix(".tmp")
        fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                json.dump(envelope, fh)
        except Exception:
            if tmp.exists():
                tmp.unlink(missing_ok=True)
            raise
        os.replace(tmp, self.path)
        if sys.platform != "win32":
            os.chmod(self.path, 0o600)

    def load(self) -> Optional[AgentCredentials]:
        if not self.path.exists():
            return None
        envelope = json.loads(self.path.read_text(encoding="utf-8"))
        scheme = envelope.get("scheme")
        if scheme == "dpapi":
            if sys.platform != "win32":
                raise RuntimeError("DPAPI credential file cannot be decrypted on non-Windows OS.")
            raw = _dpapi_unprotect(base64.b64decode(envelope["blob"]))
            return AgentCredentials.from_dict(json.loads(raw.decode("utf-8")))
        if scheme == "posix-0600":
            return AgentCredentials.from_dict(envelope["data"])
        raise ValueError(f"Unsupported credential storage scheme: {scheme!r}")

    def exists(self) -> bool:
        return self.path.exists()
