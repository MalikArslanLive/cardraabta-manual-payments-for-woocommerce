<?php
if(!defined('ABSPATH'))exit;
function ps_check($name,$ok){if(!$ok)throw new RuntimeException('FAIL PUBLIC STORE '.$name);echo 'PASS PUBLIC STORE '.$name."\n";}
$old=array();foreach(array('home','blogname','woocommerce_email_base_color','woocommerce_email_header_image','woocommerce_checkout_page_id') as $key)$old[$key]=get_option($key,null);
$logo=get_theme_mod('custom_logo');remove_theme_mod('custom_logo');
$page=wp_insert_post(array('post_title'=>'Checkout fixture','post_type'=>'page','post_status'=>'publish','post_content'=>'[woocommerce_checkout]'));update_option('woocommerce_checkout_page_id',$page);
$order=wc_create_order();$order->set_payment_method('crmp_manual');$order->save();
foreach(array('store-a'=>'#123456','store-b'=>'#654321') as $store=>$color){
 update_option('home','https://'.$store.'.example.test');update_option('blogname',ucfirst($store));update_option('woocommerce_email_base_color',$color);update_option('woocommerce_email_header_image','https://'.$store.'.example.test/logo.png');
 $brand=crmp_customer_brand_header('pending');ps_check($store.' compact status without duplicate logo',!str_contains($brand,'<img')&&!str_contains($brand,'logo.png')&&str_contains($brand,'Payment under review'));
 wp_enqueue_style('crmp-checkout',CRMP_URL.'assets/checkout.css');crmp_customer_branding();$styles=wp_styles()->get_data('crmp-checkout','after');ps_check($store.' colour derives from store setting',str_contains(end($styles),$color));
 ps_check($store.' customer link uses its own domain',wp_parse_url(crmp_email_order_url($order,'customer_rejected'),PHP_URL_HOST)===$store.'.example.test');
}
foreach($old as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}if($logo) set_theme_mod('custom_logo',$logo);else remove_theme_mod('custom_logo');
$readme=file_get_contents(CRMP_PATH.'readme.txt');ps_check('public release metadata matches version',str_contains($readme,'Stable tag: '.CRMP_VERSION)&&CRMP_VERSION==='1.0.0');ps_check('directory readme below 10KB',strlen($readme)<10000);
foreach(array_merge(glob(CRMP_PATH.'includes/*.php'),glob(CRMP_PATH.'assets/*'),array(CRMP_PATH.'cardraabta-manual-payments-for-woocommerce.php')) as $path){ps_check('no merchant-specific domain/account in '.basename($path),!preg_match('/sufaar\.com|03017494455/i',file_get_contents($path)));}
echo "RESULT: PUBLIC STORE AUDIT PASSED\n";
