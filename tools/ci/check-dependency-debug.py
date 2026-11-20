#!/usr/bin/env python3
"""Validate the separately recorded optional WooCommerce dependency startup log."""
from pathlib import Path
import re
import sys

lines = [line for line in Path(sys.argv[1]).read_text(errors="replace").splitlines() if line.strip()]
known_notice = re.compile(
    r"\[[^\]]+\] PHP Notice:  Function _load_textdomain_just_in_time was called .*"
    r"Translation loading for the <code>woocommerce</code> domain was triggered too early\. .*"
    r" in [^\r\n]+/wp-includes/functions\.php on line \d+"
)
unexpected = [line for line in lines if not known_notice.fullmatch(line)]
if unexpected:
    print("FAIL: unexpected optional dependency initialization diagnostics")
    print("\n".join(unexpected))
    raise SystemExit(1)
print(f"PASS: dependency baseline recorded ({len(lines)} known WooCommerce startup notices)")
