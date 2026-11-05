// File selection/preparation is separate from server-confirmed submission.
function crmpScreenshotStatus(root,state,name,percent,message) {
    var box=root?.querySelector('.crmp-upload-feedback');if(!box)return;
    var text=box.querySelector('.crmp-upload-status'),bar=box.querySelector('.crmp-upload-progress');
    box.hidden=!state;box.dataset.state=state || '';
    var messages={preparing:'Preparing screenshot',ready:'Ready to submit: '+name+'. Your screenshot will be submitted with your order.',selected:'Selected: '+name+'. Ready to upload when you submit payment proof.',submitting:'Submitting screenshot: '+name+'. Please wait for confirmation.',submitted:'Screenshot submitted for review: '+name,error:'Screenshot not submitted. Select it again or review the error and retry.'};
    text.textContent=message || messages[state] || '';
    if(state==='preparing')text.textContent+='…'+(Number.isFinite(percent)?' '+Math.round(percent)+'%':'');
    bar.hidden=!['preparing','submitting'].includes(state);
    if(state==='preparing'&&Number.isFinite(percent))bar.value=percent;else bar.removeAttribute('value');
}
function crmpProofPolicy(root) {
    var fallback={screenshot_enabled:true,screenshot_required:true,reference_enabled:false,reference_required:false,date_enabled:true,date_required:true,note_enabled:false,note_required:false,confirmation_enabled:false,confirmation_required:false};
    try { return Object.assign(fallback,JSON.parse(root.dataset.proofPolicy || 'null') || window.crmpCheckout?.proofPolicy || {}); } catch(error) { return fallback; }
}
function crmpCheckoutProofAttempt(root,hasScreenshot) {
    if(root.dataset.requireProof!=='0' && !(root.dataset.requireProof===undefined&&window.crmpCheckout?.requireProof===false))return true;
    var p=crmpProofPolicy(root);
    if(hasScreenshot)return true;
    return ['reference','date','note','confirmation'].some(function(key){
        if(!p[key+'_enabled'])return false;
        var name={reference:'crmp_transaction_ref',date:'crmp_payment_date',note:'crmp_customer_note',confirmation:'crmp_proof_confirm'}[key],field=root.querySelector('[name="'+name+'"]');
        return key==='confirmation'?!!field?.checked:!!field?.value?.trim();
    });
}
function crmpProofError(root,hasScreenshot) {
    if(root.matches('.crmp-checkout')&&!crmpCheckoutProofAttempt(root,hasScreenshot))return '';
    var p=crmpProofPolicy(root),reference=p.reference_enabled?(root.querySelector('[name="crmp_transaction_ref"]')?.value || '').trim():'';
    if(hasScreenshot&&!p.screenshot_enabled)return 'Screenshot uploads are disabled. Enter the transaction reference.';
    if(p.screenshot_required&&!hasScreenshot)return 'Upload your payment screenshot before submitting.';
    if(p.reference_required&&!reference)return 'Enter your transaction reference before submitting.';
    if(!reference&&!hasScreenshot)return 'Enter a transaction reference or upload a payment screenshot before submitting.';
    if(p.date_required&&!root.querySelector('[name="crmp_payment_date"]')?.value)return 'Select the date you made this payment.';
    if(p.note_required&&!(root.querySelector('[name="crmp_customer_note"]')?.value || '').trim())return 'Enter the additional payment note.';
    if(p.confirmation_required&&!root.querySelector('[name="crmp_proof_confirm"]')?.checked)return 'Confirm that the payment details are accurate and you have already paid.';
    return '';
}
(function() {
    var activeScreenshotReader=null,screenshotReading=false,activeScreenshotName='';
    function liveScreenshotRoot(root){return root.isConnected?root:document.querySelector('.crmp-checkout');}
    function updatePreview(select) {
        var root = select.closest('.crmp-checkout');
        if (!root) return;
        var preview=root.querySelector('.crmp-method-preview');if(preview)preview.hidden=!select.value;
        root.querySelectorAll('.crmp-method-info').forEach(function(el) {
            el.hidden = el.dataset.method !== select.value;
        });
    }
    document.addEventListener('change', function(e) {
        if (e.target.matches('#crmp_method')) updatePreview(e.target);
        if (!e.target.matches('input[name="crmp_screenshot"]')) return;
        var file = e.target.files && e.target.files[0],
            root = e.target.closest('.crmp-checkout');
        if (!root) return;
        var fileLabel=e.target.closest('.crmp-upload-surface')?.querySelector('.crmp-upload-name');
        if(fileLabel)fileLabel.textContent=file?.name||'Choose payment screenshot';
        var nameField=root.querySelector('[name="crmp_screenshot_name"]');if(nameField)nameField.value=file?.name||'';
        var old = root.querySelector('input[name="crmp_screenshot_data"]');
        if (old) old.remove();
        if (activeScreenshotReader) activeScreenshotReader.abort();
        activeScreenshotReader=null;screenshotReading=false;activeScreenshotName='';
        root.crmpReader = null;
        root.dataset.crmpReading = 'false';
        if (!file) {crmpScreenshotStatus(root,'');return;}
        var max = (window.crmpCheckout?.maxUploadBytes || 5 * 1024 * 1024);
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > max) {
            alert('Use JPG, PNG or WebP within the configured size limit.');
            e.target.value = '';
            if(fileLabel)fileLabel.textContent='Choose payment screenshot';
            if(nameField)nameField.value='';
            crmpScreenshotStatus(root,'error','',null,'Screenshot not selected. Use JPG, PNG or WebP within the configured size limit.');
            return;
        }
        var reader = new FileReader();
        root.crmpReader = reader;activeScreenshotReader=reader;screenshotReading=true;activeScreenshotName=file.name;
        root.dataset.crmpReading = 'true';
        crmpScreenshotStatus(root,'preparing',file.name);
        reader.onprogress=function(event){if(activeScreenshotReader===reader){var live=liveScreenshotRoot(root);if(live)crmpScreenshotStatus(live,'preparing',file.name,event.lengthComputable&&event.total?event.loaded/event.total*100:undefined);}};
        reader.onload = function() {
            if (activeScreenshotReader !== reader) return;
            root=liveScreenshotRoot(root);if(!root)return;
            screenshotReading=false;root.dataset.crmpReading = 'false';
            if(!root.querySelector('[name="crmp_screenshot"]'))return;
            root.querySelector('[name="crmp_screenshot_data"]')?.remove();
            var input = document.createElement('input');
            input.type = 'hidden';
            input.id = 'crmp-checkout-screenshot-data';
            input.name = 'crmp_screenshot_data';
            input.value = reader.result;
            input.disabled=!!document.querySelector('[name="payment_method"]:checked:not([value="crmp_manual"])');
            root.appendChild(input);
            var liveName=root.querySelector('[name="crmp_screenshot_name"]');if(liveName)liveName.value=file.name;
            var liveLabel=root.querySelector('.crmp-upload-name');if(liveLabel)liveLabel.textContent=file.name;
            crmpScreenshotStatus(root,'ready',file.name);
            root.dispatchEvent(new Event('input',{bubbles:true}));
        };
        reader.onerror = function() {
            if (activeScreenshotReader !== reader) return;
            root=liveScreenshotRoot(root);if(!root)return;
            screenshotReading=false;root.dataset.crmpReading = 'false';
            var failedInput=root.querySelector('[name="crmp_screenshot"]');if(failedInput)failedInput.value='';
            var failedLabel=root.querySelector('.crmp-upload-name');if(failedLabel)failedLabel.textContent='Choose payment screenshot';
            var failedName=root.querySelector('[name="crmp_screenshot_name"]');if(failedName)failedName.value='';activeScreenshotName='';
            crmpScreenshotStatus(root,'error','',null,'Unable to prepare the screenshot. Select it again.');
            alert('Unable to read the screenshot. Select it again.');
        };
        reader.readAsDataURL(file);
    });
    document.addEventListener('click', function(e) {
        var b = e.target.closest('.crmp-copy');
        if (!b) return;
        e.preventDefault();
        var v = b.getAttribute('data-copy');
        var original = b.dataset.crmpCopyLabel || b.textContent;
        b.dataset.crmpCopyLabel = original;
        var feedback = b.parentElement.querySelector('.crmp-copy-feedback');
        if (!feedback) {
            feedback = document.createElement('span');
            feedback.className = 'crmp-copy-feedback';
            feedback.setAttribute('role', 'status');
            b.parentElement.appendChild(feedback);
        }
        function copied() {
            feedback.textContent = 'Account number copied.';
            b.textContent = 'Copied!';
            clearTimeout(b.crmpCopyTimer);
            b.crmpCopyTimer = setTimeout(function() { b.textContent = original; }, 1400);
        }
        function manualCopy() {
            var account = b.closest('.crmp-account-copy')?.querySelector('code') || b.closest('.crmp-payment-account')?.querySelector('.crmp-account-number');
            var selection = window.getSelection();
            if (account && selection) {
                var range = document.createRange();
                range.selectNodeContents(account);
                selection.removeAllRanges();
                selection.addRange(range);
            }
            feedback.textContent = 'Account selected. Use your device’s Copy command.';
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(v).then(copied, manualCopy);
        } else {
            manualCopy();
        }
    });
    function checkoutProofReady() {
        var gateway = document.querySelector('[name="payment_method"]:checked');
        if (gateway && gateway.value !== 'crmp_manual') return true;
        var root = document.querySelector('.crmp-checkout');
        if (!root) return true;
        if (root.dataset.crmpReading === 'true') {
            alert('Please wait for the screenshot to finish loading.');
            return false;
        }
        var image = root.querySelector('[name="crmp_screenshot_data"]')?.value;
        var error = crmpProofError(root,!!image);
        if (error) { alert(error); return false; }
        var file=root.querySelector('[name="crmp_screenshot"]')?.files?.[0];
        if(image){var payload=root.querySelector('[name="crmp_screenshot_data"]');payload.disabled=false;crmpScreenshotStatus(root,'submitting',file?.name||root.querySelector('[name="crmp_screenshot_name"]')?.value||'Screenshot');}
        return true;
    }
    document.addEventListener('submit', function(event) {
        if (event.target.matches('form.checkout, form#order_review') && !checkoutProofReady()) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
    if (window.jQuery) {
        jQuery('form.checkout').on('checkout_place_order_crmp_manual', checkoutProofReady);
        jQuery(document.body).on('checkout_error',function(){var root=document.querySelector('.crmp-checkout');if(root?.querySelector('[name="crmp_screenshot_data"]'))crmpScreenshotStatus(root,'error','',null,'Order not placed. Review the checkout errors and retry; your selected screenshot has been kept.');});
        jQuery(document.body).on('update_checkout',function(){document.querySelectorAll('.crmp-checkout [name="crmp_screenshot_data"]').forEach(function(input){input.disabled=true;});});
        jQuery(document.body).on('updated_checkout', function() {
            var el = document.querySelector('#crmp_method');
            if (el) updatePreview(el);
            var root=document.querySelector('.crmp-checkout'),image=root?.querySelector('[name="crmp_screenshot_data"]'),name=root?.querySelector('[name="crmp_screenshot_name"]')?.value;
            if(image?.value&&name){var label=root.querySelector('.crmp-upload-name');if(label)label.textContent=name;crmpScreenshotStatus(root,'ready',name);}
            else if(root&&screenshotReading&&activeScreenshotReader){root.crmpReader=activeScreenshotReader;root.dataset.crmpReading='true';var pendingLabel=root.querySelector('.crmp-upload-name');if(pendingLabel)pendingLabel.textContent=activeScreenshotName;crmpScreenshotStatus(root,'preparing',activeScreenshotName);}
        });
    }
})();
// Inline order-received / My Account proof submission: no admin-post error page.
document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form.matches('.crmp-proof-form')) return;
    e.preventDefault();
    var feedback = form.querySelector('.crmp-proof-feedback');
    var file = form.querySelector('[name="crmp_screenshot"]')?.files?.[0];

    function show(message, ok) {
        feedback.hidden = false;
        feedback.textContent = message;
        feedback.classList.toggle('crmp-proof-success', !!ok);
        feedback.classList.toggle('crmp-proof-error', !ok);
        feedback.scrollIntoView({
            block: 'nearest',
            behavior: 'smooth'
        });
    }
    var proofError=crmpProofError(form,!!file);
    if(proofError){show(proofError,false);return;}
    if (file && (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > (window.crmpCheckout?.maxUploadBytes || 5 * 1024 * 1024))) {
        show('Use JPG, PNG or WebP within the configured size limit.', false);
        return;
    }
    var button = form.querySelector('[type="submit"]');
    if (button.disabled) return;
    button.disabled = true;
    var previous = button.textContent;
    button.textContent = 'Submitting…';
    var data = new FormData(form);
    data.set('action', 'crmp_submit_evidence');
    var fileInput=form.querySelector('[name="crmp_screenshot"]');
    if(file){crmpScreenshotStatus(form,'submitting',file.name);fileInput.disabled=true;}
    fetch(form.dataset.ajaxUrl, {
        method: 'POST',
        body: data,
        credentials: 'same-origin'
    }).then(async function(response) {
        var result = await response.json();
        if (!result || !result.data) throw Error('Unexpected response from server.');
        show(result.data.message || 'Submission failed.', !!result.success);
        if (result.success) {
            if(file)crmpScreenshotStatus(form,'submitted',file.name);
            button.textContent = 'Proof submitted';
            window.location.reload();
        } else {
            if(file){fileInput.disabled=false;crmpScreenshotStatus(form,'error','',null,result.data.message || 'Screenshot not submitted. Review the error and retry.');}
            button.disabled = false;
            button.textContent = previous;
        }
    }).catch(function() {
        if(file){fileInput.disabled=false;crmpScreenshotStatus(form,'error','',null,'Submission could not be confirmed. Refresh the order page to check its status before retrying.');}
        show('Could not submit payment proof. Please check your connection and try again.', false);
        button.disabled = false;
        button.textContent = previous;
    });
});

