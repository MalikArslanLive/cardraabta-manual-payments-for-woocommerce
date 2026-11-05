const {chromium,request}=require('playwright');
const fs=require('fs'),assert=require('assert/strict');
(async()=>{
 const f=JSON.parse(fs.readFileSync('/tmp/crmp-trash-ui.json','utf8'));
 const browser=await chromium.launch({headless:true});
 const page=await browser.newPage({viewport:{width:1365,height:1000}}), base='http://127.0.0.1:8088';
 try {
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('auditor');await page.locator('#user_pass').fill('test-only-local');await page.locator('#wp-submit').click();
  await page.goto(base+'/wp-admin/admin.php?page=crmp-transactions');
  const config=await page.evaluate(()=>window.crmpAdmin);
  assert(config&&config.nonce);
  const bad=await page.request.post(config.ajaxUrl,{form:{action:'crmp_manage_transactions',nonce:'invalid',id:f.pending,operation:'trash'}});assert.equal(bad.status(),403,'invalid nonce must fail');
  const anon=await request.newContext();const forbidden=await anon.post(config.ajaxUrl,{form:{action:'crmp_manage_transactions',nonce:config.nonce,id:f.pending,operation:'trash'}});assert.notEqual(forbidden.status(),200,'signed-out request must fail');await anon.dispose();
  const csvLink=await page.getByRole('link',{name:'Export CSV',exact:true}).getAttribute('href');
  const csv=await page.request.get(csvLink);assert.equal(csv.status(),200);assert((csv.headers()['content-type']||'').includes('text/csv'));assert((await csv.text()).includes("'=1+1"),'CSV formula reference must be escaped');
  const guest=await request.newContext();
  const proof={action:'crmp_submit_evidence',order_id:String(f.proof_order),order_key:f.proof_key,_wpnonce:f.proof_nonce,crmp_transaction_ref:'HTTP-PROOF'};
  assert.equal((await guest.post(config.ajaxUrl,{form:{...proof,order_key:'wrong'}})).status(),403);
  assert.equal((await guest.post(config.ajaxUrl,{form:{...proof,_wpnonce:'wrong'}})).status(),403);
  assert.equal((await guest.post(config.ajaxUrl,{form:{...proof,crmp_transaction_ref:''}})).status(),400);
  for(let i=0;i<2;i++){const response=await guest.post(config.ajaxUrl,{form:proof});assert.equal(response.status(),200);assert.equal((await response.json()).success,true);}
  await guest.dispose();
  const url=page.url();let navigations=0;page.on('framenavigated',frame=>{if(frame===page.mainFrame())navigations++;});
  await page.locator('[aria-controls="crmp-detail-'+f.closed+'"]').click();
  const source=page.locator('#crmp-detail-'+f.closed);
  assert((await source.innerText()).includes('In Trash'));
  await source.locator('.crmp-view-related').click();
  await source.locator('.crmp-related-panel').waitFor({state:'visible'});
  assert((await source.locator('.crmp-related-panel').innerText()).includes('Transaction #'+f.winner+' — In Trash'));
  const screenshotLink=await source.locator('.crmp-related-panel a').getAttribute('href');
  const image=await page.request.get(screenshotLink);assert.equal(image.status(),200);assert((image.headers()['content-type']||'').includes('image/png'));assert((image.headers()['cache-control']||'').includes('no-store'));assert((image.headers()['content-security-policy']||'').includes('sandbox'));
  const outsider=await request.newContext();const blocked=await outsider.get(screenshotLink);assert(!(blocked.headers()['content-type']||'').includes('image/png'),'anonymous evidence retrieval denied');await outsider.dispose();
  assert.equal(page.url(),url);assert.equal(navigations,0,'related view must not navigate');
  await page.screenshot({path:'/tmp/crmp-ui-trash-preview.png',fullPage:true});
  await page.goto(base+'/wp-admin/admin.php?page=crmp-transactions&trash=1');
  await page.locator('[aria-controls="crmp-detail-'+f.winner+'"]').click();let winner=page.locator('#crmp-detail-'+f.winner);
  await winner.locator('[data-operation="restore"]').click();await winner.locator('.crmp-record-confirm-cancel').click();assert.equal(await winner.isVisible(),true);
  await winner.locator('[data-operation="restore"]').click();await winner.locator('.crmp-record-confirm-yes').click();await winner.waitFor({state:'detached'});
  await page.goto(base+'/wp-admin/admin.php?page=crmp-transactions');
  await page.locator('[aria-controls="crmp-detail-'+f.closed+'"]').click();const here=page.locator('#crmp-detail-'+f.closed);assert(!(await here.locator('.crmp-paid-source').innerText()).includes('In Trash'));
  const count=navigations;await here.locator('.crmp-view-related').click();winner=page.locator('#crmp-detail-'+f.winner);assert.equal(await winner.isVisible(),true);assert.equal(navigations,count,'view visible source must expand its row without navigation');
  await winner.locator('[data-operation="trash"]').click();await winner.locator('.crmp-record-confirm-yes').click();await winner.waitFor({state:'detached'});
  await page.goto(base+'/wp-admin/admin.php?page=crmp-transactions&trash=1');await page.locator('[aria-controls="crmp-detail-'+f.winner+'"]').click();winner=page.locator('#crmp-detail-'+f.winner);await winner.locator('[data-operation="delete"]').click();await winner.locator('.crmp-record-confirm-yes').click();await winner.waitFor({state:'detached'});
  await page.goto(base+'/wp-admin/admin.php?page=crmp-transactions');await page.locator('[aria-controls="crmp-detail-'+f.closed+'"]').click();const deletedSource=page.locator('#crmp-detail-'+f.closed);assert((await deletedSource.innerText()).includes('Permanently deleted'));assert.equal(await deletedSource.locator('.crmp-view-related').count(),0);
  await page.locator('[aria-controls="crmp-detail-'+f.pending+'"]').click();const pending=page.locator('#crmp-detail-'+f.pending);await pending.locator('[data-operation="trash"]').click();await pending.locator('.crmp-record-confirm-yes').click();await pending.waitFor({state:'detached'});
  await page.goto(base+'/wp-admin/admin.php?page=crmp-transactions&trash=1');await page.locator('[data-operation="empty"]').click();await page.locator('.crmp-record-confirm:visible .crmp-record-confirm-yes').click();await page.locator('.crmp-management-notice').filter({hasText:'Trash cleared'}).waitFor({state:'visible'});assert.equal(await page.locator('.crmp-toggle-details').count(),0);
  console.log('PASS real authenticated AJAX: nonce and login guards, no-reload related preview, Trash/Restore, permanent-delete labels and Empty Trash');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
