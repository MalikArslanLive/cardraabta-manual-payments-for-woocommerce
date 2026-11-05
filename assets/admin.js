document.addEventListener('click', function(event) {
    var button = event.target.closest('.crmp-toggle-details');
    if (!button) return;
    var row = document.getElementById(button.getAttribute('aria-controls'));
    if (!row) return;
    var expanded = button.getAttribute('aria-expanded') === 'true';
    row.hidden = expanded;
    var scroller = row.closest('.crmp-table-scroll');
    if (!expanded && scroller) scroller.scrollLeft = 0;
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    button.textContent = expanded ? 'View Details' : 'Hide Details';
});

jQuery(function($) {
    $(document).on('click', '.crmp-qr-upload', function() {
        var b = $(this),
            box = b.closest('.crmp-panel'),
            frame = wp.media({
                title: 'Select QR code',
                library: {type:'image'},
                button: {
                    text: 'Use QR code'
                },
                multiple: false
            });
        frame.on('select', function() {
            var a = frame.state().get('selection').first().toJSON();
            box.find('.crmp-qr-id').val(a.id);
            box.find('.crmp-qr-preview').empty().append($('<img>',{src:a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url,alt:'Selected payment QR code'}).css('max-width','120px'));
        });
        frame.open();
    });
    $(document).on('click', '.crmp-qr-remove', function() {
        var b = $(this).closest('.crmp-panel');
        b.find('.crmp-qr-id').val('');
        b.find('.crmp-qr-preview').empty();
    });
    $(document).on('click', '.crmp-review-decision', function() {
        var button = $(this), form = button.closest('.crmp-review-form'),
            box = form.find('.crmp-inline-confirm'), decision = button.data('decision');
        form.find('.crmp-review-decision').each(function() {
            $(this).data('was-disabled', this.disabled);
            this.disabled = true;
        });
        box.data('decision', decision).find('.crmp-confirm-message').text(decision === 'verified'
            ? 'Are you sure? Confirming will mark this WooCommerce order as paid. Check the funds and transaction reference before continuing.'
            : 'Are you sure you want to reject this payment proof? The order will remain unpaid.');
        box.prop('hidden', false).find('.crmp-confirm-yes').trigger('focus');
    });
    $(document).on('click', '.crmp-confirm-cancel', function() {
        var form = $(this).closest('.crmp-review-form');
        form.find('.crmp-inline-confirm').prop('hidden', true);
        form.find('.crmp-review-decision').each(function() {
            this.disabled = $(this).data('was-disabled') === true;
        });
    });
    $(document).on('click', '.crmp-confirm-yes', function() {
        var form = $(this).closest('.crmp-review-form'), decision = form.find('.crmp-inline-confirm').data('decision');
        if(form.data('submitting'))return;
        form.data('submitting',true);this.disabled=true;
        form.find('.crmp-review-feedback').remove();
        $.ajax({url:crmpAdmin.ajaxUrl,method:'POST',data:form.serialize()+'&decision='+encodeURIComponent(decision),dataType:'json'}).done(function(result){
            if(result.success){window.location.href=result.data.redirect;return;}
            showReviewError(result.data);
        }).fail(function(error){showReviewError(error.responseJSON?.data||{message:'Unable to review this payment. Refresh and check its current status before retrying.'});});
        function showReviewError(data){
            form.data('submitting',false);form.find('.crmp-inline-confirm').prop('hidden',true);
            var feedback=$('<div>',{class:'crmp-review-feedback',role:'alert'}).append($('<p>').text(data.message||'Unable to review this payment.'));
            if(data.reloadUrl){$('<a>',{class:'button button-primary',href:data.reloadUrl,text:'Review latest proof'}).appendTo(feedback);}
            else{$('<button>',{type:'button',class:'button',text:'Refresh transaction'}).on('click',function(){window.location.reload();}).appendTo(feedback);}
            form.append(feedback);feedback.attr('tabindex','-1').trigger('focus');
        }
    });
});

