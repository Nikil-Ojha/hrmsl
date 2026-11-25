import tempfile
import unittest
from pathlib import Path

from hrms_agent.storage import LocalStore


class StorageTest(unittest.TestCase):
    def test_enqueue_dedupe_backoff_and_recovery(self):
        with tempfile.TemporaryDirectory() as tmp:
            store = LocalStore(Path(tmp) / "queue.db")
            try:
                added = store.enqueue_punches(1, "SN-1", [
                    {"employee_code": "1001", "punched_at": "2026-11-20T09:00:00+05:30"},
                    {"employee_code": "1001", "punched_at": "2026-11-20T09:00:00+05:30"},  # duplicate
                    {"employee_code": "1002", "punched_at": "2026-11-20T09:05:00+05:30"},
                ])
                self.assertEqual(added, 2)
                self.assertEqual(store.pending_punch_count(), 2)

                batch = store.pending_punches(limit=10, now_ts=1000.0)
                self.assertEqual(len(batch), 2)

                # Simulate offline failure -> exponential backoff
                store.schedule_retry([p.local_uuid for p in batch], "Network down", base_delay=30, now_ts=1000.0)
                self.assertEqual(len(store.pending_punches(limit=10, now_ts=1010.0)), 0)
                self.assertEqual(len(store.pending_punches(limit=10, now_ts=1031.0)), 2)

                # Connectivity returns -> reset backoff immediately
                store.reset_pending_backoff()
                self.assertEqual(len(store.pending_punches(limit=10, now_ts=1010.0)), 2)

                # Sync one, reject one
                store.mark_punches_synced([batch[0].local_uuid])
                store.mark_punch_rejected(batch[1].local_uuid, "Inactive device")
                self.assertEqual(store.pending_punch_count(), 0)
            finally:
                store.close()

    def test_error_buffer_and_cursors(self):
        with tempfile.TemporaryDirectory() as tmp:
            store = LocalStore(Path(tmp) / "queue.db")
            try:
                eid = store.record_error("tcp_timeout", "Device unreachable", device_id=5, device_serial="SN-5")
                self.assertEqual(store.pending_error_count(), 1)
                errs = store.pending_errors()
                self.assertEqual(errs[0]["id"], eid)
                store.mark_errors_synced([eid])
                self.assertEqual(store.pending_error_count(), 0)

                store.set_cursor(5, last_log_at="2026-11-20T10:00:00Z")
                self.assertEqual(store.get_cursor(5)["last_log_at"], "2026-11-20T10:00:00Z")
            finally:
                store.close()


if __name__ == "__main__":
    unittest.main()
