"""Real HTTP checks; requires tests/server-security.php on a disposable site."""
import hashlib,json,os
from urllib.parse import urlparse,parse_qs
import requests
from bs4 import BeautifulSoup
base='http://127.0.0.1:8089'
f=json.load(open('/tmp/crmp-server-fixture.json'))
def check(label,condition):
    assert condition,label
    print('PASS SERVER '+label,flush=True)
r=requests.get(base+'/?crmp_environment_check=1',timeout=30);r.raise_for_status();runtime=r.json()
check('HTTP PHP matches selected CLI version',runtime['php'].startswith(f['expected_php']+'.'))
expected=os.environ.get('CRMP_WEB_SERVER','').lower()
software=runtime['server'].lower()
check('expected server handles PHP', {'apache2':'apache','nginx':'nginx','openlitespeed':'litespeed'}[expected] in software)
print('::notice title=HTTP runtime::'+json.dumps(runtime,sort_keys=True),flush=True)
with open('/tmp/crmp-runtime-versions.json','w') as out: json.dump(runtime,out,sort_keys=True)
for label,url in [('encrypted evidence',f['encrypted_url']),('readable sentinel',f['sentinel_url']),('private directory',f['directory_url'])]:
    for variant in [url,url+'?cache=1',url.replace('cardraabta-manual-payments-for-woocommerce','cardraabta%2dmanual-payments-for-woocommerce')]:
        r=requests.get(variant,timeout=30)
        check(label+' denied: '+urlparse(variant).path,r.status_code in (403,404) and f['sentinel'].encode() not in r.content)
s=requests.Session();s.get(base+'/wp-login.php',timeout=30)
r=s.post(base+'/wp-login.php',data={'log':'auditor','pwd':'test-only-local','wp-submit':'Log In','testcookie':'1'},timeout=30)
check('manager authenticates','wp-admin' in r.url)
r=s.get(base+'/wp-admin/admin.php?page=crmp-transactions&s=SERVER-',timeout=30)
doc=BeautifulSoup(r.text,'html.parser');links=[a['href'] for a in doc.select('a[href]') if 'action=crmp_screenshot' in a['href'] and parse_qs(urlparse(a['href']).query).get('id')==[str(f['id'])]]
check('manager gets authorized screenshot link',bool(links))
r=s.get(links[0],timeout=30)
check('manager receives decrypted original image',r.status_code==200 and hashlib.sha256(r.content).hexdigest()==f['png_sha256'])
check('evidence response forbids caching and sniffing','no-store' in r.headers.get('Cache-Control','') and r.headers.get('X-Content-Type-Options')=='nosniff')
invalid_link=next(a['href'] for a in doc.select('a[href]') if 'action=crmp_screenshot' in a['href'] and parse_qs(urlparse(a['href']).query).get('id')==[str(f['invalid_id'])])
invalid_response=s.get(invalid_link,timeout=30)
check('manager endpoint rejects plaintext evidence',hashlib.sha256(invalid_response.content).hexdigest()!=f['png_sha256'] and not invalid_response.headers.get('Content-Type','').startswith('image/'))
r=requests.get(links[0],timeout=30)
check('anonymous cannot reuse manager screenshot link',hashlib.sha256(r.content).hexdigest()!=f['png_sha256'] and not r.headers.get('Content-Type','').startswith('image/'))
print('RESULT: REAL SERVER EVIDENCE ACCESS CHECKS PASSED',flush=True)
# An Editor's normal Media Library rights must not permit evidence destruction.
editor=requests.Session();editor.get(base+'/wp-login.php',timeout=30)
r=editor.post(base+'/wp-login.php',data={'log':'server-media-editor','pwd':'test-only-editor','testcookie':'1'},timeout=30)
check('non-manager editor authenticates','wp-admin' in r.url)
rest_nonce=editor.get(base+'/wp-admin/admin-ajax.php?action=rest-nonce',timeout=30).text.strip()
check('editor obtains its own REST nonce',len(rest_nonce)==10 and rest_nonce.isalnum())
# Resolve the evidence attachment ID through the manager-only current CSV.
export=next((a['href'] for a in doc.select('a[href]') if 'crmp_export=1' in a['href']),None)
check('manager export link is available',bool(export))
import csv,io
rows=list(csv.DictReader(io.StringIO(s.get(export,timeout=30).text)))
attachment=next(row['Screenshot ID'] for row in rows if row['ID']==str(f['id']))
media=base+'/index.php?rest_route=/wp/v2/media/'+attachment
r=editor.get(media+'&context=edit',headers={'X-WP-Nonce':rest_nonce},timeout=30)
check('editor cannot read private evidence through Media REST',r.status_code==403)
r=editor.delete(media+'&force=true',headers={'X-WP-Nonce':rest_nonce},timeout=30)
check('editor cannot delete evidence through Media REST',r.status_code==403)
r=s.get(links[0],timeout=30);check('denied editor deletion preserves original evidence',hashlib.sha256(r.content).hexdigest()==f['png_sha256'])
# Real multipart upload validation, using the actual customer form and guest authorization.
import base64,time
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
guest=requests.Session()
proof_url=base+'/?page_id='+str(f['proof_page'])+'&key='+f['key']
proof_response=guest.get(proof_url,timeout=30);check('private proof page forbids caching','no-store' in proof_response.headers.get('Cache-Control',''));doc=BeautifulSoup(proof_response.text,'html.parser');form=doc.select_one('.crmp-proof-form')
check('customer proof form is available',form is not None)
data={e['name']:e.get('value','') for e in form.select('input[type=hidden]')}
data.update(crmp_payment_date='2026-01-01',crmp_proof_confirm='1',crmp_proof_method='bank')
def upload(values,filename='proof.png',content=png,mime='image/png'):
    return guest.post(base+'/wp-admin/admin-ajax.php',data=values,files={'crmp_screenshot':(filename,content,mime)},timeout=30)
