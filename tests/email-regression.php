<?php
if(!defined('ABSPATH'))exit;
function ce_check($name,$ok){if(!$ok)throw new RuntimeException('FAIL EMAIL '.$name);echo 'PASS EMAIL '.$name."\n";}
function ce_order(){ $o=wc_create_order();$o->set_currency('PKR');$o->set_total(1499);$o->set_payment_method('crmp_manual');$o->set_billing_first_name('Alex');$o->set_billing_email('alex@example.test');$o->update_meta_data('_crmp_selected_method','bank');$o->set_status('on-hold');$o->save();return $o; }
function ce_row($o){global $wpdb;$wpdb->insert(crmp_table(),array('order_id'=>$o->get_id(),'method'=>'bank','amount'=>1499,'currency'=>'PKR','status'=>'pending','transaction_ref'=>'EMAIL-'.wp_rand(),'submitted_at'=>current_time('mysql')));return (int)$wpdb->insert_id;}
function ce_jobs($order){global $wpdb;return $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d ORDER BY id',$wpdb->prefix.'crmp_email_queue',$order->get_id()));}
wp_set_current_user(1);global $wpdb;
if(!username_exists('email-viewer'))wp_create_user('email-viewer','local-viewer-only','viewer@example.test');
$c=crmp_email_config();foreach($c['enabled'] as $id=>$value)$c['enabled'][$id]=true;$c['admin_emails']='admin@example.test, team@example.test';update_option('crmp_email_settings',$c,false);
ce_check('eleven individually configurable email types',count(crmp_email_catalog())===11);
$input=array('admin_emails'=>'admin@example.test','templates'=>wp_json_encode($c['templates']),'enabled'=>$c['enabled'],'delay'=>15);
$bad=$input;$bad['admin_emails']='bad-address';ce_check('invalid recipients do not save',is_wp_error(crmp_save_email_settings($bad))&&crmp_email_config()['admin_emails']===$c['admin_emails']);
$unsafe=crmp_sanitize_email_template('customer_rejected',array('message'=>'<script>alert(1)</script><p onclick="bad()">Safe {customer_name}</p>'));ce_check('editor strips scripts and event handlers',!str_contains($unsafe['message'],'<script')&&!str_contains($unsafe['message'],'onclick'));
$o=ce_order();$id=ce_row($o);crmp_email_event('received',$id);$jobs=ce_jobs($o);ce_check('received notifications queue each admin separately and customer',count($jobs)===3);
crmp_email_event('received',$id);ce_check('replayed submission does not duplicate jobs',count(ce_jobs($o))===3);
$captured=array();$mail_filter=static function($sent,$mail)use(&$captured){$captured[]=$mail;return true;};add_filter('pre_wp_mail',$mail_filter,99,2);
foreach($jobs as $job)crmp_send_email_job($job->id);
ce_check('queued mail handed to transport',count($captured)===3&&ce_jobs($o)[0]->status==='accepted');
foreach($jobs as $job)crmp_send_email_job($job->id);ce_check('accepted mail cannot send twice',count($captured)===3);
ce_check('render contains order and reference with store branding',str_contains($captured[0]['message'],$o->get_order_number())&&str_contains($captured[0]['message'],'EMAIL-'));
$tpl=crmp_email_defaults('customer_rejected');$tpl['show_note']=true;$data=crmp_email_snapshot(crmp_get_transaction($id),$o);$data['admin_note']='';$html=crmp_email_html('customer_rejected',$tpl,$data,home_url('/'));ce_check('empty actual reviewer note hides highlight despite sample text',!str_contains($html,'Reason:</strong>')&&!str_contains($html,$tpl['sample_note']));
$data['admin_note']='<script>unsafe</script>'; $html=crmp_email_html('customer_rejected',$tpl,$data,home_url('/'));ce_check('reviewer note escaped',!str_contains($html,'<script>unsafe')&&str_contains($html,'&lt;script&gt;'));
$o2=ce_order();$r2=ce_row($o2);crmp_email_event('received',$r2);$queued=ce_jobs($o2);ce_check('replacement succeeds',crmp_replace_pending_proof($o2,$r2,1,'UPDATED-'.wp_rand())===true);
foreach($queued as $job)crmp_send_email_job($job->id);ce_check('outdated proof emails suppressed',ce_jobs($o2)[0]->status==='skipped');
ce_check('replacement queued new version',count(ce_jobs($o2))===6);
crmp_review_transaction($r2,'rejected','Readable reference required',2);$rejected=array_filter(ce_jobs($o2),static function($j){return $j->template_id==='customer_rejected';});$j=reset($rejected);crmp_send_email_job($j->id);ce_check('rejection email includes actual review reason',str_contains(end($captured)['message'],'Readable reference required'));
$before=count(ce_jobs($o2));crmp_email_event('rejected',$r2);ce_check('review retry does not duplicate notification',count(ce_jobs($o2))===$before);
$o3=ce_order();$r3=ce_row($o3);crmp_email_event('received',$r3);$j=ce_jobs($o3)[0];$failure=static function(){return false;};add_filter('pre_wp_mail',$failure,100,2);crmp_send_email_job($j->id);remove_filter('pre_wp_mail',$failure,100);
ce_check('definite transport failure logged',ce_jobs($o3)[0]->status==='failed');
$wpdb->update($wpdb->prefix.'crmp_email_queue',array('status'=>'pending'),array('id'=>$j->id));crmp_send_email_job($j->id);ce_check('failed job can retry successfully',ce_jobs($o3)[0]->status==='accepted'&&(int)ce_jobs($o3)[0]->attempts===2);
$c=crmp_email_config();$c['enabled']['admin_received']=false;update_option('crmp_email_settings',$c,false);$o4=ce_order();$r4=ce_row($o4);crmp_email_event('received',$r4);ce_check('disabled notification does not queue',count(ce_jobs($o4))===1);
$c['enabled']['admin_received']=true;update_option('crmp_email_settings',$c,false);
$missing=ce_order();$missing->set_date_created(time()-16*MINUTE_IN_SECONDS);$missing->save();crmp_missing_proof_reminder($missing->get_id());ce_check('missing proof after 15 minutes queues both recipients',count(ce_jobs($missing))===3);crmp_missing_proof_reminder($missing->get_id());ce_check('missing reminder once per order',count(ce_jobs($missing))===3);
ce_row($missing);foreach(ce_jobs($missing) as $job)crmp_send_email_job($job->id);ce_check('proof arriving before dispatch cancels queued reminders',ce_jobs($missing)[0]->status==='skipped');
$submitted=ce_order();$submitted->set_date_created(time()-16*MINUTE_IN_SECONDS);$submitted->save();ce_row($submitted);crmp_missing_proof_reminder($submitted->get_id());ce_check('no missing-proof mail after any submission',count(ce_jobs($submitted))===0);
foreach(array('cancelled','refunded','completed') as $status){$closed=ce_order();$closed->set_date_created(time()-16*MINUTE_IN_SECONDS);$closed->set_status($status);$closed->save();crmp_missing_proof_reminder($closed->get_id());ce_check('no reminder for '.$status,count(ce_jobs($closed))===0);}
$early=ce_order();crmp_missing_proof_reminder($early->get_id());ce_check('no reminder before delay',count(ce_jobs($early))===0);
crmp_manage_transaction($r2,'trash');crmp_manage_transaction($r2,'delete');$scrubbed=ce_jobs($o2);ce_check('permanent transaction deletion scrubs queued review notes and recipient',$scrubbed[0]->payload==='{}'&&$scrubbed[0]->recipient==='');
wp_set_current_user(0);ce_check('settings require manager permission',is_wp_error(crmp_save_email_settings($input)));wp_set_current_user(1);
ob_start();crmp_email_settings_page();$settings_html=ob_get_clean();ce_check('settings contains all eleven switches',substr_count($settings_html,'type="checkbox" name="enabled[')===11);ce_check('preview sandbox and same-page editor present',str_contains($settings_html,'sandbox=""')&&str_contains($settings_html,'crmp_email_message')&&str_contains($settings_html,'Sample reviewer note'));
file_put_contents('/tmp/crmp-email-settings.html',$settings_html);file_put_contents('/tmp/crmp-email-config.json',wp_json_encode(crmp_email_config()));
$retry_o=ce_order();$retry_i=ce_row($retry_o);crmp_email_event('received',$retry_i);$retry_j=ce_jobs($retry_o)[0];add_filter('pre_wp_mail',$failure,100,2);crmp_send_email_job($retry_j->id);remove_filter('pre_wp_mail',$failure,100);file_put_contents('/tmp/crmp-email-fixtures.json',wp_json_encode(array('failed'=>$retry_j->id,'accepted'=>$jobs[0]->id)));
remove_filter('pre_wp_mail',$mail_filter,99);
foreach(crmp_email_catalog() as $template_id=>$type){
 $defaults=crmp_email_defaults($template_id);
 $normalized=crmp_sanitize_email_template($template_id,array('message'=>'<p>&nbsp;<br></p>','subject'=>'','heading'=>'','button'=>''));
 ce_check('empty core fields restore defaults '.$template_id,$normalized['message']===$defaults['message']&&$normalized['subject']===$defaults['subject']&&$normalized['heading']===$defaults['heading']&&$normalized['button']===$defaults['button']);
 ce_check('custom message retained '.$template_id,crmp_sanitize_email_template($template_id,array('message'=>'<p>Custom content</p>'))['message']==='<p>Custom content</p>');
}
require __DIR__.'/customer-form-regression.php';
echo "RESULT: EMAIL REGRESSION PASSED\n";
