<?php
$GLOBALS['mx_pass']=0;$GLOBALS['mx_fail']=0;
function mx_check($name,$condition){if($condition){$GLOBALS['mx_pass']++;echo "PASS $name\n";}else{$GLOBALS['mx_fail']++;echo "FAIL $name\n";}}
global $wpdb;
if(get_class($wpdb)==='WP_SQLite_DB'){
 echo "LOCAL ONLY: named-lock SQL shim; real exclusion is covered by MySQL CI\n";
 add_filter('query',static function($sql){return preg_match('/^SELECT (GET_LOCK|RELEASE_LOCK)\(/',$sql)?'SELECT 1':$sql;});
}
function mx_fixture($ref){global $wpdb;$o=wc_create_order();$o->set_currency('PKR');$o->set_total('10.00');$o->set_payment_method('crmp_manual');$o->update_meta_data('_crmp_selected_method','bank');$o->set_status('on-hold');$o->save();$wpdb->insert(crmp_table(),array('order_id'=>$o->get_id(),'method'=>'bank','amount'=>'10.00','currency'=>'PKR','transaction_ref'=>$ref,'status'=>'pending','submitted_at'=>current_time('mysql')));return array($o,(int)$wpdb->insert_id);}
wp_set_current_user(0);mx_check('review capability denied',is_wp_error(crmp_review_transaction(1,'verified')));wp_set_current_user(1);
mx_check('invalid decision denied',is_wp_error(crmp_review_transaction(1,'arbitrary')));
list($order,$id)=mx_fixture('MX'.wp_rand());mx_check('normal verification succeeds',crmp_review_transaction($id,'verified')===true);
mx_check('paid order status confirmed',wc_get_order($order->get_id())->is_paid());mx_check('review replay rejected',is_wp_error(crmp_review_transaction($id,'verified')));
$ref='DUP'.wp_rand();list($o1,$i1)=mx_fixture($ref);list($o2,$i2)=mx_fixture(strtolower($ref));mx_check('first shared reference verified',crmp_review_transaction($i1,'verified')===true);mx_check('case equivalent duplicate denied',is_wp_error(crmp_review_transaction($i2,'verified')));
list($o,$i)=mx_fixture('HOLD'.wp_rand());$filter=static function($status,$order_id)use($o){return (int)$order_id===$o->get_id()?'on-hold':$status;};add_filter('woocommerce_payment_complete_order_status',$filter,10,2);
mx_check('failed completion not claimed paid',is_wp_error(crmp_review_transaction($i,'verified','Original reviewer note')));remove_filter('woocommerce_payment_complete_order_status',$filter,10);
mx_check('interrupted decision reserved',$wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id=%d',crmp_table(),$i))==='verifying');
$o=wc_get_order($o->get_id());$o->set_status('processing');$o->set_transaction_id($wpdb->get_var($wpdb->prepare('SELECT transaction_ref FROM %i WHERE id=%d',crmp_table(),$i)));$o->save();
mx_check('safe interrupted review recovery',crmp_review_transaction($i,'verified','Replacement note must not overwrite')===true);
$review=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',crmp_table(),$i));mx_check('retry preserves original note',$review->note==='Original reviewer note');mx_check('retry preserves original reviewer',(int)$review->reviewed_by===get_current_user_id());
list($o,$i)=mx_fixture('REJECT'.wp_rand());mx_check('reject succeeds',crmp_review_transaction($i,'rejected','Please resubmit')===true);mx_check('reject stays unpaid',!wc_get_order($o->get_id())->is_paid());
$review=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',crmp_table(),$i));mx_check('rejected review note retained',$review->note==='Please resubmit');mx_check('rejected reviewer retained',(int)$review->reviewed_by===get_current_user_id());
mx_check('rejected payment can be verified after confirmation',crmp_review_transaction($i,'verified')===true);$o=wc_get_order($o->get_id());mx_check('rejected retry marks the same order paid once',$o->is_paid()&&hash_equals((string)$review->transaction_ref,(string)$o->get_transaction_id()));mx_check('verified decision cannot be reversed through review',is_wp_error(crmp_review_transaction($i,'rejected')));
$wpdb->insert(crmp_table(),array('order_id'=>$o->get_id(),'method'=>'bank','amount'=>'10.00','currency'=>'PKR','transaction_ref'=>$review->transaction_ref,'status'=>'pending','submitted_at'=>current_time('mysql')));$duplicate_id=(int)$wpdb->insert_id;mx_check('second submission for paid order cannot be approved',is_wp_error(crmp_review_transaction($duplicate_id,'verified')));$wpdb->delete(crmp_table(),array('id'=>$duplicate_id));
foreach(array('pending','rejected','verified','verifying','rejecting') as $deleted_status){
 list($deleted_order,$deleted_id)=mx_fixture('DELETED'.wp_rand());$deleted_order->delete(true);
 $wpdb->update(crmp_table(),array('status'=>$deleted_status,'note'=>'Historical note','reviewed_by'=>1,'reviewed_at'=>'2026-11-03 00:00:00'),array('id'=>$deleted_id));
 $before=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',crmp_table(),$deleted_id),ARRAY_A);
 foreach(array('verified','rejected') as $attempt){$result=crmp_review_transaction($deleted_id,$attempt,'Must not overwrite');mx_check('deleted '.$deleted_status.' refuses '.$attempt,is_wp_error($result)&&$result->get_error_code()==='crmp_order_deleted');}
 $after=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',crmp_table(),$deleted_id),ARRAY_A);
 mx_check('deleted '.$deleted_status.' preserves entire ledger record',$before===$after);$wpdb->delete(crmp_table(),array('id'=>$deleted_id));
}
list($trashed_order,$trashed_id)=mx_fixture('TRASH'.wp_rand());$trashed_order->delete(false);
foreach(array('verified','rejected') as $attempt){$result=crmp_review_transaction($trashed_id,$attempt);mx_check('trashed order refuses '.$attempt,is_wp_error($result)&&$result->get_error_code()==='crmp_order_deleted');}
mx_check('trashed order preserves pending status',$wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id=%d',crmp_table(),$trashed_id))==='pending');$trashed_order->delete(true);$wpdb->delete(crmp_table(),array('id'=>$trashed_id));
mx_check('malformed file array rejected',!crmp_validate_upload(array('tmp_name'=>array('bad'),'name'=>'x.png','size'=>1)));
mx_check('invalid direct base64 rejected',is_wp_error(crmp_store_data_uri('garbage',1)));
echo 'RESULT: '.$GLOBALS['mx_pass'].' passed, '.$GLOBALS['mx_fail']." failed\n";
if($GLOBALS['mx_fail'])throw new RuntimeException('Extended manual regression failed.');
