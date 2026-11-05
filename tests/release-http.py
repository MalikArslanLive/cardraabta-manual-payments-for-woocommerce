import requests,re,json
from bs4 import BeautifulSoup
f=json.load(open('/tmp/crmp-release-fixture.json'));base='http://127.0.0.1:8089';admin=requests.Session();customer=requests.Session()
def check(name,ok):
 assert ok,name
 print('PASS HTTP '+name,flush=True)
def soup(text):return BeautifulSoup(text,'html.parser')
admin.get(base+'/wp-login.php');r=admin.post(base+'/wp-login.php',data={'log':'auditor','pwd':'test-only-local','wp-submit':'Log In','testcookie':'1'});
if 'wp-admin' not in r.url:
 error=soup(r.text).select_one('#login_error');print('LOGIN DIAGNOSTIC',r.status_code,error.get_text(' ',strip=True) if error else 'No login error shown',flush=True)
check('manager login','wp-admin' in r.url)
url=base+'/wp-admin/admin.php?page=crmp-transactions';html=admin.get(url).text;doc=soup(html)
check('top and bottom counts',len(doc.select('.tablenav .displaying-num'))==2)
check('twenty rows by default',len(doc.select('.crmp-select-row'))==20)
check('top editable page and bottom navigation',len(doc.select('.tablenav.top .current-page'))==1 and len(doc.select('.tablenav.bottom .next-page'))==1)
# Search narrows to this order and returns both fixture transactions on one page.
html=admin.get(url+'&paged=2').text;doc=soup(html)
open('/tmp/crmp-release-admin.html','w').write(html)
row=doc.select_one('#crmp-detail-'+str(f['id']));check('pending Review Payment guard marked',row.select_one('[data-pending-review="1"]') is not None)
review_token=row.select_one('[name=_wpnonce]')['value']
def customer_form():
 r=customer.get(base+'/?page_id='+str(f['page'])+'&key='+f['key']);d=soup(r.text);form=d.select_one('.crmp-proof-form');return d,dict({e['name']:e.get('value','') for e in form.select('input[type=hidden]')},crmp_proof_confirm='1',crmp_payment_date='2026-01-01')
doc,data=customer_form();check('customer same-record replacement form',data['replace_id']==str(f['id']))
bad=dict(data);bad['order_key']='wrong';bad['crmp_transaction_ref']='BAD';check('wrong order key blocked',customer.post(base+'/wp-admin/admin-ajax.php',data=bad).status_code==403)
bad=dict(data);bad['_wpnonce']='bad';bad['crmp_transaction_ref']='BAD';check('invalid nonce blocked',customer.post(base+'/wp-admin/admin-ajax.php',data=bad).status_code==403)
data['crmp_transaction_ref']='HTTP-LATEST-PROOF';result=customer.post(base+'/wp-admin/admin-ajax.php',data=data).json();check('customer replacement accepted',result['success'])
doc,data=customer_form();check('same ID latest proof and version',data['replace_id']==str(f['id']) and data['proof_version']=='2' and 'HTTP-LATEST-PROOF' in doc.select_one('.crmp-payment-result').text)
r=admin.post(base+'/wp-admin/admin-ajax.php',data={'action':'crmp_review','id':f['id'],'proof_version':1,'decision':'verified','_wpnonce':review_token});result=r.json();check('stale review returns inline JSON warning',r.status_code==409 and not result['success'] and 'Payment proof changed' in result['data']['message'] and 'view=' in result['data']['reloadUrl'] and 's=' not in result['data']['reloadUrl'])
r=admin.post(base+'/wp-admin/admin-post.php',data={'action':'crmp_review','id':f['id'],'proof_version':1,'decision':'rejected','_wpnonce':review_token});check('fallback stays in dashboard with notice','wp-admin/admin.php?' in r.url and 'notice-warning' in r.text and 'Payment proof changed' in r.text)

