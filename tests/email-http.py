import requests,re,json
from bs4 import BeautifulSoup
base='http://127.0.0.1:8089';s=requests.Session();s.get(base+'/wp-login.php');s.post(base+'/wp-login.php',data={'log':'auditor','pwd':'test-only-local','testcookie':'1'})
def check(n,ok):
 assert ok,n
 print('PASS EMAIL HTTP '+n,flush=True)
url=base+'/wp-admin/admin.php?page=crmp-settings';r=s.get(url);doc=BeautifulSoup(r.text,'html.parser');config=json.loads(re.search(r'var crmpEmails = (.*?);',r.text).group(1));check('settings page has eleven switches and editor',len(doc.select('input[name^="enabled["]'))==11 and doc.select_one('#crmp-email-select') is not None)
open('/tmp/crmp-email-settings-full.html','w').write(r.text)
def ajax(**extra):
 data={'action':'crmp_email_preview','nonce':config['nonce'],'template_id':'customer_rejected','template':json.dumps(config['templates']['customer_rejected'])};data.update(extra);return s.post(base+'/wp-admin/admin-ajax.php',data=data)
r=ajax();
if r.status_code!=200: print('PREVIEW DIAGNOSTIC',r.status_code,r.text[:1000],flush=True)
check('preview returns styled sample email',r.status_code==200 and r.json()['success'] and '12345' in r.json()['data']['html'])
t=config['templates']['customer_rejected'].copy();t.update({'heading':'Custom heading','message':'<p>Hello {customer_name}</p><script>alert(1)</script>','note_label':'Our review','sample_note':'SAMPLE ONLY'})
r=ajax(template=json.dumps(t));html=r.json()['data']['html'];check('unsaved editor values and sample note render safely','Custom heading' in html and 'Hello Alex' in html and 'SAMPLE ONLY' in html and '<script>' not in html)
r=ajax(nonce='bad');check('invalid preview nonce blocked',r.status_code==403)
r=requests.post(base+'/wp-admin/admin-ajax.php',data={'action':'crmp_email_preview','nonce':config['nonce'],'template_id':'customer_rejected'});check('anonymous preview blocked',r.status_code in (400,403))
viewer=requests.Session();viewer.get(base+'/wp-login.php');viewer.post(base+'/wp-login.php',data={'log':'email-viewer','pwd':'local-viewer-only','testcookie':'1'});r=viewer.post(base+'/wp-admin/admin-ajax.php',data={'action':'crmp_email_preview','nonce':config['nonce'],'template_id':'customer_rejected'});check('logged-in non-manager preview blocked',r.status_code==403)
r=ajax(send_test=1,recipient='bad');check('invalid test recipient blocked',r.status_code==400)
r=ajax(send_test=1,recipient='test@example.test');check('test email accepted with isolated intercepted transport',r.json()['success'])
r=ajax(send_test=1,recipient='test@example.test');check('test email rate limited',r.status_code==429)
form=doc.select_one('#crmp-email-settings-form');data={x['name']:x.get('value','') for x in form.select('input[type=hidden]')};data.update({'crmp_save_general':'Save changes','admin_emails':'admin@example.test, team@example.test','delay':'22','max_mb':'6'})
templates=config['templates'];templates['customer_rejected']['heading']='Saved test heading';data['templates']=json.dumps(templates)
for x in doc.select('input[type=checkbox][checked]'):data[x['name']]='1'
r=s.post(url,data=data);check('same-page save succeeds','Settings and email templates saved.' in r.text and 'crmp-settings' in r.url)
d=BeautifulSoup(r.text,'html.parser');saved=json.loads(re.search(r'var crmpEmails = (.*?);',r.text).group(1));check('template and delay persist',saved['templates']['customer_rejected']['heading']=='Saved test heading' and d.select_one('[name=delay]')['value']=='22')
logs=BeautifulSoup(s.get(base+'/wp-admin/admin.php?page=crmp-email-logs').text,'html.parser');failed_button=logs.select_one('.crmp-log-retry');accepted_row=next(row for row in logs.select('tbody tr') if 'Sent to mail server' in row.text);f={'failed':int(failed_button['data-id']),'accepted':int(accepted_row.select_one('.crmp-log-delete')['data-id'])};r=s.post(base+'/wp-admin/admin-ajax.php',data={'action':'crmp_email_retry','nonce':config['nonce'],'job_id':f['accepted']});check('accepted emails cannot be retried',r.status_code==409)
r=s.post(base+'/wp-admin/admin-ajax.php',data={'action':'crmp_email_retry','nonce':config['nonce'],'job_id':f['failed']});check('definite failure can be queued for retry',r.json()['success'])
print('RESULT: EMAIL SETTINGS HTTP CHECKS PASSED')