jQuery(function($) {
    function api(action, values) {
        if (!window.crmpAdmin) return $.Deferred().reject({responseJSON:{data:{message:'Refresh this page before continuing.'}}}).promise();
        return $.ajax({url:crmpAdmin.ajaxUrl,method:'POST',data:$.extend({action:action,nonce:crmpAdmin.nonce},values),dataType:'json'});
    }
    function message(error) {
        return error && error.responseJSON && error.responseJSON.data && error.responseJSON.data.message
            ? error.responseJSON.data.message : 'Unable to complete this request. Refresh the page and retry.';
    }
    $(document).on('click', '.crmp-view-related', function() {
        var button=$(this), id=Number(button.data('transaction-id')),
            box=button.closest('.crmp-paid-source').next('.crmp-related-result'), existing=document.getElementById('crmp-detail-'+id);
        if (existing) {
            existing.hidden=false;
            var toggle=document.querySelector('.crmp-toggle-details[aria-controls="crmp-detail-'+id+'"]');
            if(toggle){toggle.setAttribute('aria-expanded','true');toggle.textContent='Hide Details';toggle.focus();}
            existing.scrollIntoView({behavior:'smooth',block:'center'});
            return;
        }
        if(button.attr('aria-expanded')==='true'){box.prop('hidden',true);button.attr('aria-expanded','false');return;}
        box.prop('hidden',false).text('Loading transaction…');button.prop('disabled',true);
        api('crmp_related_transaction',{id:id}).done(function(result){
            if(!result.success){box.text(result.data.message||'Transaction unavailable.');return;}
            box.html(result.data.html);button.attr('aria-expanded','true');
            var panel=box.find('.crmp-related-panel')[0];if(panel){panel.focus({preventScroll:true});panel.scrollIntoView({behavior:'smooth',block:'nearest'});}
        }).fail(function(error){box.text(message(error));}).always(function(){button.prop('disabled',false);});
    });
    $(document).on('click', '.crmp-record-action', function() {
        var button=$(this), group=button.closest('.crmp-record-actions'), box=group.find('.crmp-record-confirm'), operation=button.data('operation');
        var prompts={trash:'Move this transaction to Trash? The payment status and WooCommerce order will stay unchanged.',restore:'Restore this transaction? Its payment status is preserved; this does not approve or process a payment.',delete:'Permanently delete this transaction’s proof, screenshots, review notes and all previous proof history? This cannot be undone or restored. A minimal payment reference is retained to prevent duplicate approval. The WooCommerce order stays unchanged.',empty:'Empty Trash and permanently remove proof, screenshots, review notes and all previous proof history for every eligible trashed transaction? This cannot be undone or restored. Minimal payment references are retained to prevent duplicate approval. WooCommerce orders stay unchanged.'};
        box.data('operation',operation).data('id',Number(button.data('id'))).prop('hidden',false).find('p').text(prompts[operation]);
        if(!box.find('.crmp-record-confirm-yes').length)$('<button>',{type:'button',class:'button button-primary crmp-record-confirm-yes',text:'Yes, continue'}).insertBefore(box.find('.crmp-record-confirm-cancel'));
        box.find('.crmp-record-confirm-yes').prop('hidden',false);box.find('.crmp-review-pending').remove();if(operation==='trash'&&Number(button.data('pending-review'))===1){box.find('p').text('Payment review is pending. Verify or Reject this proof before moving it to Trash.');box.find('.crmp-record-confirm-yes').remove();$('<button>',{type:'button',class:'button button-primary crmp-review-pending',text:'Review Payment'}).appendTo(box);}
        box.find('.crmp-review-pending,.crmp-record-confirm-yes').first().trigger('focus');
    });
    $(document).on('click','.crmp-review-pending',function(){var panel=$(this).closest('.crmp-detail');panel.find('.crmp-review-form input[name=note]').trigger('focus');$(this).closest('.crmp-record-confirm').prop('hidden',true);});
    $(document).on('click', '.crmp-record-confirm-cancel', function(){ $(this).closest('.crmp-record-confirm').prop('hidden',true); });
    $(document).on('click', '.crmp-record-confirm-yes', function() {
        var yes=$(this), box=yes.closest('.crmp-record-confirm'), operation=box.data('operation'), id=box.data('id'), notice=$('.crmp-management-notice');
        if(yes.data('busy'))return;yes.data('busy',true);box.find('button').prop('disabled',true);notice.text('Saving…');
        var cursor=0,total=0,skipped=0;
        function run() {
            api('crmp_manage_transactions',{operation:operation,id:id,after_id:cursor}).done(function(result){
                if(!result.success){fail(result.data.message||'Unable to save this operation.');return;}
                if(operation==='empty'){total+=result.data.processed.length;skipped+=Object.keys(result.data.skipped).length;cursor=result.data.after_id;if(result.data.more){run();return;}if(skipped){notice.text(total+' permanently deleted; '+skipped+' skipped because review is pending or a record changed. Refresh this page.');finish();box.prop('hidden',true);reloadList(notice.text());return;}}
                if(operation==='empty'){$('.crmp-detail-row').remove();$('.crmp-toggle-details').closest('tr').remove();}
                else {var row=$('#crmp-detail-'+id);row.prev('tr').remove();row.remove();}
                $(document).trigger('crmp-selection-update');
                notice.text(operation==='trash'?'Transaction moved to Trash.':operation==='restore'?'Transaction restored.':'Trash cleared. Proof and notes removed; payment references preserved.');
                box.prop('hidden',true);finish();reloadList(notice.text());
            }).fail(function(error){fail(message(error));});
        }
        function reloadList(text){try{sessionStorage.setItem('crmpBulkNotice',JSON.stringify({path:location.pathname+location.search,message:text}));}catch(e){}window.location.reload();}
        function finish(){yes.data('busy',false);box.find('button').prop('disabled',false);}
        function fail(text){notice.text(text);finish();}
        run();
    });
});

