<?php
$GLOBALS['passed']=0;$GLOBALS['failed']=0;
function check($name,$value){global $passed,$failed;if($value){$passed++;echo "PASS $name\n";}else{$failed++;echo "FAIL $name\n";}}
update_option('woocommerce_currency','PKR');
check('gateway registered',in_array('CRMP_Gateway_Manual',apply_filters('woocommerce_payment_gateways',array()),true));
check('method allowlist count',count(crmp_methods())===11);
update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'PK001','title'=>'Bank')));
check('enabled configured method',isset(crmp_enabled_methods()['bank']));
update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'')));
check('empty account excluded',!crmp_enabled_methods());
foreach(array(-100=>1,0=>1,1=>1,5=>5,8=>8,99=>8) as $input=>$expected){update_option('crmp_max_upload_mb',$input);check('upload limit '.$input,crmp_upload_limit()===$expected*1024*1024);}
check('scalar array rejected',crmp_scalar(array('x'))==='');
check('scalar html removed',crmp_scalar('<script>x</script>abc')==='abc');
$order=wc_create_order();$order->set_payment_method('crmp_manual');$order->set_currency('PKR');$order->set_total('123.45');$order->update_meta_data('_crmp_selected_method','bank');$order->set_status('on-hold');$order->save();
$row=(object)array('amount'=>'123.45','currency'=>'PKR','method'=>'bank');
foreach(array('pending','on-hold','failed','processing','completed','cancelled','refunded') as $status){$order->set_status($status);check('review status '.$status,crmp_reviewable_order($order,$row)===in_array($status,array('pending','on-hold','failed'),true));}
$order->set_status('on-hold');
foreach(array('123.44','123.46','0','99999') as $amount){$bad=clone $row;$bad->amount=$amount;check('amount mismatch '.$amount,!crmp_reviewable_order($order,$bad));}
$bad=clone $row;$bad->currency='USD';check('currency mismatch',!crmp_reviewable_order($order,$bad));$bad=clone $row;$bad->method='jazzcash';check('method mismatch',!crmp_reviewable_order($order,$bad));
$order->set_payment_method('cod');check('different gateway rejected',!crmp_reviewable_order($order,$row));$order->set_payment_method('crmp_manual');
wp_set_current_user(0);$_GET['key']='wrong';check('wrong guest order key denied',!crmp_can_view_order($order));$_GET['key']=$order->get_order_key();check('correct guest order key allowed',crmp_can_view_order($order));
$user=wp_create_user('customer-a-'.wp_rand(),'test-only','a'.wp_rand().'@example.test');$other=wp_create_user('customer-b-'.wp_rand(),'test-only','b'.wp_rand().'@example.test');$order->set_customer_id($user);$order->save();
check('guest cannot view registered order',!crmp_can_view_order($order));wp_set_current_user($other);check('different customer denied',!crmp_can_view_order($order));wp_set_current_user($user);check('owner allowed',crmp_can_view_order($order));wp_set_current_user(1);check('manager allowed',crmp_can_view_order($order));
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$tmp=wp_tempnam('proof');file_put_contents($tmp,$png);$file=array('name'=>'test.png','tmp_name'=>$tmp,'size'=>strlen($png),'error'=>0);
$id=crmp_store_upload($file,$order->get_id(),true);check('encrypted upload stored',is_int($id)&&$id>0);
if(is_int($id)&&$id>0){$path=get_attached_file($id);$stored=file_get_contents($path);check('plaintext not stored',strpos($stored,$png)===false);check('non-executable encrypted envelope',str_starts_with($stored,"CRMP1\n"));$read=crmp_read_evidence($path);check('encrypted roundtrip',!is_wp_error($read)&&$read['bytes']===$png);check('private attachment url hidden',!wp_get_attachment_url($id));$raw=base64_decode(substr($stored,6));$raw[30]=chr(ord($raw[30])^1);file_put_contents($path,substr($stored,0,6).base64_encode($raw));check('tampered ciphertext denied',is_wp_error(crmp_read_evidence($path)));wp_delete_attachment($id,true);}
file_put_contents($tmp,'<?php echo "evil";');check('script upload rejected',is_wp_error(crmp_store_upload($file,$order->get_id(),true)));wp_delete_file($tmp);
foreach(array('data:image/svg+xml;base64,PHN2Zz4=','data:text/html;base64,PGgxPg==','garbage','data:image/png;base64,@@@',array('bad')) as $data)check('malformed image URI '.gettype($data),!crmp_validate_data_uri($data));
$order->set_status('completed');$order->save();$_POST['crmp_selected_method']='bank';$gateway=new CRMP_Gateway_Manual();check('paid order process denied',$gateway->process_payment($order->get_id())['result']==='failure');

$order->set_date_paid(null);$order->set_payment_method('crmp_manual');
foreach(array('pending','on-hold','failed','processing','completed','cancelled','refunded') as $status){
 foreach(array('crmp_manual','jazzcash','cod','') as $method){
  foreach(array('PKR','USD','EUR') as $currency){
   foreach(array('123.45','123.44','0') as $amount){
    $order->set_status($status);$order->set_payment_method($method);$candidate=clone $row;$candidate->currency=$currency;$candidate->amount=$amount;
    $expected=in_array($status,array('pending','on-hold','failed'),true)&&$method==='crmp_manual'&&$currency==='PKR'&&$amount==='123.45';
    check('review matrix '.$status.'/'.$method.'/'.$currency.'/'.$amount,crmp_reviewable_order($order,$candidate)===$expected);
   }
  }
 }
}

echo 'RESULT: '.$GLOBALS['passed'].' passed, '.$GLOBALS['failed']." failed\n";
if($GLOBALS['failed'])throw new Exception('Regression tests failed');
