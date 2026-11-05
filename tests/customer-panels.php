<?php
/** Customer status and real-theme browser fixtures; isolated test database only. */
function cu_assert($label,$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
wp_set_current_user(1);global $wpdb;
$config=get_option('crmp_method_config');update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','title'=>'Bank Transfer','account_title'=>'Example Store Receiving Account','account'=>'PK'.str_repeat('1234567890',4),'instructions'=>'Transfer only if unpaid.')));
$fixtures=array();
foreach(array('awaiting','pending','rejected','verified','pending-trash','pending-purge','rejected-trash','rejected-purge','verified-trash','verified-purge') as $case){
 $order=wc_create_order();$order->set_payment_method('crmp_manual');$order->set_currency('PKR');$order->set_total('12345.67');$order->update_meta_data('_crmp_selected_method','bank');$order->set_status('on-hold');$order->save();$status=explode('-',$case)[0];
 if($case!=='awaiting'){
  $wpdb->insert(crmp_table(),array('order_id'=>$order->get_id(),'method'=>'bank','amount'=>$order->get_total(),'currency'=>'PKR','status'=>$status,'transaction_ref'=>'PRIVATE-REFERENCE-'.str_repeat('R',150),'note'=>'Review note: '.str_repeat('LongUnbrokenNote',20),'reviewed_at'=>current_time('mysql'),'submitted_at'=>current_time('mysql')));$id=(int)$wpdb->insert_id;
  if($status==='verified'){$order->payment_complete('PRIVATE-REFERENCE-'.str_repeat('R',150));$order=wc_get_order($order->get_id());}
  if(str_contains($case,'-')){crmp_manage_transaction($id,'trash');if(str_contains($case,'purge'))crmp_manage_transaction($id,'delete');}
 }
 ob_start();crmp_render_payment_evidence($order);$html=ob_get_clean();
 cu_assert($case.' never exposes internal removal labels',!str_contains($html,'Trash')&&!str_contains($html,'Permanently deleted')&&!str_contains($html,'Transaction #'));
 if(str_contains($case,'-'))cu_assert($case.' hides removed proof and notes',!str_contains($html,'PRIVATE-REFERENCE')&&!str_contains($html,'LongUnbrokenNote'));
 if($status==='verified')cu_assert($case.' remains paid without submission form',str_contains($html,'Payment Verified')&&!str_contains($html,'Submit payment proof'));
 if(str_contains($case,'rejected'))cu_assert($case.' shows rejection and safe resubmission',str_contains($html,'Payment Proof Rejected')&&str_contains($html,'do not pay again')&&str_contains($html,'crmp-proof-form')&&!str_contains($html,'crmp-payment-transfer'));
 if(str_starts_with($case,'pending-'))cu_assert($case.' keeps latest pending status with safe proof request',str_contains($html,'Pending Payment')&&str_contains($html,'do not pay again')&&str_contains($html,'crmp-proof-form')&&!str_contains($html,'crmp-payment-transfer'));
 $page=wp_insert_post(array('post_title'=>'Payment Details','post_status'=>'publish','post_type'=>'page','post_content'=>'[crmp_test_customer_panel]'));update_post_meta($page,'_crmp_test_order',$order->get_id());
 $fixtures[]=array('case'=>$case,'page'=>$page,'key'=>$order->get_order_key(),'expected'=>$status==='verified'?'Payment Verified':($status==='rejected'?'Payment Proof Rejected':($case==='pending'?'Payment Under Review':($status==='pending'?'Pending Payment':'Complete Your Manual Payment'))));
}
// Test-only shortcode runs through the real WordPress theme and the plugin's ownership checks.
wp_mkdir_p(WPMU_PLUGIN_DIR);file_put_contents(WPMU_PLUGIN_DIR.'/crmp-customer-fixtures.php',"<?php\nadd_action('wp_enqueue_scripts',static function(){if(get_post_meta(get_queried_object_id(),'_crmp_test_order',true))add_filter('woocommerce_is_checkout','__return_true');},0);\nadd_shortcode('crmp_test_customer_panel',static function(){ob_start();crmp_render_payment_evidence(wc_get_order(get_post_meta(get_the_ID(),'_crmp_test_order',true)));return ob_get_clean();});\n");
file_put_contents('/tmp/crmp-customer-fixtures.json',wp_json_encode($fixtures));update_option('crmp_customer_test_config',$config,false);
echo "RESULT: customer status regressions passed\n";