r=upload(dict(data,_wpnonce='invalid'));check('multipart upload rejects bad nonce',r.status_code==403)
r=upload(dict(data,order_key='wrong'));check('multipart upload rejects wrong order key',r.status_code==403)
missing=dict(data);missing.pop('crmp_proof_ui',None);missing.pop('crmp_payment_date',None)
r=upload(missing);check('multipart upload enforces configured date without UI marker',r.status_code==400 and not r.json()['success'])
r=upload(data,'proof.php',b'<?php echo "unsafe";','application/x-httpd-php');check('executable upload rejected',r.status_code==400 and not r.json()['success'])
r=upload(data);check('real uploaded PNG accepted',r.status_code==200 and r.json()['success'])
r=upload(data);check('stale multipart proof cannot overwrite latest',r.status_code==409 and not r.json()['success'])
doc=BeautifulSoup(guest.get(proof_url,timeout=30).text,'html.parser');check('upload increments existing proof version',doc.select_one('[name=proof_version]')['value']=='2')
r=s.get(links[0],timeout=30);check('manager can decrypt the HTTP-uploaded screenshot',r.status_code==200 and hashlib.sha256(r.content).hexdigest()==f['png_sha256'])
# Actual Classic Checkout, not just generated payment-method markup.
classic=requests.Session()
r=classic.post(base+'/?wc-ajax=add_to_cart',data={'product_id':f['product'],'quantity':1},timeout=30);cart=r.json()
check('Classic AJAX adds purchasable product to cart',not cart.get('error') and bool(cart.get('cart_hash')))

checkout=base+'/?page_id='+str(f['checkout_page']);doc=BeautifulSoup(classic.get(checkout,timeout=30).text,'html.parser')
nonce=doc.select_one('[name=woocommerce-process-checkout-nonce]')
if nonce is None:
    content=doc.select_one('.woocommerce') or doc
    print('CLASSIC CHECKOUT DIAGNOSTIC',content.get_text(' ',strip=True)[:1800],flush=True)
