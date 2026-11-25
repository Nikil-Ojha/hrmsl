"""Safe local LAN subnet & USB interface discovery for biometric devices."""

from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor, as_completed
import glob
import ipaddress
import socket
import sys
from typing import Any, Dict, Iterable, List, Optional


PORT_HINTS: Dict[int, Dict[str, str]] = {
    4370: {"protocol_hint": "zk_adms", "manufacturer_hint": "ZKTeco / Compatible"},
    5005: {"protocol_hint": "fkweb", "manufacturer_hint": "Realsoft / FK"},
    8080: {"protocol_hint": "local_agent", "manufacturer_hint": "HTTP Biometric Device"},
    80: {"protocol_hint": "local_agent", "manufacturer_hint": "HTTP Device"},
    443: {"protocol_hint": "native", "manufacturer_hint": "HTTPS Device"},
}


def discover_lan(
    cidrs: Iterable[str],
    ports: Iterable[int] = (4370, 5005, 8080),
    timeout_sec: float = 0.4,
    max_hosts_per_cidr: int = 256,
    max_workers: int = 32,
) -> List[Dict[str, Any]]:
    """Probe private IPv4 subnets for open biometric device ports."""
    targets: List[tuple[str, int]] = []
    port_list = [int(p) for p in ports if 1 <= int(p) <= 65535]

    for raw_cidr in cidrs:
        try:
            net = ipaddress.ip_network(raw_cidr.strip(), strict=False)
        except ValueError:
            continue

        # Safety guard: only scan private / link-local office networks.
        if not net.is_private:
            continue

        hosts = list(net.hosts())[:max_hosts_per_cidr]
        for host in hosts:
            ip_str = str(host)
            for port in port_list:
                targets.append((ip_str, port))

    if not targets:
        return []

    def probe(ip_str: str, port: int) -> Optional[Dict[str, Any]]:
        try:
            with socket.create_connection((ip_str, port), timeout=timeout_sec):
                hint = PORT_HINTS.get(port, {})
                return {
                    "fingerprint": f"lan:{ip_str}:{port}",
                    "connection_type": "lan",
                    "ip_address": ip_str,
                    "port": port,
                    "protocol_hint": hint.get("protocol_hint"),
                    "manufacturer_hint": hint.get("manufacturer_hint"),
                    "metadata": {"open_port": port},
                }
        except OSError:
            return None

    found: List[Dict[str, Any]] = []
    with ThreadPoolExecutor(max_workers=min(max_workers, len(targets))) as pool:
        futures = [pool.submit(probe, ip_str, port) for ip_str, port in targets]
        for fut in as_completed(futures):
            res = fut.result()
            if res:
                found.append(res)

    found.sort(key=lambda x: (x["ip_address"], x["port"]))
    return found


def discover_usb() -> List[Dict[str, Any]]:
    """Enumerate local USB serial interfaces (Windows registry / Linux dev nodes)."""
    found: List[Dict[str, Any]] = []

    if sys.platform == "win32":
        try:
            import winreg  # type: ignore[import-not-found]
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"HARDWARE\DEVICEMAP\SERIALCOMM") as key:
                i = 0
                while True:
                    try:
                        name, port_name, _ = winreg.EnumValue(key, i)
                        found.append({
                            "fingerprint": f"usb:{port_name}",
                            "connection_type": "usb",
                            "usb_path": str(port_name),
                            "protocol_hint": "local_agent",
                            "manufacturer_hint": "USB Serial",
                            "metadata": {"device_name": str(name)},
                        })
                        i += 1
                    except OSError:
                        break
        except OSError:
            pass
    else:
        for pattern in ("/dev/ttyUSB*", "/dev/ttyACM*"):
            for path in sorted(glob.glob(pattern)):
                found.append({
                    "fingerprint": f"usb:{path}",
                    "connection_type": "usb",
                    "usb_path": path,
                    "protocol_hint": "local_agent",
                    "manufacturer_hint": "USB Serial",
                    "metadata": {},
                })

    return found
