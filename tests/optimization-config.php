<?php
/** Disposable-site settings for actual optimizer/page/object cache integrations. */
if(!defined('ABSPATH'))exit;
wp_set_current_user(1);$mode=$args[0]??'aggregate';
if(!in_array($mode,array('aggregate','inline'),true))throw new RuntimeException('Unknown optimizer scenario');
if(!wp_using_ext_object_cache())throw new RuntimeException('Persistent object cache is not active');
wp_cache_set('persistent-probe','alive','crmp-tests',300);
if(!defined('WP_CACHE')||!WP_CACHE)throw new RuntimeException('Page cache drop-in disabled');
if(!function_exists('wp_cache_enable')||!function_exists('wp_super_cache_enable'))throw new RuntimeException('WP Super Cache setup functions unavailable');
wp_cache_enable();wp_super_cache_enable();
// Mirror the cache settings screen: its slash policy must match our permalink.
wp_cache_setting('wp_cache_slash_check',substr(get_option('permalink_structure'),-1)==='/'?1:0);
wp_cache_setting('wpsc_served_header',true);
if(getenv('CRMP_CACHE_ONLY')==='1'){wp_cache_setting('wp_super_cache_debug',1);wp_cache_setting('wp_cache_debug_to_file',1);wp_cache_setting('wp_cache_debug_ip','');wpsc_create_debug_log('crmp-public-cache-debug.php');}
foreach(array('cache_enabled'=>true,'super_cache_enabled'=>true,'wp_super_cache_late_init'=>0,'wp_cache_mod_rewrite'=>0,'cache_compression'=>true,'wp_cache_not_logged_in'=>true,'wp_cache_no_cache_for_get'=>true,'cache_max_time'=>300) as $key=>$value)wp_cache_setting($key,$value);
wp_cache_setting('cache_rejected_uri',array('wp-.*\\.php','index\\.php','/cart','/checkout','/my-account','server-checkout','server-proof','cardraabta-manual-payments-for-woocommerce','wc-ajax'));
$settings=array('autoptimize_html'=>'on','autoptimize_js'=>'on','autoptimize_css'=>'on','autoptimize_js_include_inline'=>'on','autoptimize_css_include_inline'=>'on','autoptimize_css_aggregate'=>'on','autoptimize_optimize_checkout'=>'on','autoptimize_optimize_logged'=>'on','autoptimize_js_trycatch'=>'','autoptimize_js_forcehead'=>'','autoptimize_js_justhead'=>'','autoptimize_css_defer'=>'','autoptimize_cache_nogzip'=>'on','autoptimize_js_exclude'=>'wp-includes/js/dist/,wp-includes/js/tinymce/,js/jquery/jquery.js,js/jquery/jquery.min.js','autoptimize_css_exclude'=>'admin-bar.min.css,dashicons.min.css,wp-content/cache/');
$settings['autoptimize_js_aggregate']=$mode==='aggregate'?'on':'';$settings['autoptimize_js_defer_not_aggregate']=$mode==='inline'?'on':'';$settings['autoptimize_css_inline']=$mode==='inline'?'on':'';
foreach($settings as $key=>$value)update_option($key,$value);
if(class_exists('autoptimizeCache')&&method_exists('autoptimizeCache','clearall'))autoptimizeCache::clearall();
$page=wp_insert_post(array('post_title'=>'Public cache probe '.$mode,'post_name'=>'crmp-public-cache-'.$mode,'post_content'=>'<p>PUBLIC-CACHE-PROBE: no customer data.</p>[crmp_cache_render_probe]','post_status'=>'publish','post_type'=>'page'));
file_put_contents('/tmp/crmp-optimization-fixture.json',wp_json_encode(array('mode'=>$mode,'public_url'=>get_permalink($page))));
$versions=array();foreach(array('autoptimize/autoptimize.php','wp-super-cache/wp-cache.php','redis-cache/redis-cache.php') as $file){$data=get_plugin_data(WP_PLUGIN_DIR.'/'.$file,false,false);$versions[$file]=$data['Version'];}
echo '::notice title=Optimization versions::'.wp_json_encode(array('plugins'=>$versions,'mode'=>$mode,'persistent_object_cache'=>wp_using_ext_object_cache(),'public_url'=>get_permalink($page),'advanced_cache_present'=>is_file(WP_CONTENT_DIR.'/advanced-cache.php'),'cache_enabled'=>$GLOBALS['cache_enabled']??null,'super_cache_enabled'=>$GLOBALS['super_cache_enabled']??null))."\n";

$stored=(static function(){include WP_CONTENT_DIR.'/wp-cache-config.php';return get_defined_vars();})();
$flags=array();foreach(array('cache_enabled','super_cache_enabled','wp_super_cache_late_init','wp_cache_debug_to_file','wp_cache_slash_check') as $key)$flags[$key]=$stored[$key]??null;
echo '::notice title=Stored cache serve flags::'.wp_json_encode($flags)."\n";
echo "PASS real optimizer/cache configuration saved\n";
