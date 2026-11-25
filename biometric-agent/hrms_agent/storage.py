"""Crash-safe SQLite local queue for unsynchronized attendance records, errors and state."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
import json
from pathlib import Path
import sqlite3
import time
from typing import Any, Dict, List, Optional
import uuid


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


@dataclass
class QueuedPunch:
    id: int
    local_uuid: str
    device_id: int
    device_serial: str
    employee_code: str
    punched_at: str
    punch_state: Optional[str]
    verify_mode: Optional[str]
    attempts: int
    original_device_log_id: Optional[str] = None


class LocalStore:
    """Persistent local queue backed by SQLite (WAL mode for power-loss safety)."""

    def __init__(self, db_path: Path) -> None:
        self.db_path = Path(db_path)
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self._conn = sqlite3.connect(str(self.db_path), timeout=30.0, check_same_thread=False)
        self._conn.row_factory = sqlite3.Row
        self._init_schema()

    def _init_schema(self) -> None:
        with self._conn:
            self._conn.execute("PRAGMA journal_mode=WAL;")
            self._conn.execute("PRAGMA synchronous=FULL;")
            self._conn.executescript(
                """
                CREATE TABLE IF NOT EXISTS punch_queue (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    local_uuid TEXT NOT NULL UNIQUE,
                    device_id INTEGER NOT NULL,
                    device_serial TEXT NOT NULL,
                    employee_code TEXT NOT NULL,
                    punched_at TEXT NOT NULL,
                    punch_state TEXT,
                    verify_mode TEXT,
                    original_device_log_id TEXT,
                    status TEXT NOT NULL DEFAULT 'pending',
                    attempts INTEGER NOT NULL DEFAULT 0,
                    next_retry_at REAL NOT NULL DEFAULT 0,
                    last_error TEXT,
                    created_at TEXT NOT NULL,
                    synced_at TEXT
                );
                CREATE UNIQUE INDEX IF NOT EXISTS idx_punch_dedupe
                    ON punch_queue(device_serial, employee_code, punched_at);
                CREATE INDEX IF NOT EXISTS idx_punch_pending
                    ON punch_queue(status, next_retry_at, id);

                CREATE TABLE IF NOT EXISTS error_queue (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    device_id INTEGER,
                    device_serial TEXT,
                    level TEXT NOT NULL DEFAULT 'error',
                    type TEXT NOT NULL,
                    message TEXT NOT NULL,
                    context_json TEXT,
                    occurred_at TEXT NOT NULL,
                    synced INTEGER NOT NULL DEFAULT 0
                );
                CREATE INDEX IF NOT EXISTS idx_error_unsynced ON error_queue(synced, id);

                CREATE TABLE IF NOT EXISTS device_cursors (
                    device_id INTEGER PRIMARY KEY,
                    last_log_at TEXT,
                    last_employee_sync_at TEXT,
                    updated_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS agent_state (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );
                """
            )

    def close(self) -> None:
        self._conn.close()

    # ------------------------------------------------------------------
    # Attendance punch queue
    # ------------------------------------------------------------------

    def enqueue_punches(self, device_id: int, device_serial: str, punches: List[Dict[str, Any]]) -> int:
        """Insert new punches idempotently. Returns count of newly queued rows."""
        inserted = 0
        now_iso = utc_now_iso()
        with self._conn:
            for p in punches:
                emp_code = str(p.get("employee_code") or p.get("pin") or "").strip()
                punched_at = str(p.get("punched_at") or "").strip()
                if not emp_code or not punched_at:
                    continue
                cur = self._conn.execute(
                    """
                    INSERT OR IGNORE INTO punch_queue (
                        local_uuid, device_id, device_serial, employee_code,
                        punched_at, punch_state, verify_mode, original_device_log_id, status,
                        attempts, next_retry_at, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, 0, ?)
                    """,
                    (
                        str(p.get("local_uuid") or uuid.uuid4()),
                        int(device_id),
                        device_serial,
                        emp_code,
                        punched_at,
                        p.get("punch_state"),
                        p.get("verify_mode"),
                        p.get("original_device_log_id") or p.get("device_log_id"),
                        now_iso,
                    ),
                )
                if cur.rowcount == 1:
                    inserted += 1
        return inserted

    def pending_punches(self, limit: int = 200, now_ts: Optional[float] = None) -> List[QueuedPunch]:
        now_val = time.time() if now_ts is None else now_ts
        rows = self._conn.execute(
            """
            SELECT id, local_uuid, device_id, device_serial, employee_code,
                   punched_at, punch_state, verify_mode, original_device_log_id, attempts
            FROM punch_queue
            WHERE status = 'pending' AND next_retry_at <= ?
            ORDER BY id ASC
            LIMIT ?
            """,
            (now_val, limit),
        ).fetchall()
        return [
            QueuedPunch(
                id=r["id"],
                local_uuid=r["local_uuid"],
                device_id=r["device_id"],
                device_serial=r["device_serial"],
                employee_code=r["employee_code"],
                punched_at=r["punched_at"],
                punch_state=r["punch_state"],
                verify_mode=r["verify_mode"],
                attempts=r["attempts"],
                original_device_log_id=r["original_device_log_id"],
            )
            for r in rows
        ]

    def mark_punches_synced(self, local_uuids: List[str]) -> int:
        if not local_uuids:
            return 0
        now_iso = utc_now_iso()
        placeholders = ",".join("?" for _ in local_uuids)
        with self._conn:
            cur = self._conn.execute(
                f"UPDATE punch_queue SET status = 'synced', synced_at = ?, last_error = NULL WHERE local_uuid IN ({placeholders})",
                [now_iso, *local_uuids],
            )
            return cur.rowcount

    def mark_punch_rejected(self, local_uuid: str, reason: str) -> None:
        with self._conn:
            self._conn.execute(
                "UPDATE punch_queue SET status = 'rejected', last_error = ? WHERE local_uuid = ?",
                (reason[:1000], local_uuid),
            )

    def schedule_retry(self, local_uuids: List[str], error: str, base_delay: int = 30, max_delay: int = 3600, now_ts: Optional[float] = None) -> None:
        """Apply capped exponential backoff when connectivity or server fails."""
        if not local_uuids:
            return
        now_val = time.time() if now_ts is None else now_ts
        with self._conn:
            for uid in local_uuids:
                row = self._conn.execute("SELECT attempts FROM punch_queue WHERE local_uuid = ?", (uid,)).fetchone()
                attempts = int(row["attempts"]) + 1 if row else 1
                delay = min(base_delay * (2 ** min(attempts - 1, 8)), max_delay)
                self._conn.execute(
                    "UPDATE punch_queue SET attempts = ?, next_retry_at = ?, last_error = ? WHERE local_uuid = ?",
                    (attempts, now_val + delay, error[:1000], uid),
                )

    def reset_pending_backoff(self) -> int:
        """Called when internet connectivity returns so queued records flush immediately."""
        with self._conn:
            cur = self._conn.execute("UPDATE punch_queue SET next_retry_at = 0 WHERE status = 'pending'")
            return cur.rowcount

    def pending_punch_count(self) -> int:
        row = self._conn.execute("SELECT COUNT(*) AS c FROM punch_queue WHERE status = 'pending'").fetchone()
        return int(row["c"]) if row else 0

    def prune_synced(self, older_than_days: int = 30) -> int:
        cutoff = datetime.fromtimestamp(time.time() - older_than_days * 86400, tz=timezone.utc).isoformat()
        with self._conn:
            cur = self._conn.execute("DELETE FROM punch_queue WHERE status = 'synced' AND synced_at < ?", (cutoff,))
            return cur.rowcount

    # ------------------------------------------------------------------
    # Error buffer
    # ------------------------------------------------------------------

    def record_error(
        self,
        error_type: str,
        message: str,
        device_id: Optional[int] = None,
        device_serial: Optional[str] = None,
        level: str = "error",
        context: Optional[Dict[str, Any]] = None,
    ) -> int:
        with self._conn:
            cur = self._conn.execute(
                """
                INSERT INTO error_queue (device_id, device_serial, level, type, message, context_json, occurred_at, synced)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                """,
                (
                    device_id,
                    device_serial,
                    level,
                    error_type[:50],
                    message[:500],
                    json.dumps(context) if context else None,
                    utc_now_iso(),
                ),
            )
            return int(cur.lastrowid)

    def pending_errors(self, limit: int = 50) -> List[Dict[str, Any]]:
        rows = self._conn.execute(
            "SELECT * FROM error_queue WHERE synced = 0 ORDER BY id ASC LIMIT ?",
            (limit,),
        ).fetchall()
        out = []
        for r in rows:
            out.append({
                "id": r["id"],
                "device_id": r["device_id"],
                "device_serial": r["device_serial"],
                "level": r["level"],
                "type": r["type"],
                "message": r["message"],
                "context": json.loads(r["context_json"]) if r["context_json"] else {},
                "occurred_at": r["occurred_at"],
            })
        return out

    def mark_errors_synced(self, ids: List[int]) -> None:
        if not ids:
            return
        placeholders = ",".join("?" for _ in ids)
        with self._conn:
            self._conn.execute(f"UPDATE error_queue SET synced = 1 WHERE id IN ({placeholders})", ids)

    def pending_error_count(self) -> int:
        row = self._conn.execute("SELECT COUNT(*) AS c FROM error_queue WHERE synced = 0").fetchone()
        return int(row["c"]) if row else 0

    # ------------------------------------------------------------------
    # Cursors & cached config state
    # ------------------------------------------------------------------

    def get_cursor(self, device_id: int) -> Dict[str, Optional[str]]:
        row = self._conn.execute("SELECT last_log_at, last_employee_sync_at FROM device_cursors WHERE device_id = ?", (device_id,)).fetchone()
        if not row:
            return {"last_log_at": None, "last_employee_sync_at": None}
        return {"last_log_at": row["last_log_at"], "last_employee_sync_at": row["last_employee_sync_at"]}

    def set_cursor(self, device_id: int, last_log_at: Optional[str] = None, last_employee_sync_at: Optional[str] = None) -> None:
        current = self.get_cursor(device_id)
        with self._conn:
            self._conn.execute(
                """
                INSERT INTO device_cursors (device_id, last_log_at, last_employee_sync_at, updated_at)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(device_id) DO UPDATE SET
                    last_log_at = excluded.last_log_at,
                    last_employee_sync_at = excluded.last_employee_sync_at,
                    updated_at = excluded.updated_at
                """,
                (
                    device_id,
                    last_log_at if last_log_at is not None else current["last_log_at"],
                    last_employee_sync_at if last_employee_sync_at is not None else current["last_employee_sync_at"],
                    utc_now_iso(),
                ),
            )

    def get_state(self, key: str, default: Any = None) -> Any:
        row = self._conn.execute("SELECT value FROM agent_state WHERE key = ?", (key,)).fetchone()
        return json.loads(row["value"]) if row else default

    def set_state(self, key: str, value: Any) -> None:
        with self._conn:
            self._conn.execute(
                """
                INSERT INTO agent_state (key, value, updated_at)
                VALUES (?, ?, ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
                """,
                (key, json.dumps(value), utc_now_iso()),
            )
