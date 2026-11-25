import hashlib
import hmac
import tempfile
import unittest
from pathlib import Path

from hrms_agent.config import AgentCredentials, CredentialStore
from hrms_agent.security import canonical_string, sign_request, validate_server_url


class SecurityTest(unittest.TestCase):
    def test_requires_https_by_default(self):
        with self.assertRaises(ValueError):
            validate_server_url("http://hrms.example.com")
        self.assertEqual(validate_server_url("https://hrms.example.com/"), "https://hrms.example.com")
        self.assertEqual(validate_server_url("http://127.0.0.1:8000/", allow_insecure_http=True), "http://127.0.0.1:8000")

    def test_hmac_signature_matches_canonical_spec(self):
        body = b'{"logs":[]}'
        headers = sign_request(
            agent_identifier="AGT-TEST1",
            access_token="agt_12345678_secret",
            hmac_secret="deadbeef",
            method="POST",
            url="https://hrms.example.com/api/biometric/agent/v1/logs?foo=bar",
            body=body,
            timestamp=1700000000,
            nonce="abc123456789",
        )
        expected_canonical = canonical_string("POST", "/api/biometric/agent/v1/logs", "1700000000", "abc123456789", body)
        expected_sig = hmac.new(b"deadbeef", expected_canonical.encode("utf-8"), hashlib.sha256).hexdigest()

        self.assertEqual(headers["X-Agent-Id"], "AGT-TEST1")
        self.assertEqual(headers["Authorization"], "Bearer agt_12345678_secret")
        self.assertEqual(headers["X-Agent-Signature"], expected_sig)

    def test_credential_store_roundtrip_and_permissions(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "credentials.dat"
            store = CredentialStore(path)
            creds = AgentCredentials("AGT-1", "agt_abc_xyz", "sec123", "https://hrms.example.com")
            store.save(creds)
            loaded = store.load()
            self.assertEqual(loaded, creds)
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)


if __name__ == "__main__":
    unittest.main()
