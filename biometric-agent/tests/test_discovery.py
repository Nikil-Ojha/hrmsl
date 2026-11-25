import socket
import unittest

from hrms_agent.discovery import discover_lan


class DiscoveryTest(unittest.TestCase):
    def test_refuses_public_cidr_and_discovers_local_listener(self):
        # Public CIDR must be ignored for safety
        self.assertEqual(discover_lan(["8.8.8.0/30"], ports=[80], timeout_sec=0.05), [])

        # Bind an ephemeral localhost listener and discover 127.0.0.1/32
        srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv.bind(("127.0.0.1", 0))
        srv.listen(1)
        port = srv.getsockname()[1]
        try:
            # 127.0.0.1 is private in Python ipaddress; /32 has 1 host
            found = discover_lan(["127.0.0.1/32"], ports=[port], timeout_sec=0.2)
            self.assertEqual(len(found), 1)
            self.assertEqual(found[0]["ip_address"], "127.0.0.1")
            self.assertEqual(found[0]["port"], port)
        finally:
            srv.close()


if __name__ == "__main__":
    unittest.main()
