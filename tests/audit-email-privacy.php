<?php
require_once __DIR__."/proof-policy-fixture.php";crmp_test_flexible_policy();
if(!defined('ABSPATH'))exit;
// Run inside email-log-regression.php with its isolated mail transport and assertions.
$o=wc_create_order();$o->set_payment_method('crmp_manual');$o->set_currency('PKR');$o->set_total(1499);$o->set_billing_email('privacy@example.test');$o->set_status('on-hold');$o->update_meta_data('_crmp_selected_method','bank');$o->save();
$wpdb->insert(crmp_table(),array('order_id'=>$o->get_id(),'method'=>'bank','amount'=>1499,'currency'=>'PKR','status'=>'pending','transaction_ref'=>'PRIVACY-'.wp_rand(),'customer_note'=>'PRIVATE-CUSTOMER-NOTE','payment_date'=>'2026-01-01','proof_context'=>'order','field_policy'=>wp_json_encode(crmp_proof_policy('order')),'customer_confirmed'=>'yes','submitted_at'=>current_time('mysql')));$privacy_id=(int)$wpdb->insert_id;crmp_email_event('received',$privacy_id);
$pending=$wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE transaction_id=%d',$table,$privacy_id));el_check('privacy fixture queued',count($pending)>0);foreach($pending as $job){$payload=json_decode(el_get($job)->payload,true);el_check('private customer fields stay out of email snapshots',is_array($payload)&&!array_intersect(array('customer_note','payment_date','proof_context','field_policy','customer_confirmed'),array_keys($payload))&&!str_contains(el_get($job)->payload,'PRIVATE-CUSTOMER-NOTE'));}
crmp_email_log_hide($pending);
$wpdb->update(crmp_table(),array('status'=>'rejected'),array('id'=>$privacy_id));
foreach($pending as $job){crmp_send_email_job($job);$done=el_get($job);el_check('hidden stale job scrubs snapshot immediately',$done->status==='skipped'&&$done->payload==='{}'&&$done->recipient===''&&$done->recipient_masked==='');}
$wpdb->update($table,array('recipient_masked'=>'p***@example.test','recipient'=>'privacy@example.test','payload'=>'{"admin_note":"private"}'),array('id'=>$pending[0]));crmp_email_purge_transaction($privacy_id);$done=el_get($pending[0]);el_check('permanent transaction purge also removes masked recipient',$done->recipient===''&&$done->recipient_masked===''&&$done->payload==='{}');
$input=array('crmp_proof_confirm'=>'1','crmp_proof_method'=>'bank','crmp_payment_date'=>'2026-01-01','crmp_customer_note'=>str_repeat('ا',2000));el_check('2000-character Unicode customer note accepted',!is_wp_error(crmp_customer_proof_details($o,$input,true)));$input['crmp_customer_note'].='ا';el_check('2001-character Unicode note rejected',is_wp_error(crmp_customer_proof_details($o,$input,true)));
$_GET['key']=$o->get_order_key();wp_set_current_user(0);ob_start();crmp_render_payment_evidence($o);$public=ob_get_clean();el_check('public form has no unsolicited credit',!str_contains($public,'Powered by'));wp_set_current_user(1);
echo "RESULT: FINAL AUDIT PRIVACY CHECKS PASSED\n";

$unicode=str_repeat('ا',2000);$wpdb->update(crmp_table(),array('status'=>'pending'),array('id'=>$privacy_id));$result=crmp_review_transaction($privacy_id,'rejected',$unicode,1);$row=crmp_get_transaction($privacy_id);el_check('long Urdu review note survives without byte truncation',$result===true&&$row->note===$unicode);
$tpl=crmp_sanitize_email_template('customer_rejected',array('heading'=>str_repeat('ا',2001)));el_check('Unicode email text uses character-safe limit',$tpl['heading']===$unicode);
