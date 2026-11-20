const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('assert/strict'),path=require('path');
(async()=>{
 const d=new JSDOM(fs.readFileSync('/tmp/crmp-proof-policy-settings.html','utf8'),{runScripts:'outside-only',url:'https://shop.example.test/wp-admin/'}),w=d.window,$=require('jquery')(w);w.jQuery=$;
 w.eval(fs.readFileSync(path.join(__dirname,'../assets/email-settings.js'),'utf8'));await new Promise(r=>setTimeout(r,30));
 const root=$('[data-policy-context=checkout]'),order=$('[data-policy-context=order]'),same=$('#crmp-order-proof-same');
 const enabled=(box,key)=>box.find('[data-proof-field='+key+'] .crmp-proof-enabled'),required=(box,key)=>box.find('[data-proof-field='+key+'] .crmp-proof-required');
 assert(enabled(root,'screenshot').prop('checked'));assert(required(root,'screenshot').prop('checked'));assert(!required(root,'screenshot').prop('disabled'));assert(!enabled(root,'reference').prop('checked'));
 assert(enabled(order,'reference').prop('checked'));assert(!enabled(order,'screenshot').prop('checked'));assert(!$('#crmp-order-proof-options').prop('hidden'));
 enabled(root,'screenshot').prop('checked',false).trigger('change');assert(!root.find('.crmp-proof-policy-warning').prop('hidden'));
 let e=$.Event('submit');$('#crmp-email-settings-form').triggerHandler(e);assert(e.isDefaultPrevented());
 enabled(root,'reference').prop('checked',true).trigger('change');assert(!required(root,'reference').prop('checked'));required(root,'reference').prop('checked',true).trigger('change');assert(root.find('.crmp-proof-policy-warning').prop('hidden'));
 enabled(root,'screenshot').prop('checked',true).trigger('change');assert(required(root,'reference').prop('checked'));required(root,'reference').prop('checked',false).trigger('change');assert(!required(root,'reference').prop('disabled'));assert(!root.find('.crmp-proof-optional-warning').prop('hidden'));assert(root.find('.crmp-proof-recommendation').text().includes('Recommended: mark at least one'));assert.equal(root.find('.crmp-proof-required:checked').length,1,'date alone remains required while both proof fields can be optional');let optionalSave=$.Event('submit');$('#crmp-email-settings-form').triggerHandler(optionalSave);assert(!optionalSave.isDefaultPrevented(),'merchant may save both enabled proof fields as optional');
 required(root,'screenshot').prop('checked',true);required(root,'reference').prop('checked',true);e=$.Event('submit');$('#crmp-email-settings-form').triggerHandler(e);assert(!e.isDefaultPrevented());
 enabled(root,'date').prop('checked',false).trigger('change');assert(!required(root,'date').prop('checked'));assert(required(root,'date').prop('disabled'));
 enabled(order,'reference').prop('checked',false).trigger('change');e=$.Event('submit');$('#crmp-email-settings-form').triggerHandler(e);assert(e.isDefaultPrevented());
 same.prop('checked',true).trigger('change');assert($('#crmp-order-proof-options').prop('hidden'));assert($('#crmp-order-proof-options').prop('disabled'));e=$.Event('submit');$('#crmp-email-settings-form').triggerHandler(e);assert(!e.isDefaultPrevented());
 d.window.close();console.log('PASS POLICY DOM: independent contexts, same-fields toggle, required-state controls and invalid save guard');
})().catch(e=>{console.error(e);process.exit(1)});
