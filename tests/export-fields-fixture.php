<?php
require_once __DIR__."/proof-policy-fixture.php";crmp_test_flexible_policy();
if(!defined('ABSPATH'))exit;
wp_set_current_user(1);global $wpdb;
update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'TEST','title'=>'Example Receiving Bank'),'jazzcash'=>array('enabled'=>'yes','account'=>'03000000000','title'=>'JazzCash Account')));
$o=wc_create_order();$o->set_currency('PKR');$o->set_total(1499);$o->set_payment_method('crmp_manual');$o->update_meta_data('_crmp_selected_method','bank');$o->set_status('on-hold');$o->save();
$old="Customer's first note, \"quoted\"\nاردو details";$new='=1+1';
$_POST=wp_slash(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'FIRST-REF','crmp_payment_date'=>'2026-01-01','crmp_customer_note'=>$old,'crmp_proof_confirm'=>'1'));
$gateway=new CRMP_Gateway_Manual();$result=$gateway->process_payment($o->get_id());if($result['result']!=='success')throw new RuntimeException('Classic checkout capture failed');
$id=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE order_id=%d ORDER BY id DESC LIMIT 1',crmp_table(),$o->get_id()));
$captured=crmp_get_transaction($id);if(!$captured||$captured->payment_date!=='2026-01-01'||$captured->customer_note!==$old)throw new RuntimeException('Classic checkout details missing');
echo "PASS Classic checkout date and note captured through real gateway\n";
$_POST=array();
if(crmp_review_transaction($id,'rejected','Earlier review reason',1)!==true)throw new RuntimeException('Old rejection failed');
$custom=array_merge(crmp_default_proof_policy(),array('screenshot_enabled'=>false,'screenshot_required'=>false,'reference_enabled'=>true,'reference_required'=>true,'note_enabled'=>true));update_option('crmp_order_proof_policy',$custom);update_option('crmp_order_proof_same','no');
$details=crmp_customer_proof_details($o,array('crmp_proof_method'=>'jazzcash','crmp_payment_date'=>'2026-02-02','crmp_customer_note'=>$new,'crmp_proof_confirm'=>'1'),true);if(is_wp_error($details))throw new RuntimeException($details->get_error_message());
if(crmp_replace_pending_proof($o,$id,1,'LATEST-REF',null,$details)!==true)throw new RuntimeException('Replacement failed');
if(crmp_review_transaction($id,'rejected','Latest review note',2)!==true)throw new RuntimeException('Current rejection failed');
// Later settings changes must never reinterpret prior submissions.
update_option('crmp_proof_policy',crmp_default_proof_policy());
$wpdb->insert(crmp_table(),array('order_id'=>$o->get_id(),'method'=>'bank','amount'=>1499,'currency'=>'PKR','transaction_ref'=>'PURGED-REF','status'=>'rejected','submitted_at'=>current_time('mysql'),'purged_at'=>current_time('mysql'),'customer_note'=>'DO-NOT-EXPORT'));$purged=(int)$wpdb->insert_id;
$closure=wc_create_order();$closure->set_currency('PKR');$closure->set_total(7);$closure->set_payment_method('crmp_manual');$closure->update_meta_data('_crmp_selected_method','bank');$closure->set_status('on-hold');$closure->save();
$closure_ids=array();foreach(array('WINNER','SIBLING') as $label){$wpdb->insert(crmp_table(),array('order_id'=>$closure->get_id(),'method'=>'bank','amount'=>7,'currency'=>'PKR','transaction_ref'=>'CSV-'.$label.'-'.$closure->get_id(),'status'=>'pending','submitted_at'=>current_time('mysql')));$closure_ids[]=(int)$wpdb->insert_id;}
if(crmp_review_transaction($closure_ids[0],'verified')!==true)throw new RuntimeException('CSV closure fixture failed');$closed=crmp_get_transaction($closure_ids[1]);if($closed->status!=='closed'||empty($closed->closed_at)||(int)$closed->closed_by_transaction_id!==$closure_ids[0])throw new RuntimeException('CSV closure metadata failed');
file_put_contents('/tmp/crmp-export-fields-fixture.json',wp_json_encode(array('order'=>$o->get_id(),'id'=>$id,'purged'=>$purged,'old'=>$old,'new'=>$new,'closed_id'=>$closure_ids[1],'winner_id'=>$closure_ids[0],'closed_at'=>$closed->closed_at)));
echo "PASS export fixtures: real same-record method/date/customer-note revisions and review decisions\n";
