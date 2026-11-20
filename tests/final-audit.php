<?php
require_once __DIR__."/proof-policy-fixture.php";crmp_test_flexible_policy();
/** Behavioral checks in the isolated WordPress/WooCommerce installation only. */
function fa_check($label,$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
wp_set_current_user(1);global $wpdb;
fa_check('Blocks integration loads through the real plugin bootstrap',class_exists('CRMP_Block_Method'));
$block=new CRMP_Block_Method();$block->initialize();$block->get_payment_method_script_handles();
fa_check('Blocks checkout receives its stylesheet',wp_style_is('crmp-checkout','enqueued'));
$config=get_option('crmp_method_config');
update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','title'=>'Bank','account_title'=>'Store','account'=>'PK001'),'jazzcash'=>array('enabled'=>'yes','title'=>'JazzCash','account_title'=>'Store','account'=>'03000000000')));
$order=wc_create_order();$order->set_payment_method('crmp_manual');$order->set_currency('PKR');$order->set_total('10.00');$order->update_meta_data('_crmp_selected_method','bank');$order->set_status('on-hold');$order->save();
$wpdb->insert(crmp_table(),array('order_id'=>$order->get_id(),'method'=>'bank','amount'=>'10.00','currency'=>'PKR','status'=>'pending','transaction_ref'=>'AUDIT-'.wp_rand(),'submitted_at'=>current_time('mysql')));$id=(int)$wpdb->insert_id;
$_POST=array('crmp_selected_method'=>'jazzcash','crmp_transaction_ref'=>crmp_get_transaction($id)->transaction_ref,'crmp_payment_date'=>'2026-01-01');$gateway=new CRMP_Gateway_Manual();
fa_check('retry cannot switch receiving method away from existing proof',$gateway->process_payment($order->get_id())['result']==='failure');
fa_check('refused switch leaves order method and proof unchanged',wc_get_order($order->get_id())->get_meta('_crmp_selected_method')==='bank'&&crmp_get_transaction($id)->status==='pending');
$_POST=array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>crmp_get_transaction($id)->transaction_ref,'crmp_payment_date'=>'2026-01-01');fa_check('same-method retry remains available',$gateway->process_payment($order->get_id())['result']==='success');
fa_check('existing proof can still be verified',crmp_review_transaction($id,'verified')===true);
crmp_manage_transaction($id,'trash');
$wpdb->insert(crmp_table(),array('order_id'=>$order->get_id(),'method'=>'bank','amount'=>'10.00','currency'=>'PKR','status'=>'rejected','transaction_ref'=>'NOT-THE-PAID-RECEIPT','submitted_at'=>current_time('mysql')));$sibling=(int)$wpdb->insert_id;
ob_start();crmp_render_payment_evidence(wc_get_order($order->get_id()));$html=ob_get_clean();
fa_check('paid customer panel does not present unrelated rejected proof when receipt is trashed',str_contains($html,'Payment Verified')&&!str_contains($html,'NOT-THE-PAID-RECEIPT')&&!str_contains($html,'Rejected')&&!str_contains($html,'Submit payment proof'));
$currency=get_option('woocommerce_currency');update_option('woocommerce_currency','USD');
$preview=crmp_related_transaction_payload($id);
fa_check('historical receipt keeps its recorded PKR currency after store currency change',!is_wp_error($preview)&&str_contains($preview['html'],get_woocommerce_currency_symbol('PKR'))&&!str_contains($preview['html'],'&#36;'));
update_option('woocommerce_currency',$currency);
// A previously verified receipt remains authoritative even after an external unpaid reset.
$order=wc_get_order($order->get_id());$order->set_status('on-hold');$order->set_date_paid(null);$order->save();
fa_check('gateway retry refuses a ledger-verified order after external unpaid reset',$gateway->process_payment($order->get_id())['result']==='failure');
$_GET['key']=$order->get_order_key();wp_set_current_user(0);ob_start();crmp_render_payment_evidence($order);$customer=ob_get_clean();fa_check('external unpaid reset never offers another payment form',!str_contains($customer,'crmp-proof-form')&&str_contains($customer,'Do not pay again'));wp_set_current_user(1);
foreach(array($id,$sibling) as $record){$wpdb->delete(crmp_table(),array('id'=>$record));}$order->delete(true);update_option('crmp_method_config',$config);$_POST=array();
echo "RESULT: final audit regressions passed\n";
