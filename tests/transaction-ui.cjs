const {chromium} = require('playwright');
const fs = require('fs');
const assert = require('assert/strict');
(async () => {
  const fixture = JSON.parse(fs.readFileSync('/tmp/crmp-ui-fixture.json', 'utf8'));
  const browser = await chromium.launch({headless: true});
  try {
    for (const width of [1280, 390]) {
      const page = await browser.newPage({viewport:{width,height:900}});
      await page.setContent('<!doctype html><html><head><style>body{font:14px Arial;margin:16px}.widefat{width:100%;border-collapse:collapse}.widefat td,.widefat th{padding:12px;border-bottom:1px solid #ddd}.button{padding:8px;cursor:pointer}</style></head><body>'+fixture.html+'</body></html>');
      await page.addStyleTag({path:'assets/admin.css'});
      await page.addScriptTag({path:'/tmp/crmp-wp/wp-includes/js/jquery/jquery.min.js'});
      await page.addScriptTag({path:'assets/admin.js'});
      await page.evaluate(() => {window.testSubmits=0;HTMLFormElement.prototype.submit=function(){window.testSubmits++;};});
      const live = page.locator('#crmp-detail-'+fixture.live_id);
      const deleted = page.locator('#crmp-detail-'+fixture.deleted_id);
      const liveButton = page.locator('[aria-controls="crmp-detail-'+fixture.live_id+'"]');
      const deletedButton = page.locator('[aria-controls="crmp-detail-'+fixture.deleted_id+'"]');
      assert.equal(await live.isVisible(),false);
      const before = await deletedButton.boundingBox();
      await liveButton.click();
      assert.equal(await live.isVisible(),true);
      if(width===390){const panel=await live.locator('.crmp-detail').boundingBox();assert(panel.x>=0&&panel.x+panel.width<=width,'mobile detail panel stays inside the visible viewport');assert(await live.locator('.crmp-review-form').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'mobile review controls and confirmation must fit');}
      assert.equal(await liveButton.textContent(),'Hide Details');
      assert.equal(await page.locator('.crmp-table-scroll').evaluate(el=>el.scrollLeft),0,'opening details returns to the beginning of the horizontally scrolling table');
      const after = await deletedButton.boundingBox();
      assert(after.y > before.y+50,'opening details must push following rows down');
      await deletedButton.click();
      assert.equal(await live.isVisible(),true);
      assert.equal(await deleted.isVisible(),true);
      const a = await live.boundingBox(), b = await deleted.boundingBox();
      assert(a.y+a.height <= b.y+1,'open detail rows must not overlap');
      assert.equal(await deleted.locator('.crmp-review-form').count(),0);
      assert((await deleted.innerText()).includes('Order deleted.'));
      assert((await deleted.innerText()).includes('Rejected'));
      const approve=live.locator('[data-decision="verified"]');
      await approve.click();
      assert.equal(await page.evaluate(()=>window.testSubmits),0);
      assert.equal(await live.locator('.crmp-inline-confirm').isVisible(),true);
      await live.locator('.crmp-confirm-cancel').click();
      assert.equal(await live.locator('.crmp-inline-confirm').isVisible(),false);
      assert.equal(await approve.isEnabled(),true);
      assert.equal(await page.evaluate(()=>window.testSubmits),0);
      await live.locator('[data-decision="rejected"]').click();
      assert((await live.locator('.crmp-confirm-message').innerText()).includes('reject'));
      await live.locator('.crmp-confirm-cancel').click();
      await approve.click();
      await live.locator('.crmp-confirm-yes').click();
      assert.equal(await page.evaluate(()=>window.testSubmits),1);
      assert.equal(await live.locator('input[name="decision"]').inputValue(),'verified');
      await liveButton.click();assert.equal(await live.isVisible(),false);
      assert.equal(await liveButton.textContent(),'View Details');
      assert.equal(await deleted.isVisible(),true);
      await liveButton.click();
      assert.equal(page.url(),'about:blank','view toggles must not navigate');
      await page.locator('.crmp-table > tbody > tr:not(.crmp-detail-row) > td:nth-child(6)').first().evaluate(el=>{el.textContent='R'.repeat(191);});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'filters and long references must not overflow the page');
      await page.screenshot({path:'/tmp/crmp-ui-'+width+'.png',fullPage:true});
      console.log('PASS inline row expansion, independent collapse, no overlap, deleted-order controls and confirmation at '+width+'px');
      await page.close();
    }
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
