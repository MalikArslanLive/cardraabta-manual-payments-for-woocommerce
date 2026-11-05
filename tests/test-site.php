<?php
add_filter("pre_http_request",static function(){return new WP_Error("local_test_no_outbound","Outbound disabled in isolated test site");});
add_filter("pre_wp_mail","__return_true");
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
