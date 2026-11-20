<?php
require_once __DIR__."/proof-policy-fixture.php";crmp_test_flexible_policy();
/** Invoked with wp eval-file tests/payment-workflow.php MODE. Isolated test database only. */
$mode=$args[0]??'';
global $wpdb;
if(get_class($wpdb)==='WP_SQLite_DB'){
 add_filter('query',static function($sql){return preg_match('/^SELECT (GET_LOCK|RELEASE_LOCK)\\(/',$sql)?'SELECT 1':$sql;});
}
wp_set_current_user(1);$_SERVER['REQUEST_METHOD']='POST';
if($mode==='setup'){
 update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'PK001','title'=>'Bank')));
 $order=wc_create_order();$order->set_currency('PKR');$order->set_total('500.00');$order->set_payment_method('crmp_manual');$order->update_meta_data('_crmp_selected_method','bank');$order->set_status('on-hold');$order->save();
 $_POST['crmp_payment_date']=current_datetime()->format('Y-m-d');$_POST['crmp_selected_method']='bank';$_POST['crmp_transaction_ref']='CRMP-REGRESSION-'.wp_rand();$result=crmp_capture_checkout_evidence($order);
 if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
 update_option('crmp_test_order',$order->get_id());echo "PASS proof captured\n";return;
}
global $wpdb;$order=wc_get_order(get_option('crmp_test_order'));
$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d ORDER BY id DESC LIMIT 1',crmp_table(),$order->get_id()));
if(!$row)throw new RuntimeException('Missing proof record.');
if($mode==='verify'||$mode==='reject'){
 $_POST=array('id'=>$row->id,'proof_version'=>$row->proof_version,'decision'=>$mode==='verify'?'verified':'rejected','note'=>'Regression test','_wpnonce'=>wp_create_nonce('crmp_review_'.$row->id));$_REQUEST=$_POST;
 do_action('admin_post_crmp_review');throw new RuntimeException('Review handler did not terminate.');
}
if($mode==='assert-verified'){
 if($row->status!=='verified'||!$order->is_paid())throw new RuntimeException('Review did not mark both order and ledger paid.');
 echo "PASS verified ledger and paid order\n";return;
}
if($mode==='assert-rejected'){
 if($row->status!=='rejected'||$order->get_status()!=='on-hold'||$order->is_paid())throw new RuntimeException('Reject changed payment state incorrectly.');
 echo "PASS rejected ledger and unpaid order\n";return;
}
if($mode==='resubmit'){
 $result=crmp_replace_pending_proof($order,(int)$row->id,(int)$row->proof_version,'CRMP-RESUBMIT-'.wp_rand(),null,array('method'=>'bank','payment_date'=>current_datetime()->format('Y-m-d'),'customer_note'=>''));
 if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
 $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d',crmp_table(),$order->get_id()));
 $fresh=crmp_get_transaction($row->id);if($count!==1||$fresh->status!=='pending'||(int)$fresh->proof_version!==2)throw new RuntimeException('Same-record replacement failed');
 echo "PASS same-record resubmission and proof version\n";return;
}
throw new RuntimeException('Unknown workflow mode.');
