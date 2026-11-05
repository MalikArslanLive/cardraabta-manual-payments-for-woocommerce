import requests,re,json
from bs4 import BeautifulSoup
base='http://127.0.0.1:8089';s=requests.Session();s.get(base+'/wp-login.php');s.post(base+'/wp-login.php',data={'log':'auditor','pwd':'test-only-local','testcookie':'1'})
def ck(n,ok):
 assert ok,n
 print('PASS LOG HTTP '+n,flush=True)
url=base+'/wp-admin/admin.php?page=crmp-email-logs'
def page(query=''):
 r=s.get(url+query);doc=BeautifulSoup(r.text,'html.parser');cfg=json.loads(re.search(r'var crmpEmailLogs = (.*?);',r.text).group(1));return doc,cfg,r.text
d,c,h=page();total=int(re.search(r'[\d,]+',d.select_one('.displaying-num').text).group(0).replace(',',''));open('/tmp/crmp-email-log-page.html','w').write(h);ck('default twenty rows and both pagers',len(d.select('.crmp-log-select'))==20 and len(d.select('.displaying-num'))==2);ck('cleanup on by default',d.select_one('#crmp-log-auto-cleanup').has_attr('checked'))
d2,c2,h2=page('&paged=2');ck('second page shows the correct remaining logs',len(d2.select('.crmp-log-select'))==min(20,total-20))
def api(op,**extra):
 data={'action':'crmp_email_log_manage','nonce':c['nonce'],'operation':op};data.update(extra);return s.post(base+'/wp-admin/admin-ajax.php',data=data)
r=api('delete',**{'ids[]':[c2['ids'][0]],'page_ids[]':c['ids'],'selection_nonce':c['selectionNonce']});ck('off-page deletion rejected',r.status_code==403)
r=api('delete',**{'ids[]':c['ids'][:2],'page_ids[]':c['ids'],'selection_nonce':c['selectionNonce']});ck('bulk deletion succeeds',r.json()['success']);d,c,h=page();ck('deleted logs no longer listed',len(d.select('.displaying-num'))==2 and str(total-2)+' items' in d.text)
r=api('retention',enabled=0);ck('cleanup can be disabled',r.json()['success']);d,c,h=page();ck('disabled preference persists',not d.select_one('#crmp-log-auto-cleanup').has_attr('checked'));api('retention',enabled=1)
r=s.post(base+'/wp-admin/admin-ajax.php',data={'action':'crmp_email_log_manage','nonce':'bad','operation':'clear'});ck('clear-all invalid nonce blocked',r.status_code==403)
r=api('clear');ck('clear all pages succeeds',r.json()['success']);d,c,h=page();ck('empty log page still shows totals','No email logs found.' in d.text and len(d.select('.displaying-num'))==2)
print('RESULT: EMAIL LOG HTTP CHECKS PASSED')
