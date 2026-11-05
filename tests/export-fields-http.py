import requests,re,csv,io,json
from bs4 import BeautifulSoup
base='http://127.0.0.1:8089';f=json.load(open('/tmp/crmp-export-fields-fixture.json'));s=requests.Session()
def check(name,ok):
 assert ok,name
 print('PASS EXPORT '+name,flush=True)
s.get(base+'/wp-login.php');r=s.post(base+'/wp-login.php',data={'log':'auditor','pwd':'test-only-local','wp-submit':'Log In','testcookie':'1'});check('manager login','wp-admin' in r.url)
r=s.get(base+'/wp-admin/admin.php',params={'page':'crmp-transactions','s':f['order']});doc=BeautifulSoup(r.text,'html.parser');panel=doc.select_one('#crmp-detail-'+str(f['id']));text=panel.get_text(' ',strip=True)
check('dashboard current payment date note method and reviewer note',all(x in text for x in ['2026-02-02','=1+1','JazzCash','Latest review note','Payment date (customer provided)','Customer note:']))
check('dashboard history contains previous fields and decision',all(x in text for x in ['2026-01-01','quoted','اردو details','Earlier review reason','Example Receiving Bank']))
check('dashboard header uses configured method name','JazzCash Account' in panel.select_one('h2 + p').get_text())
check('dashboard records original per-submission settings and context',all(x in text for x in ['Order page','Checkout','Not collected','Confirmed','Screenshot: disabled','Reference: required','Reference: optional']))
check('dashboard current proof version visible','Proof version: 2' in text)
revision=panel.select_one('.crmp-proof-revision');check('old fields grouped with their revision',revision is not None and all(x in revision.get_text(' ',strip=True) for x in ['Revision 1','2026-01-01','Earlier review reason','quoted']))
urls={}
for a in doc.select('a[href]'):
 if 'crmp_export=' in a['href']:urls['history' if 'crmp_export=history' in a['href'] else 'current']=a['href']
check('both exports available',len(urls)==2)
for mode,url in urls.items():
 r=s.get(url);check(mode+' response is CSV',r.status_code==200 and 'text/csv' in r.headers.get('content-type',''))
 rows=list(csv.DictReader(io.StringIO(r.content.decode('utf-8-sig'))));idkey='Transaction record' if mode=='history' else 'ID'
 check(mode+' excludes permanently deleted records',not any(x[idkey]==str(f['purged']) for x in rows) and 'DO-NOT-EXPORT' not in r.text)
 records=[x for x in rows if x[idkey]==str(f['id'])];current=next((x for x in records if x.get('Submission','Current')=='Current'),None)
 if mode=='current':
  closed=next(x for x in rows if x['ID']==str(f['closed_id']));winner=next(x for x in rows if x['ID']==str(f['winner_id']))
  check('closure CSV timestamp and paid-through ledger ID are accurate',closed['Closed at']==f['closed_at'] and closed['Paid through transaction']==str(f['winner_id']) and closed['Status']=='closed')
  check('winning receipt is not falsely labelled closed',winner['Closed at']=='' and winner['Paid through transaction']=='')
 check(mode+' current fields exported',current is not None and current['Payment date']=='2026-02-02' and current['Customer note']=="'=1+1" and current['Method']=='jazzcash' and current['Proof version']=='2')
 check(mode+' current audit fields retained after configuration changes',current['Proof source']=='Order page' and current['Customer confirmation']=='Not collected' and 'Screenshot: disabled' in current['Field settings'] and 'Reference: required' in current['Field settings'])
 check(mode+' reviewer note separate from customer note',current.get('Review note',current.get('Note'))=='Latest review note')
 if mode=='history':
  old=next(x for x in records if x['Submission']=='Previous');check('history preserves old method date customer note and reason',old['Method']=='bank' and old['Payment date']=='2026-01-01' and old['Customer note']==f['old'] and old['Review note']=='Earlier review reason' and old['Proof version']=='1')
  check('history retains checkout policy and confirmation',old['Proof source']=='Checkout' and old['Customer confirmation']=='Confirmed' and 'Reference: optional' in old['Field settings'])
 check(mode+' no public evidence URLs leaked','action=crmp_screenshot' not in r.text and '/crmp-private/' not in r.text)
 check(mode+' invalid nonce blocked',s.get(re.sub(r'_wpnonce=[^&]+','_wpnonce=invalid',url)).status_code==403)
 check(mode+' anonymous cannot export',requests.get(url).headers.get('content-type','').startswith('text/html'))
print('RESULT: CSV AND DASHBOARD NEW FIELD CHECKS PASSED',flush=True)