check('Classic checkout form renders with gateway',nonce is not None and doc.select_one('[name=payment_method][value=crmp_manual]') is not None)
billing={'first_name':'Test','last_name':'Customer','email':'server@example.test','phone':'03000000000','country':'PK','state':'PB','address_1':'Test street','address_2':'','city':'Lahore','postcode':'54000'}
payload={'billing_'+k:v for k,v in billing.items()};payload.update({'woocommerce-process-checkout-nonce':nonce['value'],'payment_method':'crmp_manual','crmp_selected_method':'bank','crmp_transaction_ref':'CLASSIC-'+str(time.time_ns()),'crmp_payment_date':'2026-01-01','terms':'1','ship_to_different_address':'0'})
check('default checkout shows screenshot and date only',doc.select_one('[name=crmp_screenshot]') is not None and doc.select_one('[name=crmp_payment_date]') is not None and doc.select_one('[name=crmp_transaction_ref]') is None and doc.select_one('[name=crmp_customer_note]') is None and doc.select_one('[name=crmp_proof_confirm]') is None)
r=classic.post(base+'/?wc-ajax=checkout',data=payload,timeout=60);check('Classic checkout rejects missing screenshot',r.json().get('result')=='failure')
payload['crmp_screenshot_data']='data:image/png;base64,'+base64.b64encode(png).decode()
r=classic.post(base+'/?wc-ajax=checkout',data=payload,timeout=60);result=r.json()
check('Classic checkout creates manual-payment order',result.get('result')=='success')
r=classic.get(result['redirect'],timeout=30);check('Classic checkout proof is pending review','Payment Under Review' in r.text)
# Store API is the payment flow used by Checkout Blocks.
blocks=requests.Session();api=base+'/index.php?rest_route=/wc/store/v1/'
r=blocks.get(api+'cart',timeout=30);r.raise_for_status();token=r.headers.get('Cart-Token');check('Store API issues a cart token',bool(token))
headers={'Cart-Token':token}
if r.headers.get('Nonce'): headers['Nonce']=r.headers['Nonce']
r=blocks.post(api+'cart/add-item',headers=headers,json={'id':f['product'],'quantity':1},timeout=30)
if r.status_code not in (200,201): print('STORE CART DIAGNOSTIC',r.status_code,r.text[:1200],flush=True)
check('Blocks cart accepts virtual product',r.status_code in (200,201))
if r.headers.get('Nonce'): headers['Nonce']=r.headers['Nonce']
body={'billing_address':billing,'shipping_address':{k:v for k,v in billing.items() if k not in ('email','phone')},'payment_method':'crmp_manual','payment_data':[{'key':'crmp_selected_method','value':'bank'},{'key':'crmp_transaction_ref','value':'BLOCKS-'+str(time.time_ns())},{'key':'crmp_payment_date','value':'2026-01-01'}]}
r=blocks.post(api+'checkout',headers=headers,json=body,timeout=60);check('Store API rejects missing screenshot',r.status_code>=400 or r.json().get('payment_result',{}).get('payment_status')!='success')
if r.headers.get('Nonce'): headers['Nonce']=r.headers['Nonce']
body['payment_data'].append({'key':'crmp_screenshot_data','value':'data:image/png;base64,'+base64.b64encode(png).decode()})
r=blocks.post(api+'checkout',headers=headers,json=body,timeout=60);result=r.json()
if r.status_code not in (200,201): print('STORE CHECKOUT DIAGNOSTIC',r.status_code,result.get('code'),result.get('message'),flush=True)
check('Blocks Store API checkout succeeds',r.status_code in (200,201) and result.get('payment_result',{}).get('payment_status')=='success')
check('Blocks order awaits manual verification',result.get('status')=='on-hold')
print('RESULT: MULTIPART, CLASSIC AND BLOCKS SERVER CHECKS PASSED',flush=True)
# Native multipart requests exercise the server without relying on FileReader/HTML validation.
native=requests.Session();native.post(base+'/?wc-ajax=add_to_cart',data={'product_id':f['product'],'quantity':1},timeout=30)
native_doc=BeautifulSoup(native.get(checkout,timeout=30).text,'html.parser');native_payload={'billing_'+k:v for k,v in billing.items()};native_payload.update({'woocommerce-process-checkout-nonce':native_doc.select_one('[name=woocommerce-process-checkout-nonce]')['value'],'payment_method':'crmp_manual','crmp_selected_method':'bank','crmp_payment_date':'2026-01-01','terms':'1'})
r=native.post(base+'/?wc-ajax=checkout',data=native_payload,files={'crmp_screenshot':('proof.php',png,'image/png')},timeout=60);check('native checkout rejects image with executable extension',r.json().get('result')=='failure')
r=native.post(base+'/?wc-ajax=checkout',data=native_payload,files={'crmp_screenshot':('large.png',png+b'x'*(5*1024*1024),'image/png')},timeout=60);check('native checkout rejects oversized screenshot',r.json().get('result')=='failure')
r=native.post(base+'/?wc-ajax=checkout',data=native_payload,files={'crmp_screenshot':('proof.png',png,'image/png')},timeout=60);check('native multipart checkout accepts valid proof without client base64',r.json().get('result')=='success')
# An authenticated merchant can explicitly allow empty-proof checkout.
settings_url=base+'/wp-admin/admin.php?page=crmp-settings'
settings_doc=BeautifulSoup(s.get(settings_url,timeout=30).text,'html.parser');settings_form=settings_doc.select_one('#crmp-email-settings-form');settings_data={}
for e in settings_form.select('input[name]'):
    if e.get('type')=='checkbox' and not e.has_attr('checked'):continue
    settings_data[e['name']]=e.get('value','')
