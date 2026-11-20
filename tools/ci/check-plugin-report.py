"""Fail on Plugin Check findings even when its CLI command exits successfully."""
import json,pathlib,sys
report=pathlib.Path(sys.argv[1]).read_text().strip()
if report.startswith('Success: Checks complete. No errors found.'):
    print('PASS PLUGIN CHECK: zero reported errors or warnings (including runtime checks)')
    raise SystemExit(0)
try:
    findings=json.loads(report)
except json.JSONDecodeError:
    raise SystemExit('FAIL PLUGIN CHECK: expected a complete strict-JSON report or the explicit clean result')
if isinstance(findings,list) and not findings:
    print('PASS PLUGIN CHECK: zero reported findings')
    raise SystemExit(0)
print(report)
raise SystemExit('FAIL PLUGIN CHECK: reported findings require review')