// Native file picker stays keyboard accessible; display the selected filename as plain text.
document.addEventListener('change',function(event){
 if(!event.target.matches('.crmp-proof-form input[name="crmp_screenshot"]'))return;
 var label=event.target.closest('.crmp-upload-surface')?.querySelector('.crmp-upload-name');
 if(label)label.textContent=event.target.files?.[0]?.name||'Choose payment screenshot';
 var form=event.target.closest('.crmp-proof-form'),file=event.target.files?.[0];
 if(!file){crmpScreenshotStatus(form,'');return;}
 if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>(window.crmpCheckout?.maxUploadBytes||5*1024*1024)){crmpScreenshotStatus(form,'error','',null,'Use JPG, PNG or WebP within the configured size limit.');return;}
 crmpScreenshotStatus(form,'selected',file.name);
});

// Keep receiving account and QR matched to the method actually used for this proof.
document.addEventListener('change',function(event){
 if(!event.target.matches('#crmp-proof-method'))return;
 var form=event.target.closest('.crmp-proof-form');
 form?.querySelectorAll('[data-proof-method]').forEach(function(panel){panel.hidden=panel.dataset.proofMethod!==event.target.value;});
});

// Manual checkout requires proof and its date; other gateways retain their own validation.
(function(){
 function sync(root){
  var gateway=document.querySelector('[name="payment_method"]:checked'),manual=!gateway||gateway.value==='crmp_manual',p=crmpProofPolicy(root);
  var fields={date:'crmp_payment_date',reference:'crmp_transaction_ref',screenshot:'crmp_screenshot',note:'crmp_customer_note',confirmation:'crmp_proof_confirm'};
  var file=root.querySelector('[name="crmp_screenshot"]')?.files?.length,data=root.querySelector('[name="crmp_screenshot_data"]')?.value;
  var enforce=manual&&crmpCheckoutProofAttempt(root,!!file||!!data);
  Object.keys(fields).forEach(function(key){var input=root.querySelector('[name="'+fields[key]+'"]');if(input){input.required=enforce&&!!p[key+'_required']&&(key!=='screenshot'||!data);input.disabled=!manual;}});
  var ref=root.querySelector('[name="crmp_transaction_ref"]');if(ref&&enforce&&!p.reference_required&&!file&&!data&&!p.screenshot_required)ref.required=true;
  root.querySelectorAll('[name="crmp_screenshot_data"],[name="crmp_screenshot_name"]').forEach(function(input){input.disabled=!manual;});
  var method=root.querySelector('#crmp_method');if(method){method.required=manual;method.disabled=!manual;}
 }
 ['input','change'].forEach(function(type){document.addEventListener(type,function(event){
  if(event.target.matches('[name="payment_method"]')){document.querySelectorAll('.crmp-checkout').forEach(sync);return;}
  var root=event.target.closest('.crmp-checkout');if(root)sync(root);
 });});
 document.querySelectorAll('.crmp-checkout').forEach(sync);
 if(window.jQuery)jQuery(document.body).on('updated_checkout',function(){document.querySelectorAll('.crmp-checkout').forEach(sync);});
})();

// Pending proof stays collapsed until the customer explicitly chooses to correct it.
document.addEventListener('click',function(event){
 var button=event.target.closest('.crmp-proof-toggle,.crmp-proof-cancel');if(!button)return;
 var panel=button.closest('.crmp-payment-panel'),form=panel?.querySelector('.crmp-proof-form');if(!form)return;
 var cancel=button.classList.contains('crmp-proof-cancel');form.hidden=cancel||!form.hidden;
 var toggle=panel.querySelector('.crmp-proof-toggle');if(toggle)toggle.setAttribute('aria-expanded',form.hidden?'false':'true');
 if(form.hidden){toggle?.focus();}else{form.querySelector('select,input:not([type=hidden]),textarea')?.focus();}
});