jQuery(function($){
 try{var saved=JSON.parse(sessionStorage.getItem('crmpBulkNotice')||'null');if(saved&&saved.path===location.pathname+location.search){$('.crmp-management-notice').text(saved.message);sessionStorage.removeItem('crmpBulkNotice');}}catch(e){}
 function selected(){return $('.crmp-select-row:checked:not(:disabled)').map(function(){return Number(this.value);}).get();}
 function refresh(){var all=$('.crmp-select-row:not(:disabled)'),ids=selected();$('.crmp-selected-count').text(ids.length+' selected');$('.crmp-bulk-action').prop('disabled',!ids.length);$('.crmp-select-page').prop('checked',all.length>0&&ids.length===all.length).prop('indeterminate',ids.length>0&&ids.length<all.length);$('.crmp-bulk-confirm').prop('hidden',true);}
 $(document).on('change','.crmp-select-page',function(){$('.crmp-select-row:not(:disabled)').prop('checked',this.checked);refresh();});
 $(document).on('change','.crmp-select-row',refresh);
 $(document).on('crmp-selection-update',refresh);
 $(document).on('click','.crmp-bulk-action',function(){var ids=selected();if(!ids.length)return;var operation=$(this).data('operation'),verb=operation==='bulk-approve'?'Approve payments for':operation==='bulk-reject'?'Reject pending proofs in':operation==='bulk-restore'?'Restore':operation==='bulk-delete'?'Permanently delete':'Move to Trash';$('.crmp-bulk-confirm').data('operation',operation);$('.crmp-bulk-reason').prop('hidden',operation!=='bulk-reject'&&operation!=='bulk-approve');$('.crmp-bulk-note-label').text(operation==='bulk-approve'?'Approval note (optional, visible to customers)':'Reason for rejection (required, visible to customers)');if($('.crmp-bulk-confirm').data('last-operation')!==operation){$('.crmp-bulk-note').val('');}$('.crmp-bulk-confirm').data('last-operation',operation);$('.crmp-bulk-confirm p').text(verb+' '+ids.length+' selected transactions on this page? '+(operation==='bulk-delete'?'Proof, screenshots, review notes and all previous proof history for the selected transactions will be permanently deleted. This cannot be undone or restored. ':'')+(operation==='bulk-approve'?'Confirm that you have checked the funds and latest proof for every selected order. Eligible unpaid WooCommerce orders will be marked as paid. Already-paid orders and changed proof will be skipped.':operation==='bulk-reject'?'Orders remain unpaid. Customers can submit new proof. Only pending proofs are eligible.':'WooCommerce orders and payment statuses will remain unchanged.'));$('.crmp-bulk-yes').text(operation==='bulk-approve'?'Yes, mark as paid':operation==='bulk-reject'?'Yes, reject payments':'Yes, continue');$('.crmp-bulk-confirm').prop('hidden',false);$('.crmp-bulk-yes').trigger('focus');});
 $(document).on('click','.crmp-bulk-cancel',function(){$('.crmp-bulk-confirm').prop('hidden',true);});
 $(document).on('click','.crmp-bulk-yes',function(){var ids=selected();if(!ids.length||this.disabled||!window.crmpBulk)return;var note=$('.crmp-bulk-note').val().trim();if($('.crmp-bulk-confirm').data('operation')==='bulk-reject'&&!note){$('.crmp-management-notice').text('Enter a reason for rejecting the selected pending proofs.');$('.crmp-bulk-note').trigger('focus');return;}var controls=$('.crmp-bulk-controls button,.crmp-select-row:not(:disabled),.crmp-select-page');controls.prop('disabled',true);$.ajax({url:crmpAdmin.ajaxUrl,method:'POST',dataType:'json',data:{action:'crmp_manage_transactions',operation:$('.crmp-bulk-confirm').data('operation'),nonce:crmpAdmin.nonce,bulk_nonce:crmpBulk.nonce,page_ids:crmpBulk.ids,ids:ids,versions:crmpBulk.versions,note:note}}).done(function(result){if(result.success){try{sessionStorage.setItem('crmpBulkNotice',JSON.stringify({path:location.pathname+location.search,message:result.data.message}));}catch(e){}window.location.reload();return;}$('.crmp-management-notice').text(result.data.message);}).fail(function(error){$('.crmp-management-notice').text(error.responseJSON?.data?.message||'Unable to move the selection. Refresh and retry.');}).always(function(){controls.prop('disabled',false);$('.crmp-bulk-confirm').prop('hidden',true);refresh();});});
});
