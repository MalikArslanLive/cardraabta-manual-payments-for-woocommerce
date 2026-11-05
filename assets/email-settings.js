/* All template editing stays on CardRaabta Settings. Previews always use sample data. */
jQuery(function($){
 'use strict';
 var config=window.crmpEmails;if(!config)return;
 var templates=config.templates,select=$('#crmp-email-select'),current=select.val(),loading=false,timer,request,sequence=0;
 function editor(){return window.tinymce&&window.tinymce.get('crmp_email_message');}
 function message(){var e=editor();return e&&e.initialized&&!e.isHidden()?e.getContent():$('#crmp_email_message').val();}
 function collect(){if(loading)return;var t=templates[current];$('[data-email-field]').each(function(){var k=this.dataset.emailField;t[k]=this.type==='checkbox'?this.checked:$(this).val();});t.message=message();$('#crmp-email-templates').val(JSON.stringify(templates));}
 function load(){loading=true;var t=templates[current];$('[data-email-field]').each(function(){var v=t[this.dataset.emailField];if(this.type==='checkbox')this.checked=!!v;else $(this).val(v);});$('#crmp_email_message').val(t.message);var e=editor();if(e&&e.initialized)e.setContent(t.message);loading=false;}
 function payload(extra){collect();return $.extend({action:'crmp_email_preview',nonce:config.nonce,template_id:current,template:JSON.stringify(templates[current])},extra||{});}
 function feedback(text,url){var box=$('#crmp-email-feedback').text(text);if(url){box.append(' ',$('<a>',{href:url,text:'View email logs'}));}}
 function preview(){collect();var seq=++sequence;if(request)request.abort();request=$.ajax({url:config.ajaxUrl,method:'POST',dataType:'json',data:payload()}).done(function(r){if(seq!==sequence)return;if(r.success){$('#crmp-email-preview')[0].srcdoc=r.data.html;}else feedback(r.data.message||'Preview unavailable.');}).fail(function(e,status){if(status!=='abort')feedback(e.responseJSON?.data?.message||'Unable to render preview. Please refresh and retry.');});}
 function changed(){if(loading)return;collect();clearTimeout(timer);timer=setTimeout(preview,600);}
 select.on('change',function(){collect();current=this.value;load();preview();});
 $('[data-email-field],#crmp_email_message').on('input change',changed);
 function bindEditor(e){if(!e||e.id!=='crmp_email_message'||e.crmpBound)return;e.crmpBound=true;loading=true;e.setContent(templates[current].message);loading=false;e.on('input change keyup undo redo',changed);}
 $(document).on('tinymce-editor-init',function(event,e){bindEditor(e);});
 $('#crmp-refresh-preview').on('click',preview);
 $('[data-preview-width]').on('click',function(){var mobile=this.dataset.previewWidth==='mobile';$('.crmp-preview-frame-wrap').toggleClass('crmp-mobile-preview',mobile);$('[data-preview-width]').removeClass('button-primary').attr('aria-pressed','false');$(this).addClass('button-primary').attr('aria-pressed','true');});
 $('#crmp-email-settings-form').on('submit',function(){collect();});
 $('#crmp-test-email').on('click',function(){var button=$(this);button.prop('disabled',true);feedback('Sending test email…');$.ajax({url:config.ajaxUrl,method:'POST',dataType:'json',timeout:45000,data:payload({send_test:1,recipient:$('#crmp-test-recipient').val()})}).done(function(r){feedback(r.data.message,r.data.logUrl);}).fail(function(e){feedback(e.responseJSON?.data?.message||'No confirmed send result. Check Email Logs before trying again.',e.responseJSON?.data?.logUrl);}).always(function(){button.prop('disabled',false);});});
 $('#crmp-email-reset').on('click',function(){if(!window.confirm('Restore default text for this email template? Notification switches and recipients will stay unchanged.'))return;var resetId=current;$.ajax({url:config.ajaxUrl,method:'POST',dataType:'json',data:{action:'crmp_email_preview',nonce:config.nonce,template_id:resetId,reset:1}}).done(function(r){if(!r.success){feedback(r.data.message);return;}templates[resetId]=r.data.template;if(current===resetId){load();preview();}feedback('Default template restored in the editor. Save changes to keep it.');}).fail(function(){feedback('Unable to restore defaults.');});});
 $(document).on('click','.crmp-email-retry',function(){var button=$(this);button.prop('disabled',true);$.ajax({url:config.ajaxUrl,method:'POST',dataType:'json',data:{action:'crmp_email_retry',nonce:config.nonce,job_id:button.data('job')}}).done(function(r){feedback(r.data.message);if(r.success)button.text('Queued for retry');else button.prop('disabled',false);}).fail(function(e){feedback(e.responseJSON?.data?.message||'Unable to retry this email.');button.prop('disabled',false);});});
 load();if(editor()&&editor().initialized)bindEditor(editor());preview();$('#crmp-test-email').prop('disabled',false);$('#crmp-email-script-status').prop('hidden',true);
});

// Checkout and order-update policies are independently configurable.
jQuery(function($){
 var same=$('#crmp-order-proof-same'),order=$('#crmp-order-proof-options');
 var refreshers=[];
 $('.crmp-proof-policy').each(function(){
  var box=$(this);
  function row(key){return box.find('[data-proof-field="'+key+'"]');}
  function enabled(key){return row(key).find('.crmp-proof-enabled').prop('checked');}
  function active(){return box.data('policy-context')!=='order'||!same.prop('checked');}
  function sync(){
   box.find('[data-proof-field]').each(function(){var r=$(this),on=r.find('.crmp-proof-enabled').prop('checked');r.find('.crmp-proof-required').prop('disabled',!on);if(!on)r.find('.crmp-proof-required').prop('checked',false);});

   box.find('.crmp-proof-policy-warning').prop('hidden',!active()||enabled('screenshot')||enabled('reference'));
   box.find('.crmp-proof-optional-warning').prop('hidden',!active()||!enabled('screenshot')||!enabled('reference')||box.find('[data-proof-field="screenshot"] .crmp-proof-required').prop('checked')||box.find('[data-proof-field="reference"] .crmp-proof-required').prop('checked'));
  }
  box.on('change','.crmp-proof-enabled,.crmp-proof-required',sync);
  $('#crmp-email-settings-form').on('submit',function(event){if(active()&&!enabled('screenshot')&&!enabled('reference')){event.preventDefault();box.find('.crmp-proof-policy-warning').prop('hidden',false);row('screenshot').find('.crmp-proof-enabled').trigger('focus');}});
  refreshers.push(sync);sync();
 });
 function sameChanged(){order.prop('disabled',same.prop('checked')).prop('hidden',same.prop('checked'));refreshers.forEach(function(sync){sync();});}
 same.on('change',sameChanged);sameChanged();
});
