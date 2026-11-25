"""Command-line interface for provisioning, running and inspecting the Local Biometric Agent."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys
from typing import List, Optional

from . import __version__
from .api_client import ApiError, HrmsApiClient
from .config import AgentConfig, AgentCredentials, CredentialStore, default_data_dir
from .discovery import discover_lan, discover_usb
from .drivers import DriverRegistry
from .logging_setup import setup_logging
from .runner import AgentRunner
from .storage import LocalStore


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="hrms-biometric-agent", description="HRMS Local Biometric Device Communication Agent")
    p.add_argument("--version", action="version", version=f"%(prog)s {__version__}")
    p.add_argument("--config", type=Path, help="Path to agent config.json")
    p.add_argument("--data-dir", type=Path, help="Directory for SQLite queue, credentials and logs")

    sub = p.add_subparsers(dest="command", required=True)

    prov = sub.add_parser("provision", help="Exchange one-time HRMS provisioning token for secure agent credentials")
    prov.add_argument("--server", required=True, help="HRMS HTTPS base URL (e.g. https://hrms.example.com)")
    prov.add_argument("--agent-id", required=True, help="Agent identifier from HRMS (e.g. AGT-XXXXXXXX)")
    prov.add_argument("--token", required=True, help="One-time provisioning token from HRMS")
    prov.add_argument("--allow-insecure-http", action="store_true", help="Allow HTTP (local development only)")
    prov.add_argument("--no-verify-tls", action="store_true", help="Disable TLS verification (local development only)")

    run = sub.add_parser("run", help="Run the biometric agent service loop")
    run.add_argument("--once", action="store_true", help="Run a single sync/command/heartbeat cycle and exit")

    sub.add_parser("status", help="Show local agent provisioning status, queue depth and loaded drivers")

    disc = sub.add_parser("discover", help="Scan local LAN subnets and USB ports for biometric devices")
    disc.add_argument("--cidr", action="append", default=[], help="Private IPv4 CIDR subnet to scan (repeatable)")
    disc.add_argument("--ports", default="4370,5005,8080", help="Comma-separated TCP ports to probe")

    return p


def _config_path(args: argparse.Namespace) -> Path:
    if args.config:
        return args.config
    data_dir = args.data_dir or default_data_dir()
    return Path(data_dir) / "config.json"


def _load_config(args: argparse.Namespace) -> AgentConfig:
    cfg_path = _config_path(args)
    if not cfg_path.exists():
        raise SystemExit(f"Config not found at {cfg_path}. Run `provision` first.")
    cfg = AgentConfig.from_file(cfg_path)
    if args.data_dir:
        cfg.data_dir = args.data_dir.expanduser().resolve()
    return cfg


def main(argv: Optional[List[str]] = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    if args.command == "provision":
        data_dir = (args.data_dir or default_data_dir()).expanduser().resolve()
        cfg = AgentConfig(
            server_url=args.server,
            data_dir=data_dir,
            allow_insecure_http=args.allow_insecure_http,
            verify_tls=not args.no_verify_tls,
        )
        setup_logging(cfg.log_path, cfg.log_level)
        drivers = DriverRegistry(cfg.plugins_dir, cfg.driver_overrides)
        api = HrmsApiClient(cfg)

        try:
            resp = api.provision(args.agent_id, args.token, capabilities={"drivers": drivers.names()})
        except ApiError as exc:
            print(f"Provisioning failed: {exc}", file=sys.stderr)
            return 1

        data = resp["data"]
        creds = AgentCredentials(
            agent_identifier=data["agent_identifier"],
            access_token=data["access_token"],
            hmac_secret=data["hmac_secret"],
            server_url=cfg.server_url,
        )
        CredentialStore(cfg.credentials_path).save(creds)
        cfg.to_file(_config_path(args))
        print(f"Provisioned agent {creds.agent_identifier} ({data.get('agent_name')}) successfully.")
        print(f"Credentials stored securely at {cfg.credentials_path}")
        return 0

    if args.command == "discover":
        ports = [int(x.strip()) for x in args.ports.split(",") if x.strip().isdigit()]
        results = discover_lan(args.cidr, ports) + discover_usb()
        print(json.dumps(results, indent=2))
        return 0

    cfg = _load_config(args)
    setup_logging(cfg.log_path, cfg.log_level, cfg.log_max_bytes, cfg.log_backup_count)
    creds = CredentialStore(cfg.credentials_path).load()
    drivers = DriverRegistry(cfg.plugins_dir, cfg.driver_overrides)
    store = LocalStore(cfg.db_path)

    try:
        if args.command == "status":
            print(json.dumps({
                "version": __version__,
                "server_url": cfg.server_url,
                "provisioned": creds is not None,
                "agent_identifier": creds.agent_identifier if creds else None,
                "data_dir": str(cfg.data_dir),
                "pending_punches": store.pending_punch_count(),
                "pending_errors": store.pending_error_count(),
                "drivers": drivers.names(),
            }, indent=2))
            return 0

        if not creds:
            print("Agent credentials not found. Run `provision` first.", file=sys.stderr)
            return 1

        api = HrmsApiClient(cfg, creds)
        runner = AgentRunner(cfg, api, store, drivers)
        if args.once:
            summary = runner.run_once()
            print(json.dumps(summary, indent=2))
        else:
            runner.run_forever()
        return 0
    finally:
        store.close()
