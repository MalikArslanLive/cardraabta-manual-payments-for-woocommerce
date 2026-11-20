<?php
/** Field visibility, backend enforcement, inheritance and atomic settings saves. */
if(!defined('ABSPATH'))exit;
function pp_check($name,$ok){if(!$ok)throw new RuntimeException('FAIL POLICY '.$name);echo 'PASS POLICY '.$name."\n";}
wp_set_current_user(1);
$keys=array('crmp_require_checkout_proof','crmp_proof_policy','crmp_order_proof_policy','crmp_order_proof_same','crmp_email_settings','crmp_method_config','crmp_max_upload_mb');$saved=array();foreach($keys as $key)$saved[$key]=get_option($key,null);
try{
 update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'POLICY-TEST')));
 delete_option('crmp_require_checkout_proof');delete_option('crmp_proof_policy');delete_option('crmp_order_proof_same');delete_option('crmp_order_proof_policy');
 $default=crmp_proof_policy();pp_check('default only screenshot and date enabled/required',$default===crmp_default_proof_policy()&&count(array_filter($default))===4);
 ob_start();crmp_render_proof_inputs(true);$html=ob_get_clean();
 pp_check('default HTML omits reference note and confirmation',strpos($html,'crmp_transaction_ref')===false&&strpos($html,'crmp_customer_note')===false&&strpos($html,'crmp_proof_confirm')===false&&strpos($html,'name="crmp_screenshot"')!==false&&strpos($html,'name="crmp_payment_date"')!==false);
 pp_check('reference cannot bypass default screenshot requirement',is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'FAKE','crmp_payment_date'=>'2026-01-01'))));
 $alloff=array_fill_keys(array_keys($default),false);pp_check('both proof options disabled rejected',is_wp_error(crmp_validate_proof_policy($alloff)));
 $ref=$alloff;$ref['reference_enabled']=true;update_option('crmp_proof_policy',$ref);
 pp_check('sole proof requirement remains configurable',!crmp_proof_policy()['reference_required']&&is_wp_error(crmp_validate_proof_choice('',false)));
 pp_check('zero reference remains a valid supplied value',crmp_customer_reference(array('crmp_transaction_ref'=>'0'))==='0'&&crmp_validate_proof_choice('0',false)===true);
 pp_check('reference alone accepted when date disabled',!is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'REF-ONLY'))));
 $input=array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'REF-ONLY','crmp_payment_date'=>'invalid','crmp_customer_note'=>'ignored','crmp_proof_confirm'=>'0','proof_context'=>'order','field_policy'=>'forged','customer_confirmed'=>'yes');$details=crmp_checkout_proof_details($input);
 pp_check('disabled fields never collected',!is_wp_error($details)&&$details['payment_date']===''&&$details['customer_note']===''&&$details['customer_confirmed']===''&&$details['proof_context']==='checkout'&&json_decode($details['field_policy'],true)===crmp_proof_policy());
 pp_check('disabled screenshot payload rejected',is_wp_error(crmp_validate_proof_choice('REF',true)));
 $both=$ref;$both['screenshot_enabled']=true;update_option('crmp_proof_policy',$both);
 pp_check('either proof accepted but neither rejected',crmp_validate_proof_choice('REF',false)===true&&crmp_validate_proof_choice('',true)===true&&is_wp_error(crmp_validate_proof_choice('',false)));
 $both['screenshot_required']=true;$both['reference_required']=true;update_option('crmp_proof_policy',$both);
 pp_check('both explicitly required enforced',is_wp_error(crmp_validate_proof_choice('REF',false))&&is_wp_error(crmp_validate_proof_choice('',true))&&crmp_validate_proof_choice('REF',true)===true);
 foreach(array('date'=>'crmp_payment_date','note'=>'crmp_customer_note','confirmation'=>'crmp_proof_confirm') as $field=>$name){
  $policy=$ref;$policy[$field.'_enabled']=true;$policy[$field.'_required']=false;update_option('crmp_proof_policy',$policy);
  pp_check($field.' optional permits omission',!is_wp_error(crmp_customer_proof_details(null,array('crmp_selected_method'=>'bank'))));
  $policy[$field.'_required']=true;update_option('crmp_proof_policy',$policy);
  pp_check($field.' required blocks omission',is_wp_error(crmp_customer_proof_details(null,array('crmp_selected_method'=>'bank'))));
  pp_check($field.' valid required value accepted',!is_wp_error(crmp_customer_proof_details(null,array('crmp_selected_method'=>'bank',$name=>$field==='date'?'2026-01-01':($field==='confirmation'?'1':'Payment note')))));
 }
 update_option('crmp_proof_policy',$default);update_option('crmp_order_proof_policy',$ref);update_option('crmp_order_proof_same','yes');
 pp_check('order inherits checkout by default',crmp_proof_policy('order')===$default);
 update_option('crmp_order_proof_same','no');pp_check('order independently uses reference policy',crmp_proof_policy('order')['reference_enabled']&&!crmp_proof_policy('order')['screenshot_enabled']);
 ob_start();crmp_render_proof_inputs(false);$html=ob_get_clean();pp_check('order HTML independently renders configured fields',strpos($html,'crmp_transaction_ref')!==false&&strpos($html,'name="crmp_screenshot"')===false&&strpos($html,'crmp_payment_date')===false);
 pp_check('checkout screenshot policy does not leak into order validation',crmp_validate_proof_choice('ORDER-REF',false,'order')===true&&is_wp_error(crmp_validate_proof_choice('ORDER-REF',false,'checkout')));
 pp_check('legacy confirmation and policy remain unknown',crmp_recorded_confirmation((object)array())==='Not recorded'&&crmp_recorded_field_settings((object)array())==='Not recorded');
 // Exercise the actual settings save path, including nonce and manager checks.
 $emails=crmp_email_config();$post=array('_wpnonce'=>wp_create_nonce('crmp_save_general'),'crmp_save_general'=>'1','require_checkout_proof'=>'1','admin_emails'=>'policy@example.test','templates'=>wp_json_encode($emails['templates']),'delay'=>'20','max_mb'=>'7','proof_policy'=>$alloff,'order_proof_same'=>'1');
 $_POST=wp_slash($post);$_REQUEST=$_POST;ob_start();crmp_email_settings_page();$html=ob_get_clean();
 pp_check('invalid checkout policy prevents all settings writes',strpos($html,'Enable at least one')!==false&&crmp_proof_policy()===$default&&get_option('crmp_email_settings',null)===$saved['crmp_email_settings']&&get_option('crmp_max_upload_mb',null)===$saved['crmp_max_upload_mb']);
 $post['proof_policy']=$default;$post['order_proof_policy']=$alloff;unset($post['order_proof_same']);$_POST=wp_slash($post);$_REQUEST=$_POST;ob_start();crmp_email_settings_page();$html=ob_get_clean();
 pp_check('invalid custom order policy prevents save',strpos($html,'Enable at least one')!==false&&crmp_proof_policy('order')['reference_enabled']);
 $post['order_proof_policy']=$ref;$_POST=wp_slash($post);$_REQUEST=$_POST;ob_start();crmp_email_settings_page();$html=ob_get_clean();
 pp_check('valid independent policies save together',strpos($html,'Settings and email templates saved.')!==false&&get_option('crmp_order_proof_same')==='no'&&crmp_proof_policy()===$default&&crmp_proof_policy('order')['reference_enabled']);
 file_put_contents('/tmp/crmp-proof-policy-settings.html',$html);
}finally{foreach($saved as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}$_POST=array();$_REQUEST=array();}
echo "RESULT: FIELD POLICY CHECKS PASSED\n";
