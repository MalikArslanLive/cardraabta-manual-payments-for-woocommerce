import gzip,json,os,pathlib
fixture=json.load(open('/tmp/crmp-server-fixture.json'));root=pathlib.Path(os.environ['CRMP_WP_PATH'])/'wp-content/cache';pages=[]
for file in root.rglob('*'):
    if not file.is_file() or file.suffix not in ('.html','.gz','.js'):continue
    raw=file.read_bytes()
    if file.suffix=='.gz':
        try:raw=gzip.decompress(raw)
        except (OSError,EOFError):continue
    assert fixture['key'].encode() not in raw,'Private order key appeared in public cache files'
    if file.suffix=='.html' or file.name.endswith('.html.gz'):
        pages.append(file)
        assert b'id="crmp-payment-proof"' not in raw,'Private payment panel appeared in page cache'
        assert 'server-checkout' not in str(file) and 'server-proof' not in str(file),'Private page path cached'
assert pages,'No actual cached public HTML files found'
print('PASS CACHE AUDIT: public HTML cache exists; no private order key, proof panel or checkout/proof page stored',flush=True)
