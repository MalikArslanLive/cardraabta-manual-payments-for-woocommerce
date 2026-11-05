<?php
/** Disposable site fixture for actual web-server authorization and evidence tests. */
if(!defined('ABSPATH'))exit;
wp_set_current_user(1);global $wpdb;
update_option('crmp_max_upload_mb',5);update_option('crmp_require_checkout_proof','yes');update_option('crmp_proof_policy',crmp_default_proof_policy());update_option('crmp_order_proof_same','yes');
update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','title'=>'Test Bank','account_title'=>'Demo Store','account'=>'TEST-ONLY')));
$order=wc_create_order();$order->set_payment_method('crmp_manual');$order->set_currency('PKR');$order->set_total('10.00');$order->update_meta_data('_crmp_selected_method','bank');$order->set_status('on-hold');$order->save();
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$tmp=wp_tempnam('server-evidence');file_put_contents($tmp,$png);$attachment=crmp_store_upload(array('tmp_name'=>$tmp),$order->get_id(),true);wp_delete_file($tmp);
if(is_wp_error($attachment))throw new RuntimeException($attachment->get_error_message());
$wpdb->insert(crmp_table(),array('order_id'=>$order->get_id(),'method'=>'bank','amount'=>'10.00','currency'=>'PKR','transaction_ref'=>'SERVER-'.wp_rand(),'screenshot_id'=>$attachment,'status'=>'pending','submitted_at'=>current_time('mysql')));$id=(int)$wpdb->insert_id;
$uploads=wp_upload_dir();$path=get_attached_file($attachment);$private=dirname($path);
foreach(array('.htaccess','index.php','web.config') as $guard)if(!is_file($private.'/'.$guard))throw new RuntimeException('Missing private evidence guard: '.$guard);
// Readable plaintext sentinel ensures access denial is a server rule, not file permissions.
$legacy='legacy-server-check-'.wp_generate_uuid4().'.txt';$sentinel='CRMP-PRIVATE-SERVER-SENTINEL';file_put_contents($private.'/'.$legacy,$sentinel);chmod($private.'/'.$legacy,0644);
$fixture=array('id'=>$id,'key'=>$order->get_order_key(),'order'=>$order->get_id(),'encrypted_url'=>trailingslashit($uploads['baseurl']).'crmp-private/'.basename($path),'legacy_url'=>trailingslashit($uploads['baseurl']).'crmp-private/'.$legacy,'directory_url'=>trailingslashit($uploads['baseurl']).'crmp-private/','png_sha256'=>hash('sha256',$png),'sentinel'=>$sentinel,'expected_php'=>PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
file_put_contents('/tmp/crmp-server-fixture.json',wp_json_encode($fixture));
echo '::notice title=Runtime versions::PHP '.PHP_VERSION.'; WordPress '.get_bloginfo('version').'; WooCommerce '.WC_VERSION."\n";
echo "PASS server evidence fixture created\n";
update_option('woocommerce_coming_soon','no');update_option('woocommerce_store_pages_only','no');
update_option('woocommerce_cod_settings',array('enabled'=>'yes','enable_for_virtual'=>'yes'));update_option('woocommerce_currency','PKR');update_option('woocommerce_crmp_manual_settings',array('enabled'=>'yes','title'=>'Manual payment','description'=>'Test manual transfer'));
$product=new WC_Product_Simple();$product->set_name('Server checkout fixture');$product->set_regular_price('10.00');$product->set_price('10.00');$product->set_virtual(true);$product->set_status('publish');$product->save();
$page=wp_insert_post(array('post_title'=>'Server checkout','post_content'=>'[woocommerce_checkout]','post_status'=>'publish','post_type'=>'page'));update_option('woocommerce_checkout_page_id',$page);
$proof_page=wp_insert_post(array('post_title'=>'Server proof','post_content'=>'[crmp_server_proof]','post_status'=>'publish','post_type'=>'page'));update_option('crmp_server_order',$order->get_id());
$fixture['product']=$product->get_id();$fixture['checkout_page']=$page;$fixture['proof_page']=$proof_page;
file_put_contents('/tmp/crmp-server-fixture.json',wp_json_encode($fixture));

$editor_id=username_exists('server-media-editor');
if(!$editor_id)$editor_id=wp_create_user('server-media-editor','test-only-editor','media-editor@example.test');
if(is_wp_error($editor_id))throw new RuntimeException('Could not create editor fixture.');
(new WP_User($editor_id))->set_role('editor');
