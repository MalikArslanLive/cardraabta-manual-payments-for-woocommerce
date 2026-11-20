const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync('/tmp/crmp-server-fixture.json','utf8')),base='http://127.0.0.1:8089',browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const context=await browser.newContext(),page=await context.newPage(),errors=[];if(process.env.CRMP_DELAY_SCRIPTS)await page.route('**/*',async route=>{if(route.request().resourceType()==='script'&&/autoptimize_|cardraabta.*assets\//.test(route.request().url()))await new Promise(r=>setTimeout(r,750));await route.continue();});page.on('pageerror',e=>errors.push(e.message));
  const cart=await context.request.post(base+'/?wc-ajax=add_to_cart',{form:{product_id:String(fixture.product),quantity:'1'}});assert(!(await cart.json()).error);
  const firstUpdate=page.waitForResponse(r=>r.url().includes('wc-ajax=update_order_review')&&r.status()===200);
  await page.goto(base+'/?page_id='+fixture.checkout_page);await firstUpdate;if(process.env.CRMP_EXPECT_OPTIMIZED)assert((await page.content()).includes('autoptimize_'),'optimizer must actually modify checkout assets');await page.locator('[name=payment_method][value=crmp_manual]').check();await page.locator('#crmp_method').selectOption('bank');
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64'),name='payment-'+('x'.repeat(100))+'.png';
  await page.locator('.crmp-checkout input[type=file]').setInputFiles({name,mimeType:'image/png',buffer:png});await page.locator('.crmp-checkout .crmp-upload-feedback[data-state=ready]').waitFor();
  await page.evaluate(()=>{window.crmpOldFileInput=document.querySelector('.crmp-checkout input[type=file]')});const original=await page.locator('[name=crmp_screenshot_data]').inputValue();assert(original.startsWith('data:image/png;base64,'));
  let updateBody;page.on('request',r=>{if(r.url().includes('wc-ajax=update_order_review'))updateBody=r.postData();});
  const refreshed=page.waitForResponse(r=>r.url().includes('wc-ajax=update_order_review')&&r.status()===200);await page.evaluate(()=>{window.crmpRefreshes=0;jQuery(document.body).on('updated_checkout.crmp_probe',()=>{window.crmpRefreshes++});jQuery(document.body).trigger('update_checkout');});await refreshed;await page.waitForFunction(()=>window.crmpRefreshes>0);assert(await page.evaluate(()=>window.crmpOldFileInput!==document.querySelector('.crmp-checkout input[type=file]')),'actual payment fragment must be replaced');
  assert.equal(await page.locator('[name=crmp_screenshot_data]').inputValue(),original,'prepared screenshot survives the actual WooCommerce refresh');assert((await page.locator('.crmp-upload-status').innerText()).includes(name));assert.equal(await page.locator('.crmp-checkout input[type=file]').getAttribute('id'),null,'WooCommerce must not restore a fake file path');
  const encoded=new URLSearchParams(updateBody).get('post_data')||'';assert(!new URLSearchParams(encoded).has('crmp_screenshot_data'),'cart/address updates do not upload the image payload');
  await page.locator('[name=payment_method][value=cod]').check();
  assert(await page.locator('[name=crmp_screenshot_data]').isDisabled(),'other gateways must not send manual screenshot payload');
  await page.locator('[name=payment_method][value=crmp_manual]').check();assert(!(await page.locator('[name=crmp_screenshot_data]').isDisabled()));
  for(const width of [320,360,390,768,1365]){await page.setViewportSize({width,height:1000});const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);assert(!overflow,'real checkout overflow '+width);}
  assert.deepEqual(errors,[],'checkout refresh must not throw file-input restoration errors');
  fs.mkdirSync('local-runtime/responsive',{recursive:true});await page.screenshot({path:'local-runtime/responsive/checkout-upload-status.png',fullPage:true});console.log('PASS REAL CHECKOUT UPLOAD: filename/ready feedback, WooCommerce refresh persistence, shipping-payload isolation, other-gateway isolation and responsive layouts');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
