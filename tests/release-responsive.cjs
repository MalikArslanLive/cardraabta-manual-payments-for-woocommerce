const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict'),path=require('path');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const output=path.resolve('local-runtime/responsive');fs.mkdirSync(output,{recursive:true});
 try{
  const page=await browser.newPage();
  const css=fs.readFileSync(require('path').join(__dirname,'../assets/checkout.css'),'utf8');
  const script=fs.readFileSync(require('path').join(__dirname,'../assets/checkout.js'),'utf8');
  for(const width of [320,360,390,768,1365]){
   await page.setViewportSize({width,height:950});
   for(const [name,file] of [['checkout','/tmp/crmp-checkout-design.html'],['rejected','/tmp/crmp-qr-order-form.html'],['pending','/tmp/crmp-proof-limit.html']]){
    await page.setContent('<meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;padding:12px;font:16px Arial,sans-serif}*{box-sizing:border-box}input,select,textarea{font:inherit}'+css+'</style>'+fs.readFileSync(file,'utf8'));
    await page.addScriptTag({content:script});
    const toggle=page.locator('.crmp-proof-toggle');if(await toggle.count()){assert.equal(await page.locator('.crmp-proof-form').isVisible(),false);await toggle.click();assert.equal(await page.locator('.crmp-proof-form').isVisible(),true);}
    const copy=page.locator('.crmp-copy:visible').first();
    if(await copy.count()){
     await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:undefined}));
     await copy.click();
     assert((await page.locator('.crmp-copy-feedback').first().innerText()).includes('Copy command'),'manual copy fallback is visible');
    }
    const screenshotPicker=page.locator('[name=crmp_screenshot]');
    if(await screenshotPicker.count()){await screenshotPicker.setInputFiles({name:'payment-'+('x'.repeat(120))+'.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64')});await page.locator('.crmp-upload-feedback:not([hidden])').waitFor();assert((await page.locator('.crmp-upload-status').innerText()).includes('payment-'),'selected filename is explicit');}
    const result=await page.evaluate(()=>{
     const controls=[...document.querySelectorAll('input:not([type=hidden]):not([type=radio]):not([type=checkbox]):not([type=file]),select,textarea')].filter(x=>x.getClientRects().length);
     return {overflow:document.documentElement.scrollWidth>innerWidth+1,bad:controls.filter(x=>{let r=x.getBoundingClientRect();return r.left<0||r.right>innerWidth+1}).map(x=>x.id),prompts:controls.filter(x=>x.placeholder==='Enter reference').map(x=>{let s=getComputedStyle(x),c=document.createElement('canvas').getContext('2d');c.font='14px '+s.fontFamily;return c.measureText(x.placeholder).width<=x.clientWidth-parseFloat(s.paddingLeft)-parseFloat(s.paddingRight);})};
    });
    assert(!result.overflow,`${name} page overflow at ${width}`);assert.deepEqual(result.bad,[],`${name} control overflow at ${width}`);assert(result.prompts.every(Boolean),`${name} reference prompt clipped at ${width}`);
    if(await toggle.count()){await page.locator('.crmp-proof-cancel').click();assert.equal(await page.locator('.crmp-proof-form').isVisible(),false);}
    await page.screenshot({path:path.join(output,`${name}-${width}.png`),fullPage:true});console.log(`PASS RESPONSIVE ${name}: ${width}px, controls fit and correction toggles work`);
   }
  }
  if(process.env.CRMP_VISUAL_CUSTOMER_ONLY)return;
  // Real authenticated dashboard and settings, with the installed CSS and scripts.
  await page.setViewportSize({width:1365,height:1000});await page.goto('http://127.0.0.1:8089/wp-login.php');await page.locator('#user_login').fill('auditor');await page.locator('#user_pass').fill('test-only-local');await page.locator('#wp-submit').click();
  for(const screen of ['crmp-transactions','crmp-settings','crmp-email-logs'])for(const width of (screen==='crmp-settings'?[320,360,390,768,1365]:[390,768,1365])){
   await page.setViewportSize({width,height:1000});await page.goto('http://127.0.0.1:8089/wp-admin/admin.php?page='+screen);await page.locator('h1').first().waitFor();
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);assert(!overflow,`${screen} document overflow at ${width}`);
   await page.screenshot({path:path.join(output,screen+'-'+width+'.png'),fullPage:true});console.log(`PASS RESPONSIVE ${screen}: ${width}px, table overflow contained`);
  }
 }finally{await browser.close();}
 console.log('RESULT: RELEASE RESPONSIVE CHECKS PASSED');
})().catch(e=>{console.error(e);process.exit(1)});
