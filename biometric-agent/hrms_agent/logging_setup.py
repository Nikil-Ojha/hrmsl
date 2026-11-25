"""Rotating file + console logging setup."""

from __future__ import annotations

import logging
from logging.handlers import RotatingFileHandler
from pathlib import Path


def setup_logging(log_path: Path, level: str = "INFO", max_bytes: int = 5 * 1024 * 1024, backup_count: int = 5) -> logging.Logger:
    log_path.parent.mkdir(parents=True, exist_ok=True)
    logger = logging.getLogger("hrms_agent")
    logger.setLevel(getattr(logging, level.upper(), logging.INFO))
    logger.handlers.clear()

    fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s")

    file_handler = RotatingFileHandler(str(log_path), maxBytes=max_bytes, backupCount=backup_count, encoding="utf-8")
    file_handler.setFormatter(fmt)
    logger.addHandler(file_handler)

    console = logging.StreamHandler()
    console.setFormatter(fmt)
    logger.addHandler(console)

    return logger
