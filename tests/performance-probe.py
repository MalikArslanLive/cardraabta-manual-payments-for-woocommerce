"""Small reproducible latency sample, not a production load/capacity guarantee."""
import json,statistics,time
import requests
base='http://127.0.0.1:8089';s=requests.Session()
s.get(base+'/wp-login.php',timeout=30);r=s.post(base+'/wp-login.php',data={'log':'auditor','pwd':'test-only-local','testcookie':'1'},timeout=30)
assert 'wp-admin' in r.url
result={}
for name,path,marker in [('transactions','/wp-admin/admin.php?page=crmp-transactions','Manual Payment Transactions'),('settings','/wp-admin/admin.php?page=crmp-settings','CardRaabta Settings'),('logs','/wp-admin/admin.php?page=crmp-email-logs','Email Logs')]:
    values=[]
    for i in range(6):
        start=time.perf_counter();r=s.get(base+path,timeout=30);elapsed=(time.perf_counter()-start)*1000
        assert r.status_code==200 and marker in r.text,name
        if i:values.append(elapsed)
    result[name]={'samples':len(values),'median_ms':round(statistics.median(values),2),'max_ms':round(max(values),2)}
with open('/tmp/crmp-latency.json','w') as out:json.dump(result,out,sort_keys=True)
print('::notice title=Latency sample::'+json.dumps(result,sort_keys=True),flush=True)
