<?php
/** Actual WooCommerce calls on the disposable test database, no provider payments. */
if(!defined('ABSPATH'))exit;
function cm_check($name,$ok){if(!$ok)throw new RuntimeException('FAIL CHECKOUT MODE '.$name);echo 'PASS CHECKOUT MODE '.$name."\n";}
wp_set_current_user(1);global $wpdb;
$keys=array('crmp_require_checkout_proof','crmp_proof_policy','crmp_order_proof_policy','crmp_order_proof_same','crmp_method_config','crmp_email_settings');$saved=array();foreach($keys as $key)$saved[$key]=get_option($key,null);$created=array();$_FILES=array();
try{
 update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'MODE-TEST','account_title'=>'Test Store')));update_option('crmp_proof_policy',crmp_default_proof_policy());update_option('crmp_order_proof_same','yes');delete_option('crmp_require_checkout_proof');
 cm_check('mandatory by default',crmp_checkout_proof_required());
 cm_check('missing proof denied without browser validation',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank'))));
 cm_check('request cannot switch merchant mandatory setting',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','require_checkout_proof'=>'0','deferred'=>'1'))));
 update_option('crmp_require_checkout_proof','no');$details=crmp_checkout_proof_details(array('crmp_selected_method'=>'bank'));
 cm_check('optional mode defers empty proof despite default field requirements',!is_wp_error($details)&&$details['deferred']===true&&$details['payment_date']===''&&$details['customer_note']==='');
 cm_check('date alone is an incomplete attempted proof',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_payment_date'=>'2026-01-01'))));
 cm_check('unknown method cannot defer validation',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'not-configured'))));
 cm_check('malformed payload cannot hide inside optional mode',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_screenshot_data'=>array('fake')))));
 cm_check('missing or forged physical upload rejected',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_payment_date'=>'2026-01-01'),null,array('crmp_screenshot'=>array('name'=>'fake.png','tmp_name'=>'/tmp/not-uploaded','error'=>0,'size'=>1)))));
 $policy=crmp_default_proof_policy();$policy['screenshot_enabled']=false;$policy['reference_enabled']=true;$policy['reference_required']=false;update_option('crmp_proof_policy',$policy);
 cm_check('hidden required screenshot is normalized off',!crmp_proof_policy()['screenshot_required']);
 cm_check('individual optional reference still needs actual proof when submitting',is_wp_error(crmp_validate_proof_choice('',false,'order')));
 cm_check('oversized reference rejected rather than silently truncated',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>str_repeat('x',192),'crmp_payment_date'=>'2026-01-01'))));
 cm_check('zero reference is valid information',!is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'0','crmp_payment_date'=>'2026-01-01'))));
 cm_check('disabled screenshot cannot bypass reference-only policy',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'REF','crmp_screenshot_data'=>'data:image/png;base64,AAAA','crmp_payment_date'=>'2026-01-01'))));
 update_option('crmp_proof_policy',crmp_default_proof_policy());
 $order=wc_create_order();$created[]=$order;$order->set_payment_method('crmp_manual');$order->set_currency('PKR');$order->set_total(12);$order->set_status('pending');$order->save();
 $events=0;$count=static function()use(&$events){$events++;};add_action('crmp_proof_submitted',$count);$_POST=array('crmp_selected_method'=>'bank');$gateway=new CRMP_Gateway_Manual();$result=$gateway->process_payment($order->get_id());remove_action('crmp_proof_submitted',$count);
 $fresh=wc_get_order($order->get_id());cm_check('proof-free checkout succeeds but stays unpaid on-hold',$result['result']==='success'&&$fresh->get_status()==='on-hold'&&!$fresh->is_paid()&&!$fresh->get_date_paid()&&$fresh->get_transaction_id()==='');
 cm_check('deferral creates no fake ledger row or proof-received event',(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d',crmp_table(),$order->get_id()))===0&&$events===0);
 ob_start();crmp_render_payment_evidence($fresh);$html=ob_get_clean();cm_check('later proof form stays collapsed and enforces order policy',strpos($html,'crmp-proof-form')!==false&&strpos($html,'hidden class="crmp-payment-proof')!==false&&preg_match('/name="crmp_screenshot"[^>]* required/',$html));
 cm_check('optional checkout never makes order proof optional',is_wp_error(crmp_validate_proof_choice('',false,'order'))&&is_wp_error(crmp_customer_proof_details($fresh,array('crmp_proof_method'=>'bank'),true)));
 $_POST=array('crmp_selected_method'=>'bank','crmp_screenshot_data'=>'data:image/png;base64,AAAA','crmp_payment_date'=>'2026-01-01');cm_check('optional mode still rejects invalid supplied evidence',$gateway->process_payment($order->get_id())['result']==='failure');
 cm_check('invalid optional submission leaves ledger empty',(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d',crmp_table(),$order->get_id()))===0);
 // Save via the real manager-only settings handler, preserving individual flags.
 $emails=crmp_email_config();$post=array('_wpnonce'=>wp_create_nonce('crmp_save_general'),'crmp_save_general'=>'1','admin_emails'=>'mode@example.test','templates'=>wp_json_encode($emails['templates']),'proof_policy'=>crmp_default_proof_policy(),'order_proof_same'=>'1','require_checkout_proof'=>'1');
 $_POST=wp_slash($post);$_REQUEST=$_POST;ob_start();crmp_email_settings_page();$settings=ob_get_clean();cm_check('manager can enable mandatory checkout',crmp_checkout_proof_required()&&strpos($settings,'Settings and email templates saved.')!==false);
 unset($post['require_checkout_proof']);$_POST=wp_slash($post);$_REQUEST=$_POST;ob_start();crmp_email_settings_page();$settings=ob_get_clean();cm_check('manager can disable mandatory checkout',!crmp_checkout_proof_required());
 ob_start();$gateway->payment_fields();$markup=ob_get_clean();cm_check('optional markup has no unconditional required proof fields',strpos($markup,'data-require-proof="0"')!==false&&!preg_match('/name="crmp_(?:screenshot|payment_date)"[^>]* required/',$markup));file_put_contents('/tmp/crmp-optional-checkout.html','<form class="checkout"><input type="radio" name="payment_method" value="crmp_manual" checked>'.$markup.'</form>');
 $block=new CRMP_Block_Method();$block->initialize();cm_check('Blocks receives optional mode from the server',$block->get_payment_method_data()['require_proof']===false);
}finally{foreach($created as $order)$order->delete(true);foreach($saved as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}$_POST=array();$_REQUEST=array();$_FILES=array();}
echo "RESULT: CHECKOUT MODE CHECKS PASSED\n";