def bulk(op,note=''):
 html=admin.get(url+'&paged=2').text
 conf=json.loads(re.search(r'var crmpBulk = (.*?);',html).group(1));auth=json.loads(re.search(r'var crmpAdmin = (.*?);',html).group(1));payload={'action':'crmp_manage_transactions','operation':op,'nonce':auth['nonce'],'bulk_nonce':conf['nonce'],'note':note,'page_ids[]':conf['ids'],'ids[]':[f['id'],f['eligible']] if op=='bulk-trash' else [f['id']]}
 payload.update({'versions['+str(k)+']':v for k,v in conf['versions'].items()});return admin.post(base+'/wp-admin/admin-ajax.php',data=payload).json()
r=bulk('bulk-trash');check('mixed bulk skips Pending',r['success'] and len(r['data']['processed'])==1 and str(f['id']) in r['data']['skipped'])
r=bulk('bulk-reject');check('bulk rejection requires reason',not r['success'])
r=bulk('bulk-reject','Receipt is unreadable. Please replace it.');check('bulk rejection accepted',r['success'] and len(r['data']['processed'])==1)
doc,data=customer_form();check('rejected replacement retains ID and reason',data['replace_id']==str(f['id']) and 'Receipt is unreadable' in doc.text)
html=admin.get(url+'&trash=1').text;check('Trash has two count/navigation areas',len(soup(html).select('.tablenav .displaying-num'))==2)
print('RESULT: HTTP authorization, customer replacement, stale review, native pagination and bulk checks passed')

open('/tmp/crmp-release-checkout.html','w').write(customer.get(base+'/?page_id='+str(f['checkout'])).text)

import csv,io
html=admin.get(url).text;doc=soup(html);link=next(a['href'] for a in doc.select('a') if a.text=='Export Proof History')
r=admin.get(link);rows=list(csv.DictReader(io.StringIO(r.text)));mine=[x for x in rows if x['Transaction record']==str(f['id'])]
check('history CSV includes current and previous submissions',len(mine)==2 and {x['Submission'] for x in mine}=={'Current','Previous'} and {x['Proof version'] for x in mine}=={'1','2'})
check('history CSV includes review decision and reason',any(x['Proof status']=='rejected' and 'Receipt is unreadable' in x['Review note'] for x in mine))
check('history CSV is manager-only',customer.get(link).headers.get('Content-Type','').split(';')[0]!='text/csv')
check('checkout labels identify account details','Account title' in open('/tmp/crmp-release-checkout.html').read() and 'Account number / IBAN' in open('/tmp/crmp-release-checkout.html').read())

r=bulk('bulk-approve','Funds received. Thank you.');check('bulk approval HTTP applies shared note',r['success'] and len(r['data']['processed'])==1)
doc=soup(customer.get(base+'/?page_id='+str(f['page'])+'&key='+f['key']).text);check('customer sees approved status and approval note','Payment Verified' in doc.text and 'Funds received. Thank you.' in doc.text and doc.select_one('.crmp-proof-form') is None)

html=admin.get(url+'&paged=2').text;doc=soup(html);form=doc.select_one('#crmp-detail-'+str(f['single'])+' .crmp-review-form');data={e['name']:e.get('value','') for e in form.select('input[type=hidden]')}
check('review form remembers current list page without auto search',data['list_paged']=='2' and data['list_s']=='')
data.update({'decision':'rejected','note':'Single reject','list_s':'MANUAL-SEARCH','list_status':'pending','list_paged':'2'})
r=admin.post(base+'/wp-admin/admin-ajax.php',data=data).json();check('single reject preserves manual search and filters',r['success'] and 's=MANUAL-SEARCH' in r['data']['redirect'] and 'status=pending' in r['data']['redirect'] and 'paged=2' in r['data']['redirect'])
data.update({'decision':'verified','note':'Single approve','list_s':'','list_status':''})
r=admin.post(base+'/wp-admin/admin-ajax.php',data=data).json();check('single approve keeps page without adding search',r['success'] and 's=' not in r['data']['redirect'] and 'paged=2' in r['data']['redirect'])
html=admin.get(r['data']['redirect']).text;doc=soup(html);check('search stays empty and other transactions remain visible',doc.select_one('.crmp-filters input[name=s]').get('value','')=='' and len(doc.select('.crmp-toggle-details'))>1)