settings_data.pop('require_checkout_proof',None);settings_data['crmp_save_general']='1'
r=s.post(settings_url,data=settings_data,timeout=30);check('merchant saves optional checkout mode','Settings and email templates saved.' in r.text)
optional=requests.Session();optional.post(base+'/?wc-ajax=add_to_cart',data={'product_id':f['product'],'quantity':1},timeout=30)
optional_doc=BeautifulSoup(optional.get(checkout,timeout=30).text,'html.parser')
check('optional checkout fields do not have unconditional HTML required',not optional_doc.select_one('[name=crmp_screenshot]').has_attr('required') and not optional_doc.select_one('[name=crmp_payment_date]').has_attr('required'))
optional_payload={'billing_'+k:v for k,v in billing.items()};optional_payload.update({'woocommerce-process-checkout-nonce':optional_doc.select_one('[name=woocommerce-process-checkout-nonce]')['value'],'payment_method':'crmp_manual','crmp_selected_method':'bank','terms':'1'})
r=optional.post(base+'/?wc-ajax=checkout',data=optional_payload,timeout=60);optional_result=r.json();check('Classic optional mode permits empty proof',optional_result.get('result')=='success')
order_doc=BeautifulSoup(optional.get(optional_result['redirect'],timeout=30).text,'html.parser');later_form=order_doc.select_one('.crmp-proof-form');check('deferred order offers collapsed later-proof form',later_form is not None and later_form.has_attr('hidden'))
later={e['name']:e.get('value','') for e in later_form.select('input[type=hidden]')};later.update(crmp_proof_method='bank',crmp_payment_date='2026-01-01')
r=optional.post(base+'/wp-admin/admin-ajax.php',data=later,timeout=30);check('later submission still rejects missing proof in optional checkout mode',r.status_code==400 and not r.json()['success'])
r=optional.post(base+'/wp-admin/admin-ajax.php',data=later,files={'crmp_screenshot':('later-proof.png',png,'image/png')},timeout=30);check('later valid proof accepted for deferred order',r.status_code==200 and r.json()['success'])
# Blocks takes the same merchant decision from the server, even when JS/required attributes are absent.
optional_blocks=requests.Session();r=optional_blocks.get(api+'cart',timeout=30);optional_headers={'Cart-Token':r.headers['Cart-Token']}
if r.headers.get('Nonce'):optional_headers['Nonce']=r.headers['Nonce']
r=optional_blocks.post(api+'cart/add-item',headers=optional_headers,json={'id':f['product'],'quantity':1},timeout=30);check('optional Blocks cart accepts product',r.status_code in (200,201))
if r.headers.get('Nonce'):optional_headers['Nonce']=r.headers['Nonce']
optional_body=dict(body);optional_body['payment_data']=[{'key':'crmp_selected_method','value':'bank'}]
r=optional_blocks.post(api+'checkout',headers=optional_headers,json=optional_body,timeout=60);optional_block_result=r.json();check('Blocks optional mode permits empty proof but stays on-hold',r.status_code in (200,201) and optional_block_result.get('status')=='on-hold' and optional_block_result.get('payment_result',{}).get('payment_status')=='success')
# Explicit reference-only policy verifies the actual Store API string handoff, including backslashes.
reference_settings=dict(settings_data);reference_settings.pop('proof_policy[screenshot_enabled]',None);reference_settings['proof_policy[reference_enabled]']='1';reference_settings['proof_policy[reference_required]']='1'
r=s.post(settings_url,data=reference_settings,timeout=30);check('merchant enables reference-only proof policy','Settings and email templates saved.' in r.text)
reference_blocks=requests.Session();r=reference_blocks.get(api+'cart',timeout=30);reference_headers={'Cart-Token':r.headers['Cart-Token']}
if r.headers.get('Nonce'):reference_headers['Nonce']=r.headers['Nonce']
r=reference_blocks.post(api+'cart/add-item',headers=reference_headers,json={'id':f['product'],'quantity':1},timeout=30)
if r.headers.get('Nonce'):reference_headers['Nonce']=r.headers['Nonce']
reference_value="BLOCK\\REF'"+str(time.time_ns());reference_body=dict(body);reference_body['payment_data']=[{'key':'crmp_selected_method','value':'bank'},{'key':'crmp_transaction_ref','value':reference_value},{'key':'crmp_payment_date','value':'2026-01-01'}]
r=reference_blocks.post(api+'checkout',headers=reference_headers,json=reference_body,timeout=60);reference_result=r.json();check('Store API accepts configured reference-only proof',r.status_code in (200,201) and reference_result.get('status')=='on-hold' and reference_result.get('payment_result',{}).get('payment_status')=='success')
export_rows=list(csv.DictReader(io.StringIO(s.get(export,timeout=30).text)));reference_row=next(row for row in export_rows if row['Order']==str(reference_result['order_id']))
check('Store API reference is preserved exactly in ledger/CSV',reference_row['Transaction ID']==reference_value and reference_row['Screenshot ID']=='0')
# Return the merchant's mode to the secure default after this isolated scenario.
settings_data['require_checkout_proof']='1';r=s.post(settings_url,data=settings_data,timeout=30);check('merchant restores mandatory mode','Settings and email templates saved.' in r.text)
print('RESULT: MANDATORY/OPTIONAL CLASSIC, BLOCKS, NATIVE MULTIPART AND LATER-PROOF CHECKS PASSED',flush=True)
