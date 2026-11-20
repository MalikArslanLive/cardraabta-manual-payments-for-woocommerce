"""Diagnostic for a disposable site's real authentication; never print cookie values."""
import requests
from bs4 import BeautifulSoup
base='http://127.0.0.1:8089';s=requests.Session()
r=s.get(base+'/wp-login.php',timeout=30)
print('LOGIN GET',r.status_code,r.url,'cookies',[(c.name,c.domain,c.path,c.secure) for c in s.cookies],flush=True)
r=s.post(base+'/wp-login.php',data={'log':'auditor','pwd':'test-only-local','wp-submit':'Log In','testcookie':'1'},timeout=30)
err=BeautifulSoup(r.text,'html.parser').select_one('#login_error')
print('LOGIN POST',r.status_code,r.url,'error',err.get_text(' ',strip=True) if err else None,'cookie names',[c.name for c in s.cookies],flush=True)
assert 'wp-admin' in r.url,'Disposable site manager login failed'
