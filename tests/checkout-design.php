<?php
require_once __DIR__."/proof-policy-fixture.php";crmp_test_flexible_policy();
if(!defined('ABSPATH'))exit;
update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'TEST-IBAN','account_title'=>'Test Account')));
update_option('crmp_max_upload_mb',3);
$upload=wp_upload_bits('qr-design-test.png',null,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlSAAAAAASUVORK5CYII='));
$qr=wp_insert_attachment(array('post_mime_type'=>'image/png','post_title'=>'QR fixture','post_status'=>'inherit'),$upload['file']);wp_update_attachment_metadata($qr,array('width'=>1,'height'=>1,'file'=>_wp_relative_upload_path($upload['file'])));
update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'TEST-IBAN','account_title'=>'Test Account','qr_id'=>$qr),'jazzcash'=>array('enabled'=>'yes','account'=>'03000000000','account_title'=>'Wallet Account')));

ob_start();$g=new CRMP_Gateway_Manual();$g->payment_fields();$html=ob_get_clean();
foreach(array('crmp-upload-surface','Choose payment screenshot','Max 3 MB','crmp-checkout-disclaimer','crmp_checkout_ref','name="crmp_screenshot"','Scan QR code to pay','<img','name="crmp_payment_date"','name="crmp_customer_note"') as $text)if(!str_contains($html,$text))throw new RuntimeException('Missing checkout design: '.$text);
if(str_contains($html,'<form')||!preg_match('/<input[^>]*name="crmp_payment_date"[^>]*\brequired\b/i',$html))throw new RuntimeException('Checkout date must be required without nested forms');
file_put_contents('/tmp/crmp-checkout-design.html','<form class="checkout"><input type="radio" name="payment_method" value="crmp_manual" checked>'.$html.'</form>');
crmp_customer_branding();if(!str_contains(implode('',wp_styles()->get_data('crmp-checkout','after')?:array()),'.crmp-checkout,.crmp-block'))throw new RuntimeException('Missing store branding scope');
echo "PASS checkout design: real gateway markup, configured upload limit, configurable proof, no nested form, store branding\n";

$o=wc_create_order();$o->set_currency('PKR');$o->set_total(1499);$o->set_payment_method('crmp_manual');$o->update_meta_data('_crmp_selected_method','bank');$o->set_status('on-hold');$o->save();wp_set_current_user(0);$_GET['key']=$o->get_order_key();global $wpdb;
foreach(array('awaiting','pending','rejected') as $state){
 if($state==='pending')$wpdb->insert(crmp_table(),array('order_id'=>$o->get_id(),'method'=>'bank','amount'=>1499,'currency'=>'PKR','status'=>'pending','submitted_at'=>current_time('mysql')));
 if($state==='rejected')$wpdb->update(crmp_table(),array('status'=>'rejected','note'=>'Please replace proof'),array('order_id'=>$o->get_id()));
 ob_start();crmp_render_payment_evidence($o);$panel=ob_get_clean();if(!str_contains($panel,'Scan QR code to pay')||!str_contains($panel,'<img')||substr_count($panel,'class="crmp-proof-receiving"')!==1)throw new RuntimeException('Missing or duplicate QR for '.$state);
 if($state==='rejected')file_put_contents('/tmp/crmp-qr-order-form.html',$panel);
 echo 'PASS order form QR in '.$state." state\n";
}
$_GET['key']='invalid';ob_start();crmp_render_payment_evidence($o);if(ob_get_clean()!=='')throw new RuntimeException('Unauthorized QR/order details exposed');
echo "PASS QR access authorization retained\n";

$invalid=crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'X','crmp_payment_date'=>'2026-02-30'));if(!is_wp_error($invalid))throw new RuntimeException('Invalid checkout date allowed');
if(!is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_customer_note'=>'No proof'))))throw new RuntimeException('Date/note without proof silently dropped');
if(!is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank'))))throw new RuntimeException('Missing checkout proof accepted');
$context=new \Automattic\WooCommerce\StoreApi\Payments\PaymentContext();$context->set_payment_method('crmp_manual');$context->set_payment_data(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>"BLOCK\\REF'0",'crmp_payment_date'=>'2026-01-03','crmp_customer_note'=>"Block customer's note"));
$payment_result=new \Automattic\WooCommerce\StoreApi\Payments\PaymentResult('success');
do_action_ref_array('woocommerce_rest_checkout_process_payment_with_context',array($context,&$payment_result));
if(wp_unslash($_POST['crmp_customer_note'])!=="Block customer's note"||$_POST['crmp_payment_date']!=='2026-01-03')throw new RuntimeException('Store API details handoff failed');
$_POST=$context->payment_data;$block=wc_create_order();$block->set_currency('PKR');$block->set_total(1499);$block->set_payment_method('crmp_manual');$block->set_status('pending');$block->save();$gateway=new CRMP_Gateway_Manual();$result=$gateway->process_payment($block->get_id());
$record=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d ORDER BY id DESC LIMIT 1',crmp_table(),$block->get_id()));if($result['result']!=='success'||!$record||$record->payment_date!=='2026-01-03'||$record->customer_note!=="Block customer's note"||$record->transaction_ref!=="BLOCK\\REF'0")throw new RuntimeException('Block checkout details lost');
echo "PASS Blocks checkout date/note handoff and ledger persistence; invalid dates rejected; proof required\n";
$_POST=array();

if(!is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'X'))))throw new RuntimeException('Checkout proof accepted without date');
if(!is_wp_error(crmp_customer_proof_details($o,array('crmp_transaction_ref'=>'X','crmp_proof_confirm'=>'1'),true)))throw new RuntimeException('Order proof accepted without date');
echo "PASS required date enforced in both submission paths\n";

if(!is_wp_error(crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_transaction_ref'=>'X','crmp_payment_date'=>'0000-01-01'))))throw new RuntimeException('Invalid year-zero payment date accepted');
echo "PASS invalid year-zero payment date rejected\n";

foreach(array(current_datetime()->format('Y-m-d'),'2099-01-01','invalid') as $date){$date_only=crmp_checkout_proof_details(array('crmp_selected_method'=>'bank','crmp_payment_date'=>$date));if(!is_wp_error($date_only))throw new RuntimeException('Date-only checkout bypassed proof requirement');}
echo "PASS date-only checkout cannot bypass required proof\n";
