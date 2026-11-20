#!/usr/bin/env python3
"""Build the installable ZIP from production files only; verify every packaged byte."""
from pathlib import Path
import re,zipfile,hashlib,sys
root=Path(__file__).resolve().parent.parent
slug='cardraabta-manual-payments-for-woocommerce'
version=re.search(r"define\('CRMP_VERSION','([^']+)'\)",(root/(slug+'.php')).read_text()).group(1)
files=[root/n for n in [slug+'.php','readme.txt','LICENSE','uninstall.php']]+sorted((root/'includes').glob('*.php'))+sorted(p for p in (root/'assets').iterdir() if p.is_file())
assert len(files)==20
readme=(root/'readme.txt').read_text();assert 'Stable tag: '+version in readme and len(readme.encode())<10000
out=Path(sys.argv[1]) if len(sys.argv)>1 else root/'release-assets'/f'{slug}-v{version}.zip'
out.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for p in files:
  info=zipfile.ZipInfo(slug+'/'+str(p.relative_to(root)),(2026,11,4,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,p.read_bytes())
with zipfile.ZipFile(out) as z:
 assert z.testzip() is None and len(z.namelist())==len(set(z.namelist()))==20
 for p in files:assert z.read(slug+'/'+str(p.relative_to(root)))==p.read_bytes()
print(hashlib.sha256(out.read_bytes()).hexdigest()+'  '+str(out))
