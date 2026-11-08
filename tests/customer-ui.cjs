const {chromium}=require('playwright');
const fs=require('fs'),assert=require('assert/strict');
(async()=>{
 const fixtures=JSON.parse(fs.readFileSync('/tmp/crmp-customer-fixtures.json','utf8'));
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage();
  for(const width of [320,390,768,1280]){
   await page.setViewportSize({width,height:900});
   for(const fixture of fixtures){
    await page.goto('http://127.0.0.1:8088/?page_id='+fixture.page+'&key='+encodeURIComponent(fixture.key));
    const panel=page.locator('.crmp-payment-panel');await panel.waitFor();
    assert.equal(await panel.count(),1,'one latest status panel only');
    assert((await panel.locator('h2').innerText()).includes(fixture.expected),fixture.case+' latest status');
    const text=await panel.innerText();assert(!/Trash|Permanently deleted|Transaction #/.test(text));
    if(fixture.case.includes('-'))assert(!/PRIVATE-REFERENCE|LongUnbrokenNote/.test(text),'hidden transaction details must stay hidden');
    assert.equal(await page.locator('link[rel=stylesheet][href*="assets/checkout.css"]').count(),1,'payment CSS loads through the real theme head');
    const box=await panel.boundingBox();assert(box.x>=-1&&box.x+box.width<=width+1,fixture.case+' panel fits '+width);
    assert(await panel.evaluate(el=>el.scrollWidth<=el.clientWidth+1),fixture.case+' content fits '+width);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'theme page has no horizontal overflow');
    if(width<=390&&await panel.locator('.crmp-payment-fields').count())assert.equal(await panel.locator('.crmp-payment-fields').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length),1);
    if(width===390||width===1280)await page.screenshot({path:'/tmp/crmp-ui-customer-'+fixture.case+'-'+width+'.png',fullPage:true});
   }
   console.log('PASS real WordPress theme customer panels: all ten latest-status, privacy and responsive cases at '+width+'px');
  }
  const retry=fixtures.find(x=>x.case==='rejected-purge');
  await page.goto('http://127.0.0.1:8088/?page_id='+retry.page+'&key='+encodeURIComponent(retry.key));
  await page.locator('[name=crmp_transaction_ref]').fill('NEW-CUSTOMER-PROOF');
  await page.locator('.crmp-payment-submit').click();
  await page.getByRole('heading',{name:'Payment Under Review',exact:true}).waitFor();
  assert.equal(await page.locator('.crmp-proof-form,.crmp-reviewer-note,.crmp-payment-result').count(),0,'successful resubmission immediately shows only the latest pending state');
  await page.reload();assert((await page.locator('.crmp-payment-panel').innerText()).includes('NEW-CUSTOMER-PROOF'));
  console.log('PASS actual customer resubmission immediately replaces the old rejection with pending and persists after reload');
  await page.goto('http://127.0.0.1:8088/?page_id='+fixtures[0].page);
  assert.equal(await page.locator('.crmp-payment-panel').count(),0,'guest without order key cannot see the payment panel');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
