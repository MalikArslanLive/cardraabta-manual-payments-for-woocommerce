#!/usr/bin/env python3
"""Reject diagnostics from the disposable WP_DEBUG-enabled integration site."""
from pathlib import Path
import sys

log = Path(sys.argv[1])
if not log.is_file():
    raise SystemExit("FAIL: expected WordPress debug log was not created")
if log.read_text(errors="replace").strip():
    print("FAIL: WordPress debug log contains diagnostics")
    print(log.read_text(errors="replace")[-6000:])
    raise SystemExit(1)
print("PASS: WordPress debug log contains no diagnostics")
