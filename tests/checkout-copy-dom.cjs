const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('assert/strict'),path=require('path');
(async()=>{
 for(const mode of ['unavailable','denied','success']){
  const dom=new JSDOM('<div class="crmp-account-copy"><code>TEST-IBAN-123</code><button class="crmp-copy" data-copy="TEST-IBAN-123">Copy account number</button></div>',{url:'https://store.example.test',runScripts:'outside-only'}),w=dom.window;
  Object.defineProperty(w.navigator,'clipboard',{value:mode==='unavailable'?undefined:{writeText:value=>{assert.equal(value,'TEST-IBAN-123');return mode==='denied'?Promise.reject(new Error('Permission denied')):Promise.resolve();}}});
  w.eval(fs.readFileSync(path.join(__dirname,'../assets/checkout.js'),'utf8'));
  w.document.querySelector('button').click();await new Promise(resolve=>setTimeout(resolve,20));
  if(mode==='success')assert.equal(w.document.querySelector('button').textContent,'Copied!');
  else{
   assert.equal(w.getSelection().toString(),'TEST-IBAN-123');
   assert.match(w.document.querySelector('[role=status]').textContent,/Copy command/);
  }
  w.close();console.log('PASS clipboard '+mode+': accessible result and manual fallback');
 }
})().catch(error=>{console.error(error);process.exit(1)});
