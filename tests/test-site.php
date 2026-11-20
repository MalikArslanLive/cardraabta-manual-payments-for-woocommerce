<?php
add_filter('pre_http_request',static function($preempt,$args,$url){
 // Model an up-to-date installation without allowing update checks onto the network.
 if(wp_parse_url($url,PHP_URL_HOST)==='api.wordpress.org'){
  $path=(string)wp_parse_url($url,PHP_URL_PATH);$body=null;
  // CLI scanners may read authentic public plugin metadata; never fabricate it.
  if(defined('WP_CLI')&&WP_CLI&&strpos($path,'/plugins/info/')===0)return $preempt;
  if(strpos($path,'/core/version-check/')===0)$body=array('offers'=>array());
  elseif(strpos($path,'/plugins/update-check/')===0)$body=array('plugins'=>array(),'translations'=>array(),'no_update'=>array());
  elseif(strpos($path,'/themes/update-check/')===0)$body=array('themes'=>array(),'translations'=>array(),'no_update'=>array());
  if($body!==null)return array('headers'=>array(),'body'=>wp_json_encode($body),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array());
 }
 return new WP_Error('local_test_no_outbound','Outbound disabled in isolated test site');
},10,3);
add_filter("pre_wp_mail","__return_true");
// WP-CLI 3 admin context must not exit into WooCommerce's first-run wizard.
// This fixture skips only onboarding redirects, never CardRaabta activation/tests.
add_filter('woocommerce_prevent_automatic_wizard_redirect','__return_true');
// WooCommerce 8.3 assumes a remote-feed transient is an array even when missing.
// Seed the empty offline cache rather than suppressing its PHP 8.1 deprecation.
add_filter('data_source_poller_data_sources',static function($sources,$id){
 $name='woocommerce_admin_'.$id.'_specs';
 if(get_transient($name)===false)set_transient($name,array(),DAY_IN_SECONDS);
 return $sources;
},10,2);
// Core update checks are unrelated to this isolated payment fixture and would
// intentionally fail against the outbound HTTP block above. Keep plugin errors visible.
remove_action('admin_init','_maybe_update_core');
remove_action('admin_init','_maybe_update_plugins');
remove_action('admin_init','_maybe_update_themes');
add_shortcode('crmp_release_panel',function(){ $f=get_option('crmp_release_fixture');ob_start();crmp_render_payment_evidence(wc_get_order($f['order']));return ob_get_clean(); });
add_shortcode('crmp_release_checkout',function(){ob_start();$g=new CRMP_Gateway_Manual();$g->payment_fields();return ob_get_clean();});
add_action('wp_enqueue_scripts',function(){if(defined('WP_CLI')&&WP_CLI)return;wp_enqueue_style('crmp-test',CRMP_URL.'assets/checkout.css');});
// Test-only runtime probe; this mu-plugin is excluded from the production ZIP.
add_action('template_redirect',static function(){if(isset($_GET['crmp_environment_check']))wp_send_json(array('php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'server'=>sanitize_text_field($_SERVER['SERVER_SOFTWARE']??'')));});
add_shortcode('crmp_server_proof',static function(){ob_start();crmp_render_payment_evidence(wc_get_order(get_option('crmp_server_order')));return ob_get_clean();});

// Test-only: force real payment fragment replacement instead of a no-op refresh.
add_filter('woocommerce_update_order_review_fragments',static function($fragments){
 if(isset($fragments['.woocommerce-checkout-payment']))$fragments['.woocommerce-checkout-payment']=preg_replace('/<div\b/','<div data-crmp-fragment-probe="'.wp_generate_uuid4().'"',$fragments['.woocommerce-checkout-payment'],1);
 return $fragments;
});

add_action('template_redirect',static function(){if(isset($_GET['crmp_object_cache_probe']))wp_send_json(array('persistent'=>wp_using_ext_object_cache(),'marker'=>wp_cache_get('persistent-probe','crmp-tests')));});

add_shortcode('crmp_cache_render_probe',static function(){return '<span hidden data-crmp-cache-render="'.esc_attr(wp_generate_uuid4()).'"></span>';});

// Public-cache diagnostic only; never emits customer values or credentials.
add_action('template_redirect',static function(){
 if(strpos($_SERVER['REQUEST_URI']??'','/crmp-public-cache-')===false)return;
 $probe_file=function_exists('get_current_url_supercache_dir')?get_current_url_supercache_dir().'index.html':'';
 header('X-CRMP-Cache-Diagnostic: '.wp_json_encode(array('wp_cache'=>defined('WP_CACHE')&&WP_CACHE,'dont_cache'=>defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE,'phase1'=>!empty($GLOBALS['wp_cache_phase1_loaded']),'cache_enabled'=>$GLOBALS['cache_enabled']??null,'super_cache'=>$GLOBALS['super_cache_enabled']??null,'user_id'=>get_current_user_id(),'cache_file_exists'=>$probe_file&&is_file($probe_file),'cache_file_readable'=>$probe_file&&is_readable($probe_file),'worker_pid'=>getmypid(),'late_init'=>$GLOBALS['wp_super_cache_late_init']??null,'serve_disabled'=>defined('WPSC_SERVE_DISABLED'))));
},999);
