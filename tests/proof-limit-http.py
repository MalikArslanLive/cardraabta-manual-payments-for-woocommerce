import requests,json
from bs4 import BeautifulSoup
f=json.load(open('/tmp/crmp-proof-limit-fixture.json'));base='http://127.0.0.1:8089';s=requests.Session()
def check(name,ok):
 assert ok,name
 print('PASS LIMIT HTTP '+name,flush=True)
url=base+'/?page_id='+str(f['page'])+'&key='+f['key'];doc=BeautifulSoup(s.get(url).text,'html.parser');form=doc.select_one('.crmp-proof-form');check('pending form collapsed and remaining quota shown',form is not None and form.has_attr('hidden') and 'you can submit 1 more' in doc.text)
data={e['name']:e.get('value','') for e in form.select('input[type=hidden]')};data.update(crmp_transaction_ref='FINAL-ALLOWED',crmp_payment_date='2026-01-01',crmp_proof_confirm='1');bad=dict(data,order_key='wrong');check('wrong key rejected',s.post(base+'/wp-admin/admin-ajax.php',data=bad).status_code==403)
r=s.post(base+'/wp-admin/admin-ajax.php',data=data);check('third replacement accepted',r.status_code==200 and r.json()['success']);data['proof_version']=str(int(data['proof_version'])+1);data['crmp_transaction_ref']='FOURTH';r=s.post(base+'/wp-admin/admin-ajax.php',data=data);check('server blocks fourth even with valid authorization',r.status_code==409 and not r.json()['success'] and 'No proof updates remaining' in r.json()['data']['message'])
doc=BeautifulSoup(s.get(url).text,'html.parser');check('after reload no upload form at exhausted limit',doc.select_one('.crmp-proof-form') is None and 'you can submit 0 more' in doc.text)
print('RESULT: PROOF LIMIT HTTP CHECKS PASSED',flush=True)
admin=requests.Session();admin.get(base+'/wp-login.php');admin.post(base+'/wp-login.php',data={'log':'auditor','pwd':'test-only-local','wp-submit':'Log In','testcookie':'1'})
settings=base+'/wp-admin/admin.php?page=crmp-settings'
def save_limit(value,nonce=None):
 doc=BeautifulSoup(admin.get(settings).text,'html.parser');form=doc.select_one('#crmp-email-settings-form');data={}
 for e in form.select('input[name]'):
  if e.get('type')=='checkbox' and not e.has_attr('checked'):continue
  data[e['name']]=e.get('value','')
 data.update(proof_update_limit=str(value),crmp_save_general='1')
 if nonce is not None:data['_wpnonce']=nonce
 return admin.post(settings,data=data)
doc=BeautifulSoup(admin.get(settings).text,'html.parser');field=doc.select_one('[name=proof_update_limit]');check('settings has default 3 and 1-to-10 numeric constraints',field['value']=='3' and field['min']=='1' and field['max']=='10' and field['type']=='number')
r=save_limit(10);check('admin saves configured limit 10',BeautifulSoup(r.text,'html.parser').select_one('[name=proof_update_limit]')['value']=='10');doc=BeautifulSoup(s.get(url).text,'html.parser');check('higher limit preserves used count and shows seven remaining','you can submit 7 more' in doc.text and doc.select_one('.crmp-proof-form').has_attr('hidden'))
r=save_limit(0);check('below-minimum POST clamped to one',BeautifulSoup(r.text,'html.parser').select_one('[name=proof_update_limit]')['value']=='1');doc=BeautifulSoup(s.get(url).text,'html.parser');check('lower limit does not reset used attempts','you can submit 0 more' in doc.text and doc.select_one('.crmp-proof-form') is None)
r=save_limit(99);check('above-maximum POST clamped to ten',BeautifulSoup(r.text,'html.parser').select_one('[name=proof_update_limit]')['value']=='10');bad=save_limit(2,'wrong');check('settings invalid nonce blocked','The link you followed has expired' in bad.text);doc=BeautifulSoup(admin.get(settings).text,'html.parser');check('invalid nonce cannot change saved limit',doc.select_one('[name=proof_update_limit]')['value']=='10')
print('RESULT: CONFIGURABLE PROOF LIMIT SETTINGS CHECKS PASSED',flush=True)
