<?php
require_once __DIR__."/proof-policy-fixture.php";crmp_test_flexible_policy();
/** Run with wp eval-file against an isolated WordPress/WooCommerce database. */
function hr_check($label,$condition){if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function hr_order(){
 $order=wc_create_order();$order->set_currency('PKR');$order->set_total('10.00');$order->set_payment_method('crmp_manual');$order->update_meta_data('_crmp_selected_method','bank');$order->set_status('on-hold');$order->save();return $order;
}
function hr_row($order,$status){
 global $wpdb;$wpdb->insert(crmp_table(),array('order_id'=>$order->get_id(),'method'=>'bank','amount'=>'10.00','currency'=>'PKR','transaction_ref'=>'HR-'.wp_generate_uuid4(),'status'=>$status,'submitted_at'=>current_time('mysql'),'reviewed_by'=>1,'reviewed_at'=>current_time('mysql'),'note'=>'Original decision note'));return crmp_get_transaction((int)$wpdb->insert_id);
}
function hr_panel($id){
 $_GET=array('s'=>'HR-');ob_start();crmp_transactions_page();$html=ob_get_clean();$_GET=array();
 $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);return array($xpath,$xpath->query('//*[@id="crmp-detail-'.$id.'"]')->item(0));
}
wp_set_current_user(1);global $wpdb;$original=get_option('crmp_method_config');
update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','title'=>'Bank','account_title'=>'Test store','account'=>'TEST-ONLY')));
$order=hr_order();$row=hr_row($order,'verifying');$order->payment_complete($row->transaction_ref);$order=wc_get_order($order->get_id());
hr_check('matching paid reservation offers recovery',crmp_review_recovery_available($row,$order));
list($xpath,$panel)=hr_panel($row->id);hr_check('paid reservation renders the recovery form',$panel&&$xpath->query('.//form[contains(@class,"crmp-review-form")]',$panel)->length===1);
hr_check('paid recovery cannot switch to rejection',$xpath->query('.//button[@data-decision="rejected" and @disabled]',$panel)->length===1);
$other=clone $row;$other->transaction_ref.='-wrong';hr_check('different paid reference never offers recovery',!crmp_review_recovery_available($other,$order));
$other=clone $row;$other->amount='11.00';hr_check('changed amount never offers recovery',!crmp_review_recovery_available($other,$order));
$other=clone $row;$other->purged_at=current_time('mysql');hr_check('purged receipt never offers recovery',!crmp_review_recovery_available($other,$order));
$completions=0;$count=static function()use(&$completions){$completions++;};add_action('woocommerce_payment_complete',$count);
hr_check('resume finalizes the original reservation',crmp_review_transaction($row->id,'verified','Must not replace original',1)===true);
remove_action('woocommerce_payment_complete',$count);
hr_check('resume does not repeat payment completion',$completions===0);
$fresh=crmp_get_transaction($row->id);hr_check('resume preserves reviewer note and receipt identity',$fresh->note==='Original decision note'&&$fresh->status==='verified'&&wc_get_order($order->get_id())->get_transaction_id()===$row->transaction_ref);
$rejected_order=hr_order();$rejecting=hr_row($rejected_order,'rejecting');list($xpath,$panel)=hr_panel($rejecting->id);
hr_check('rejection reservation cannot switch to approval',$panel&&$xpath->query('.//button[@data-decision="verified" and @disabled]',$panel)->length===1);
hr_check('rejection reservation keeps its resume action',$xpath->query('.//button[@data-decision="rejected" and not(@disabled)]',$panel)->length===1);
$policy=crmp_test_flexible_policy();$policy['confirmation_required']=true;update_option('crmp_proof_policy',$policy);
$details=crmp_customer_proof_details($rejected_order,array('crmp_payment_date'=>'2026-01-01'),true);
hr_check('removing UI marker cannot bypass the payment declaration',is_wp_error($details)&&$details->get_error_code()==='crmp_confirmation');
crmp_test_flexible_policy();
hr_check('checkout cannot defer payment proof',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank'))));
$failed_order=hr_order();$_POST=array('crmp_selected_method'=>'bank','crmp_payment_date'=>'2026-01-01','crmp_screenshot_data'=>'data:image/png;base64,AAAA');
$gateway=new CRMP_Gateway_Manual();$result=$gateway->process_payment($failed_order->get_id());
hr_check('unreadable screenshot never produces checkout success',$result['result']==='failure');
hr_check('failed proof leaves order unpaid',!wc_get_order($failed_order->get_id())->is_paid());
hr_check('failed proof does not create a ledger record',!(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d',crmp_table(),$failed_order->get_id())));
$_POST=array('crmp_selected_method'=>'bank','crmp_payment_date'=>'2026-01-01','crmp_transaction_ref'=>'HR-RETRY-'.wp_rand());
hr_check('same order can retry with valid proof',$gateway->process_payment($failed_order->get_id())['result']==='success');
$png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
$attachment=crmp_store_data_uri($png,$failed_order->get_id());hr_check('valid evidence is encrypted',!is_wp_error($attachment));
$original_key=get_option('crmp_evidence_key');
try{
 update_option('crmp_evidence_key',str_repeat('z',64));
 hr_check('corrupt key refuses new evidence without a fatal error',is_wp_error(crmp_store_data_uri($png,$failed_order->get_id())));
 hr_check('corrupt key refuses decryption without a fatal error',is_wp_error(crmp_read_evidence(get_attached_file($attachment))));
 hr_check('corrupt key is never silently replaced',get_option('crmp_evidence_key')===str_repeat('z',64));
}finally{update_option('crmp_evidence_key',$original_key);}
hr_check('restored original key still decrypts existing evidence',!is_wp_error(crmp_read_evidence(get_attached_file($attachment))));
wp_delete_attachment($attachment,true);
foreach(array($row,$rejecting) as $record){$wpdb->delete(crmp_table(),array('id'=>$record->id));}
$wpdb->delete(crmp_table(),array('order_id'=>$failed_order->get_id()));foreach(array($order,$rejected_order,$failed_order) as $created)$created->delete(true);
update_option('crmp_method_config',$original);$_POST=array();$_GET=array();
echo "RESULT: hardening regressions passed\n";
