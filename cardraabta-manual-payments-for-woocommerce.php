<?php
/**
 * Plugin Name: CardRaabta Manual Payments for WooCommerce
 * Description: Manual Pakistani wallet, Raast and bank transfer payments with evidence submission and independent verification ledger.
 * Version: 1.0.0
 * WC requires at least: 8.3
 * WC tested up to: 11.2.2
 * Requires Plugins: woocommerce
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: Malik Arslan
 * Author URI: https://malikarslan.com/
 * Plugin URI: https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce
 * Text Domain: cardraabta-manual-payments-for-woocommerce
 */
if (!defined('ABSPATH')) { exit; }
define('CRMP_VERSION','1.0.0');
define('CRMP_PATH',plugin_dir_path(__FILE__));
define('CRMP_URL',plugin_dir_url(__FILE__));
/** Deterministic cache keys also work when a same-version ZIP preserves file times. */
function crmp_asset_version($asset){
 static $versions=array();
 if(!isset($versions[$asset])){
  $path=CRMP_PATH.$asset;$hash=is_file($path)?hash_file('sha256',$path):false;
  $versions[$asset]=CRMP_VERSION.($hash?'.'.substr($hash,0,12):'');
 }
 return $versions[$asset];
}
require_once CRMP_PATH.'includes/security.php';
require_once CRMP_PATH.'includes/transaction-admin.php';
require_once CRMP_PATH.'includes/proof-replacement.php';
require_once CRMP_PATH.'includes/emails.php';
require_once CRMP_PATH.'includes/email-settings.php';
require_once CRMP_PATH.'includes/email-logs.php';
require_once CRMP_PATH.'includes/customer-form.php';
add_action('before_woocommerce_init',static function(){
 if(class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')){
  \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables',__FILE__,true);
  \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks',__FILE__,true);
 }
});
function crmp_methods() { return array(
 'jazzcash'=>array('JazzCash','Mobile Number'),
 'easypaisa'=>array('Easypaisa','Mobile Number'),
 'sadapay'=>array('SadaPay','Account Number / IBAN'),
 'nayapay'=>array('NayaPay','Account Number / IBAN'),
 'bank'=>array('Bank Transfer (IBFT)','IBAN / Account Number'),
 'raast'=>array('Raast ID / IBAN','Raast ID / IBAN'),
 'zindigi'=>array('Zindigi','Account Number'),
 'upaisa'=>array('UPaisa','Mobile Number'),
 'konnect'=>array('HBL Konnect','Account Number'),
 'deposit'=>array('Cash Bank Deposit','Bank Account / IBAN'),
 'custom'=>array('Custom Payment Method','Account / Payment Identifier')
); }
function crmp_table() { global $wpdb; return $wpdb->prefix.'crmp_transactions'; }
function crmp_install() {
 global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
 $charset=$wpdb->get_charset_collate();
 $sql="CREATE TABLE ".crmp_table()." (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 order_id bigint(20) unsigned NOT NULL,
 method varchar(40) NOT NULL,
 amount decimal(18,2) NOT NULL DEFAULT 0,
 currency varchar(8) NOT NULL DEFAULT 'PKR',
 transaction_ref varchar(191) NOT NULL DEFAULT '',
 payment_date varchar(10) NOT NULL DEFAULT '',
 customer_note text NULL,
 proof_context varchar(10) NOT NULL DEFAULT '',
 field_policy text NULL,
 customer_confirmed varchar(3) NOT NULL DEFAULT '',
 screenshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
 proof_version bigint(20) unsigned NOT NULL DEFAULT 1,
 replacement_count int unsigned NOT NULL DEFAULT 0,
 status varchar(24) NOT NULL DEFAULT 'pending',
 submitted_at datetime NOT NULL,
 reviewed_at datetime NULL,
 reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
 note text NULL,
 trashed_at datetime NULL,
 trashed_by bigint(20) unsigned NOT NULL DEFAULT 0,
 purged_at datetime NULL,
 closed_from_status varchar(24) NOT NULL DEFAULT '',
 closed_at datetime NULL,
 closed_by_transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (id),
 KEY order_id (order_id),
 KEY status (status),
 KEY visibility (purged_at,trashed_at),
 KEY transaction_ref (transaction_ref)
 ) $charset;";
 dbDelta($sql);
 dbDelta("CREATE TABLE ".$wpdb->prefix."crmp_proof_revisions (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 transaction_id bigint(20) unsigned NOT NULL,
 proof_version bigint(20) unsigned NOT NULL,
 method varchar(40) NOT NULL DEFAULT '',
 transaction_ref varchar(191) NOT NULL DEFAULT '',
 payment_date varchar(10) NOT NULL DEFAULT '',
 customer_note text NULL,
 proof_context varchar(10) NOT NULL DEFAULT '',
 field_policy text NULL,
 customer_confirmed varchar(3) NOT NULL DEFAULT '',
 screenshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
 submitted_at datetime NOT NULL,
 replaced_at datetime NOT NULL,
 review_status varchar(20) NOT NULL DEFAULT 'pending',
 review_note text NULL,
 reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
 reviewed_at datetime NULL,
 PRIMARY KEY  (id),
 KEY transaction_id (transaction_id)
 ) $charset;");
 crmp_install_email_queue($charset);
 update_option('crmp_db_version','11');
}
register_activation_hook(__FILE__,'crmp_install');
register_deactivation_hook(__FILE__,'crmp_email_deactivate');
add_action('plugins_loaded',function(){
 if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) { return; }
 if (get_option('crmp_db_version')!=='11') { crmp_install(); }
 require_once CRMP_PATH.'includes/gateways.php';
 add_filter('woocommerce_payment_gateways',function($gateways){ return array_merge($gateways,crmp_gateway_classes()); });
 if(did_action('woocommerce_blocks_loaded'))crmp_register_blocks();
},20);
add_action('woocommerce_blocks_loaded','crmp_register_blocks');
function crmp_register_blocks(){
 static $registered=false;
 if($registered)return;
 if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) return;
 $registered=true;
 require_once CRMP_PATH.'includes/blocks.php';
 add_action('woocommerce_blocks_payment_method_type_registration',function($registry){ $registry->register(new CRMP_Block_Method()); });
}
function crmp_setup_guide_url(){return 'https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce/blob/main/docs/HOSTING-SETUP.md';}
add_action('admin_menu',function(){
 add_menu_page('CardRaabta Payments','CardRaabta Payments','manage_woocommerce','crmp','crmp_admin_page','dashicons-money-alt',56);
 add_submenu_page('crmp','Payment Methods','Payment Methods','manage_woocommerce','crmp','crmp_admin_page');
 $transactions_hook=add_submenu_page('crmp','Transactions','Transactions','manage_woocommerce','crmp-transactions','crmp_transactions_page');
 add_action('load-'.$transactions_hook,static function(){add_screen_option('per_page',array('label'=>'Transactions','default'=>20,'option'=>'crmp_transactions_per_page'));});
 add_submenu_page('crmp','Settings','Settings','manage_woocommerce','crmp-settings','crmp_settings_page');
 $logs_hook=add_submenu_page('crmp','Email Logs','Email Logs','manage_woocommerce','crmp-email-logs','crmp_email_logs_page');
 add_action('load-'.$logs_hook,static function(){add_screen_option('per_page',array('label'=>'Email logs','default'=>20,'option'=>'crmp_email_logs_per_page'));});
 // External submenu URLs must bypass the page-slug normalization of add_submenu_page.
 if(current_user_can('manage_woocommerce')){
  global $submenu;
  $submenu['crmp'][]=array('Setup Guide <span class="dashicons dashicons-external" aria-hidden="true" style="font-size:12px;width:12px;height:12px;vertical-align:baseline"></span><span class="screen-reader-text"> (opens in a new tab)</span>','manage_woocommerce',esc_url(crmp_setup_guide_url()));
 }
});
add_action('admin_enqueue_scripts',static function(){
 if(!current_user_can('manage_woocommerce'))return;
 wp_add_inline_script('common','document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("#toplevel_page_crmp .wp-submenu a").forEach(function(link){if(link.href==='.wp_json_encode(crmp_setup_guide_url()).'){link.target="_blank";link.rel="noopener noreferrer";}});});');
});
add_action('admin_enqueue_scripts',function($hook){ if (strpos($hook,'crmp')===false && strpos($hook,'woocommerce_page_wc-settings')===false) return; $style_version=crmp_asset_version('assets/admin.css');wp_enqueue_style('crmp-admin',CRMP_URL.'assets/admin.css',array(),$style_version); });
function crmp_admin_page(){
 if(!current_user_can('manage_woocommerce'))return;
 $methods=crmp_methods();$all=get_option('crmp_method_config',array());
 if(isset($_POST['crmp_save']) && check_admin_referer('crmp_save_methods')){
  crmp_require_post();
  $submitted=isset($_POST['methods']) && is_array($_POST['methods'])?map_deep(wp_unslash($_POST['methods']),'sanitize_textarea_field'):array();
  $result=crmp_save_method_config($submitted);
  if(is_wp_error($result)){
   // Keep submitted values visible without persisting any part of an invalid configuration.
   $all=crmp_sanitize_method_config($submitted);
   echo '<div class="notice notice-error"><p>'.esc_html($result->get_error_message()).'</p></div>';
  }else{
   $all=$result;echo '<div class="notice notice-success"><p>Payment methods saved.</p></div>';
  }
 }
 wp_enqueue_media();wp_enqueue_script('crmp-admin',CRMP_URL.'assets/admin.js',array('jquery'),crmp_asset_version('assets/admin.js'),true);
 echo '<div class="wrap crmp-wrap"><h1>CardRaabta Manual Payments for WooCommerce</h1><p>Enable one gateway in <a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=crmp_manual')).'">WooCommerce → Settings → Payments</a>. Choose and configure receiving methods below.</p><div class="crmp-tabs"><a class="active" href="'.esc_url(admin_url('admin.php?page=crmp')).'">Payment Methods</a><a href="'.esc_url(admin_url('admin.php?page=crmp-transactions')).'">Transactions</a><a href="'.esc_url(admin_url('admin.php?page=crmp-settings')).'">Settings</a></div><form method="post">';wp_nonce_field('crmp_save_methods');
 foreach($methods as $slug=>$m){$v=$all[$slug]??array();$enabled=($v['enabled']??'no')==='yes';
 echo '<details class="crmp-panel crmp-method" '.($enabled?'open':'').'><summary><span class="crmp-method-title">'.esc_html($v['title']??$m[0]).'</span><span class="crmp-method-toggle"><label><input type="checkbox" name="methods['.esc_attr($slug).'][enabled]" value="1" '.checked($enabled,true,false).'> Enable</label></span><span class="crmp-expand">Edit settings ▾</span></summary><div class="crmp-grid">';
 crmp_field($slug,'title','Display name',$v['title']??$m[0]);crmp_field($slug,'account_title','Account Title',$v['account_title']??'');crmp_field($slug,'account',$m[1],$v['account']??'');
 echo '<div><label><strong>QR Code (optional)</strong></label><div class="crmp-qr-preview">';$id=absint($v['qr_id']??0);if($id)echo wp_get_attachment_image($id,'thumbnail');echo '</div><input type="hidden" class="crmp-qr-id" name="methods['.esc_attr($slug).'][qr_id]" value="'.absint($id).'"><button type="button" class="button crmp-qr-upload">Upload QR</button> <button type="button" class="button crmp-qr-remove">Remove</button></div>';
 echo '<div class="crmp-wide"><label><strong>Checkout instructions</strong></label><textarea rows="3" name="methods['.esc_attr($slug).'][instructions]">'.esc_textarea($v['instructions']??'Transfer the exact amount and submit your transaction reference or screenshot before placing the order.').'</textarea></div></div></details>';
 }
 submit_button('Save Payment Methods','primary','crmp_save');echo '</form></div>';
}
/** Normalize only known methods and scalar fields before validation or rendering. */
function crmp_sanitize_method_config($submitted){
 $all=array();
 foreach(crmp_methods() as $slug=>$method){
  $row=isset($submitted[$slug]) && is_array($submitted[$slug])?$submitted[$slug]:array();
  $all[$slug]=array('enabled'=>isset($row['enabled']) && is_scalar($row['enabled']) && (string)$row['enabled']==='1'?'yes':'no');
  foreach(array('title','account_title','account','instructions') as $field){
   $value=isset($row[$field]) && is_scalar($row[$field])?(string)$row[$field]:'';
   $all[$slug][$field]=$field==='instructions'?sanitize_textarea_field($value):sanitize_text_field($value);
  }
  $all[$slug]['qr_id']=isset($row['qr_id']) && is_scalar($row['qr_id'])?absint($row['qr_id']):0;
 }
 return $all;
}
/** Validate the entire form before changing the stored receiving instructions. */
function crmp_save_method_config($submitted){
 if(!current_user_can('manage_woocommerce'))return new WP_Error('crmp_forbidden','Not allowed.');
 $all=crmp_sanitize_method_config(is_array($submitted)?$submitted:array());$errors=array();
 foreach(crmp_methods() as $slug=>$method){
  if($all[$slug]['enabled']!=='yes')continue;
  $missing=array();
  foreach(array('title'=>'Display name','account_title'=>'Account Title','account'=>$method[1]) as $field=>$label){
   if(trim($all[$slug][$field])==='')$missing[]=$label;
  }
  if($missing)$errors[]=$method[0].': '.implode(', ',$missing).'.';
  $qr=$all[$slug]['qr_id'];if($qr&&(!wp_attachment_is_image($qr)||get_post_meta($qr,'_crmp_private_evidence',true)||!wp_get_attachment_image_url($qr,'medium')))$errors[]=$method[0].': choose a valid public image for the QR code.';
 }
 if($errors)return new WP_Error('crmp_incomplete_methods','Payment methods were not saved. Complete the following fields for each enabled method, or disable that method: '.implode(' ',$errors));
 update_option('crmp_method_config',$all,false);
 return $all;
}
/** Reviewer identity is admin-only; retain the recorded ID if the user no longer exists. */
function crmp_reviewer_label($reviewer_id){
 $id=absint($reviewer_id);if(!$id)return 'Reviewer not recorded';
 $user=get_userdata($id);
 return $user?$user->display_name.' (User #'.$id.')':'Deleted user (User #'.$id.')';
}
function crmp_admin_review_details($row){
 crmp_customer_detail_summary($row);
 if(isset($row->order_id,$row->status)){echo wp_kses(crmp_paid_source_html($row),array('p'=>array('class'=>true),'button'=>array('type'=>true,'class'=>true,'data-transaction-id'=>true,'aria-expanded'=>true),'div'=>array('class'=>true,'hidden'=>true)));}
 if(($row->status??'')==='closed'){if(!empty($row->closed_from_status))echo '<p><strong>Previous decision:</strong> '.esc_html(crmp_status_label($row->closed_from_status)).'</p>';echo '<p>This submission was closed without verifying its proof.</p><p><strong>Closed at:</strong> '.esc_html($row->closed_at??'Not recorded').'</p>';}
 echo '<div class="crmp-review-details"><p><strong>Reviewed by:</strong> '.esc_html(crmp_reviewer_label($row->reviewed_by)).'</p><p><strong>Reviewed at:</strong> '.esc_html($row->reviewed_at?:'Not reviewed yet').'</p><p><strong>Reviewer note:</strong><br>'.(trim((string)$row->note)!==''?nl2br(esc_html($row->note)):'No notes were added.').'</p></div>';
}
function crmp_enabled_methods(){
 $config=get_option('crmp_method_config',array());$enabled=array();foreach(crmp_methods() as $slug=>$m){$v=$config[$slug]??array();if(($v['enabled']??'no')==='yes' && !empty($v['account']))$enabled[$slug]=$v;}return $enabled;
}
function crmp_method_label($slug){$config=get_option('crmp_method_config',array());return $config[$slug]['title']??(crmp_methods()[$slug][0]??$slug);}
function crmp_field($slug,$name,$label,$value){ echo '<div><label><strong>'.esc_html($label).'</strong></label><input type="text" class="regular-text" name="methods['.esc_attr($slug).']['.esc_attr($name).']" value="'.esc_attr($value).'"></div>'; }
function crmp_settings_page(){crmp_email_settings_page();}
function crmp_status_label($status){ return array('pending'=>'Pending','verifying'=>'Verification requires review','rejecting'=>'Rejection requires review','verified'=>'Verified','rejected'=>'Rejected','closed'=>'Closed — Order already paid')[$status]??ucfirst($status); }
add_action('admin_init','crmp_reconcile_paid_submissions');
add_action('admin_init','crmp_export_transactions');
function crmp_export_transactions(){
 global $wpdb; $table=crmp_table();
 if(!isset($_GET['crmp_export']) || !current_user_can('manage_woocommerce') || sanitize_key(wp_unslash($_GET['page']??''))!=='crmp-transactions')return;
 check_admin_referer('crmp_export');
 if(sanitize_key(wp_unslash($_GET['crmp_export']))==='history'){crmp_export_proof_history();exit;}
 {
  $last_id=0;
  while(ob_get_level()>0){if(!ob_end_clean())break;}
  nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="cardraabta-manual-transactions.csv"');
  $out=fopen('php://output','w'); fputcsv($out,array('ID','Order','Method','Amount','Currency','Transaction ID','Screenshot ID','Status','Submitted','Reviewed','Reviewer','Note','Closed at','Paid through transaction','Location','Previous decision','Proof version','Payment date','Customer note','Proof source','Customer confirmation','Field settings'),',','"','');
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
  do{$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE purged_at IS NULL AND id>%d ORDER BY id ASC LIMIT 500",$table,$last_id),ARRAY_A);foreach($rows as $r) { $last_id=(int)$r['id'];$cells=array($r['id'],$r['order_id'],$r['method'],$r['amount'],$r['currency'],$r['transaction_ref'],$r['screenshot_id'],$r['status'],$r['submitted_at'],$r['reviewed_at'],crmp_reviewer_label($r['reviewed_by']),$r['note'],$r['closed_at'],$r['closed_by_transaction_id']?:'',!empty($r['trashed_at'])?'Trash':'Active',$r['closed_from_status'],$r['proof_version'],$r['payment_date']??'',$r['customer_note']??'',crmp_recorded_proof_source((object)$r),crmp_recorded_confirmation((object)$r),crmp_recorded_field_settings((object)$r)); fputcsv($out,array_map(function($v){$v=(string)$v;return preg_match('/^\s*[=+@\-]/u',$v)?"'".$v:$v;},$cells),',','"',''); }}while(count($rows)===500);
 // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the php://output CSV response stream; no filesystem write.
 fclose($out); exit;
 }
}
add_filter('set_screen_option_crmp_transactions_per_page',static function($saved,$option,$value){return max(1,min(100,absint($value)));},10,3);
function crmp_transactions_page(){
 if(!current_user_can('manage_woocommerce') || !function_exists('wc_get_order')) return;
 crmp_reconcile_paid_submissions();
 wp_enqueue_script('crmp-transactions-admin',CRMP_URL.'assets/admin.js',array('jquery'),crmp_asset_version('assets/admin.js'),true);
 wp_localize_script('crmp-transactions-admin','crmpAdmin',array('ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('crmp_transactions')));
 $notice=get_transient('crmp_review_notice_'.get_current_user_id());if($notice){delete_transient('crmp_review_notice_'.get_current_user_id());echo '<div class="notice notice-warning" role="alert"><p>'.esc_html($notice).' The latest transaction details are shown below.</p></div>';}
 global $wpdb; $table=crmp_table();
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only visibility filter, manager capability checked above.
 $trash=isset($_GET['trash'])&&sanitize_key(wp_unslash($_GET['trash']))==='1';
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table filters; capability checked above.
 $status=isset($_GET['status'])?sanitize_key($_GET['status']):''; $search=isset($_GET['s'])?sanitize_text_field(wp_unslash($_GET['s'])):'';
 $status=in_array($status,array('pending','verified','rejected','closed'),true)?$status:'';
 $like='%'.$wpdb->esc_like($search).'%';
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
 $per_page=max(1,min(100,(int)(get_user_option('crmp_transactions_per_page')?:20)));$page=max(1,absint($_GET['paged']??1));$offset=($page-1)*$per_page;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE purged_at IS NULL AND ((%d=0 AND trashed_at IS NULL) OR (%d=1 AND trashed_at IS NOT NULL)) AND (%s='' OR status=%s) AND (%s='' OR transaction_ref LIKE %s OR CAST(order_id AS CHAR) LIKE %s)",$table,$trash?1:0,$trash?1:0,$status,$status,$search,$like,$like));
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 $page=min($page,max(1,(int)ceil($count/$per_page)));$offset=($page-1)*$per_page;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded private ledger page query.
 $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE purged_at IS NULL AND ((%d=0 AND trashed_at IS NULL) OR (%d=1 AND trashed_at IS NOT NULL)) AND (%s='' OR status=%s) AND (%s='' OR transaction_ref LIKE %s OR CAST(order_id AS CHAR) LIKE %s) ORDER BY id DESC LIMIT %d OFFSET %d",$table,$trash?1:0,$trash?1:0,$status,$status,$search,$like,$like,$per_page,$offset));
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 $bulk_ids=array();$bulk_versions=array();foreach($rows as $record){if(!in_array($record->status,array('verifying','rejecting'),true)){$bulk_ids[]=(int)$record->id;$bulk_versions[(int)$record->id]=(int)$record->proof_version;}}
 wp_localize_script('crmp-transactions-admin','crmpBulk',array('ids'=>$bulk_ids,'versions'=>$bulk_versions,'nonce'=>wp_create_nonce('crmp_bulk_'.implode(',',$bulk_ids))));
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Authoritative private ledger statistics.
 $stats=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) n,COALESCE(SUM(amount),0) total FROM %i WHERE purged_at IS NULL GROUP BY status",$table),ARRAY_A); $totals=array('pending'=>0,'verified'=>0,'rejected'=>0,'closed'=>0);$amount=0;$num=0; foreach($stats as $s){$totals[$s['status']]=(int)$s['n'];$num+=(int)$s['n'];if($s['status']==='verified')$amount=(float)$s['total'];}
 echo '<div class="wrap crmp-wrap"><h1>Manual Payment Transactions</h1><div class="crmp-stats"><div><strong>'.esc_html($num).'</strong>Total Transactions</div><div><strong>'.esc_html($totals['verified']).'</strong>Verified</div><div><strong>'.esc_html($totals['pending']).'</strong>Pending</div><div><strong>'.esc_html($totals['rejected']).'</strong>Rejected</div><div><strong>'.esc_html($totals['closed']).'</strong>Closed</div><div><strong>'.wp_kses_post(wc_price($amount,array('currency'=>'PKR'))).'</strong>Verified Amount</div></div>';
 echo '<p class="crmp-visibility-tabs"><a href="'.esc_url(admin_url('admin.php?page=crmp-transactions')).'">All transactions</a> | <a href="'.esc_url(admin_url('admin.php?page=crmp-transactions&trash=1')).'">Trash</a></p><div class="crmp-management-notice" role="status" aria-live="polite"></div>';
 if($trash){echo '<div class="crmp-record-actions"><button type="button" class="button crmp-record-action" data-operation="empty" data-id="0">Empty Trash</button><div class="crmp-record-confirm" hidden><p></p><button type="button" class="button button-primary crmp-record-confirm-yes">Yes, continue</button> <button type="button" class="button crmp-record-confirm-cancel">Cancel</button></div></div>';}
 echo '<form method="get" class="crmp-filters"><input type="hidden" name="page" value="crmp-transactions"><input type="hidden" name="trash" value="'.($trash?'1':'0').'"><input type="search" name="s" placeholder="Order or transaction ID" value="'.esc_attr($search).'"><select name="status"><option value="">All statuses</option>';foreach(array('pending','verified','rejected','closed') as $s) echo '<option value="'.esc_attr($s).'" '.selected($status,$s,false).'>'.esc_html(ucfirst($s)).'</option>';echo '</select><button class="button">Filter</button> <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin.php?page=crmp-transactions&crmp_export=1'),'crmp_export')).'">Export CSV</a> <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin.php?page=crmp-transactions&crmp_export=history'),'crmp_export')).'">Export Proof History</a></form>';
 echo '<div class="crmp-list-toolbar"><div class="crmp-bulk-controls">'.($trash?'<button type="button" class="button crmp-bulk-action" data-operation="bulk-restore" disabled>Restore selected</button> <button type="button" class="button crmp-bulk-action" data-operation="bulk-delete" disabled>Delete selected permanently</button>':'<button type="button" class="button crmp-bulk-action crmp-bulk-trash" data-operation="bulk-trash" disabled>Move selected to Trash</button> <button type="button" class="button crmp-bulk-action" data-operation="bulk-approve" disabled>Approve selected</button> <button type="button" class="button crmp-bulk-action" data-operation="bulk-reject" disabled>Reject selected pending</button>').' <span class="crmp-selected-count" aria-live="polite">0 selected</span><div class="crmp-bulk-confirm" hidden><p></p><label class="crmp-bulk-reason" hidden><span class="crmp-bulk-note-label">Reason for rejection (visible to customers)</span><textarea class="crmp-bulk-note" maxlength="2000" rows="3"></textarea><span class="description crmp-bulk-note-scope">The same note will be visible to customers on every successfully processed transaction. Skipped records will not receive this note.</span></label><button type="button" class="button button-primary crmp-bulk-yes">Yes, move to Trash</button> <button type="button" class="button crmp-bulk-cancel">Cancel</button></div></div>';
 crmp_list_navigation('top',$count,$per_page,$page,$trash,$status,$search);echo '</div>';
 echo '<div class="crmp-table-scroll"><table class="widefat striped crmp-table"><thead><tr><th class="check-column"><input type="checkbox" class="crmp-select-page" aria-label="Select all transactions on this page"></th><th>#</th><th>Order</th><th>Method</th><th>Amount</th><th>Transaction ID</th><th>Screenshot</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized read-only selection used to reopen one transaction after a review redirect.
 $open_id=absint($_GET['view']??0);
 foreach($rows as $r){$order=wc_get_order($r->order_id);$order_deleted=crmp_order_is_deleted($order);$expanded=$open_id===(int)$r->id;$method=crmp_method_label($r->method);
 echo '<tr><td class="check-column"><input type="checkbox" class="crmp-select-row" value="'.absint($r->id).'" aria-label="Select transaction '.absint($r->id).'"'.(in_array($r->status,array('verifying','rejecting'),true)?' disabled':'').'></td><td>'.absint($r->id).'</td><td>'.(!$order_deleted?'<a href="'.esc_url($order->get_edit_order_url()).'">#'.absint($r->order_id).'</a>':'Order Deleted (#'.absint($r->order_id).')').'</td><td>'.esc_html($method).'</td><td>'.wp_kses_post(wc_price($r->amount,array('currency'=>$r->currency))).'</td><td>'.esc_html($r->transaction_ref!==''?$r->transaction_ref:'—').'</td><td>';
 if($r->screenshot_id){echo '<a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=crmp_screenshot&id='.$r->id),'crmp_screenshot_'.$r->id)).'">View file</a>';}else echo '—';
 echo '</td><td><span class="crmp-status crmp-'.esc_attr($r->status).'">'.esc_html(crmp_status_label($r->status)).'</span>'.($r->reviewed_by?'<br><small>'.esc_html(crmp_reviewer_label($r->reviewed_by)).'</small>':'').'</td><td>'.esc_html($r->submitted_at).'</td><td><button type="button" class="button crmp-toggle-details" aria-expanded="'.($expanded?'true':'false').'" aria-controls="crmp-detail-'.absint($r->id).'">'.($expanded?'Hide Details':'View Details').'</button></td></tr><tr id="crmp-detail-'.absint($r->id).'" class="crmp-detail-row"'.($expanded?'':' hidden').'><td colspan="10"><div class="crmp-panel crmp-detail"><h2>Transaction #'.absint($r->id).'</h2><p><strong>Order:</strong> #'.absint($r->order_id).' | <strong>Method:</strong> '.esc_html($method).' | <strong>Amount:</strong> '.wp_kses_post(wc_price($r->amount,array('currency'=>$r->currency))).'</p><p><strong>Transaction ID:</strong> '.esc_html($r->transaction_ref!==''?$r->transaction_ref:'Not supplied').'</p><p><strong>Status:</strong> '.esc_html(crmp_status_label($r->status)).'</p>';if(!empty($r->trashed_at))echo '<p class="crmp-trash-label"><strong>In Trash.</strong> Payment status is preserved. Restore this transaction before reviewing it.</p>';if($r->screenshot_id)echo '<p><a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=crmp_screenshot&id='.$r->id),'crmp_screenshot_'.$r->id)).'">View submitted screenshot</a></p>';crmp_admin_review_details($r);crmp_render_proof_history($r);
 if(crmp_transaction_removed($r)){echo '<p class="description">This transaction is read-only while in Trash.</p>';}
 elseif($order_deleted){echo '<p class="crmp-order-deleted"><strong>Order deleted.</strong> This transaction is read-only. Its previous status and review details have been preserved.</p>';}
 elseif(($order->is_paid()||$order->get_date_paid())&&!crmp_review_recovery_available($r,$order)){echo '<p class="description">Order already paid. Further payment reviews are disabled.</p>';}
 elseif(in_array($r->status,array('pending','verifying','rejecting','rejected'),true)){echo '<form method="post" class="crmp-review-form" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="crmp_review"><input type="hidden" name="id" value="'.absint($r->id).'"><input type="hidden" name="proof_version" value="'.absint($r->proof_version).'"><input type="hidden" name="list_s" value="'.esc_attr($search).'"><input type="hidden" name="list_status" value="'.esc_attr($status).'"><input type="hidden" name="list_paged" value="'.absint($page).'">';wp_nonce_field('crmp_review_'.$r->id);echo '<p><label>Admin note (visible to the customer) <input type="text" name="note" maxlength="2000" class="regular-text" value="'.esc_attr($r->note??'').'"></label></p><button type="button" class="button button-primary crmp-review-decision" data-decision="verified"'.($r->status==='rejecting'?' disabled':'').'>'.($r->status==='verifying'?'Resume verification':'Mark as Paid (Verify)').'</button> <button type="button" class="button crmp-review-decision" data-decision="rejected"'.(in_array($r->status,array('rejected','verifying'),true)?' disabled':'').'>'.($r->status==='rejecting'?'Resume rejection':'Reject Payment').'</button><div class="crmp-inline-confirm" hidden><p class="crmp-confirm-message"></p><button type="button" class="button button-primary crmp-confirm-yes">Yes, continue</button> <button type="button" class="button crmp-confirm-cancel">Cancel</button></div>'.($r->status==='rejected'?'<p class="description">This rejected submission may be verified after checking the funds and confirming the order is still unpaid.</p>':'').'</form>';}
 echo '<div class="crmp-record-actions">';if($trash){echo '<button type="button" class="button crmp-record-action" data-operation="restore" data-id="'.absint($r->id).'">Restore</button> <button type="button" class="button crmp-record-action" data-operation="delete" data-id="'.absint($r->id).'">Delete permanently</button>';}else{echo '<button type="button" class="button crmp-record-action" data-operation="trash" data-id="'.absint($r->id).'" data-pending-review="'.(crmp_pending_protected($r)?'1':'0').'"'.(in_array($r->status,array('verifying','rejecting'),true)?' disabled':'').'>Move to Trash</button>';}echo '<div class="crmp-record-confirm" hidden><p></p><button type="button" class="button button-primary crmp-record-confirm-yes">Yes, continue</button> <button type="button" class="button crmp-record-confirm-cancel">Cancel</button></div></div></div></td></tr>'; }
 if(!$rows)echo '<tr><td colspan="10">No transactions found.</td></tr>'; echo '</tbody></table></div>';
 crmp_list_navigation('bottom',$count,$per_page,$page,$trash,$status,$search);
 echo '</div>';
}
add_action('admin_post_crmp_review','crmp_process_review');
add_action('wp_ajax_crmp_review','crmp_process_review');
function crmp_process_review(){
 if(!current_user_can('manage_woocommerce') || !function_exists('wc_get_order'))wp_die('Not allowed');
 crmp_require_post();$id=absint($_POST['id']??0);check_admin_referer('crmp_review_'.$id);
 $decision=sanitize_key(wp_unslash($_POST['decision']??''));
 $note=sanitize_textarea_field(wp_unslash($_POST['note']??''));
 $version=absint($_POST['proof_version']??0);
 $result=$version?crmp_review_transaction($id,$decision,$note,$version):new WP_Error('crmp_refresh','Refresh the transaction page before reviewing payment proof.');
 // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The helper accepts only whitelisted scalar list fields and sanitizes each value.
 $url=crmp_review_return_url($id,$_POST);
 if(is_wp_error($result)){
  $message=$result->get_error_message();
  if(wp_doing_ajax())wp_send_json_error(array('message'=>$message,'reloadUrl'=>$url),409);
  set_transient('crmp_review_notice_'.get_current_user_id(),$message,60);
  wp_safe_redirect($url);exit;
 }
 if(wp_doing_ajax())wp_send_json_success(array('redirect'=>$url));
 wp_safe_redirect($url);exit;
}
add_action('admin_post_crmp_screenshot',function(){
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 if(!current_user_can('manage_woocommerce'))wp_die('Not allowed');$id=absint($_GET['id']??0);check_admin_referer('crmp_screenshot_'.$id);global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',crmp_table(),$id));if(!$row || !empty($row->purged_at) )wp_die('File not found');
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live private proof revision lookup; authorize access against the current transaction before serving evidence.
 $evidence_id=(int)$row->screenshot_id;$revision_id=absint($_GET['revision']??0);if($revision_id){$revision=$wpdb->get_row($wpdb->prepare('SELECT screenshot_id FROM %i WHERE id=%d AND transaction_id=%d',$wpdb->prefix.'crmp_proof_revisions',$revision_id,$id));if(!$revision||!$revision->screenshot_id)wp_die('File not found');$evidence_id=(int)$revision->screenshot_id;}
 $path=get_attached_file($evidence_id);if(!$path||!is_file($path))wp_die('File unavailable');if(!crmp_evidence_path_allowed($path))wp_die('Invalid evidence path');$payload=crmp_read_evidence($path);if(is_wp_error($payload))wp_die('File unavailable');$mime=$payload['mime'];if(!in_array($mime,array('image/jpeg','image/png','image/webp'),true))wp_die('Invalid file');nocache_headers();header('Content-Type: '.$mime);header('Content-Disposition: inline; filename="payment-evidence-'.absint($id).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; sandbox");
 // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated binary image response; getimagesizefromstring validates the MIME and nosniff/CSP headers prevent active content.
 echo $payload['bytes'];exit;
});
add_action('wp_enqueue_scripts',static function(){
 if((function_exists('is_checkout')&&is_checkout())||(function_exists('is_account_page')&&is_account_page())){wp_enqueue_style('crmp-checkout',CRMP_URL.'assets/checkout.css',array(),crmp_asset_version('assets/checkout.css'));crmp_customer_branding();}
});
function crmp_render_payment_evidence($order){
 if(!$order instanceof WC_Order || $order->get_payment_method()!=='crmp_manual' || !crmp_can_view_order($order))return;
 $config=get_option('crmp_method_config',array());$slug=$order->get_meta('_crmp_selected_method');$v=$config[$slug]??array();
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 global $wpdb;$table=crmp_table();$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE order_id=%d AND trashed_at IS NULL AND purged_at IS NULL ORDER BY id DESC",$table,$order->get_id()));
 $latest=$rows?$rows[0]:null;$recorded_paid=crmp_paid_transaction_id($order->get_id());
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Retained decision metadata guides resubmission without exposing hidden proof, references or notes.
 $last_record=$wpdb->get_row($wpdb->prepare('SELECT status,trashed_at,purged_at FROM %i WHERE order_id=%d ORDER BY id DESC LIMIT 1',$table,$order->get_id()));
 $removed_status='';$other_pending=false;foreach($rows as $visible_record){if(in_array($visible_record->status,array('pending','verifying','rejecting'),true)){$other_pending=true;break;}}
 if($last_record&&crmp_transaction_removed($last_record)&&in_array($last_record->status,array('pending','rejected'),true)){$removed_status=$last_record->status;$latest=null;}

 if($order->is_paid()||$order->get_date_paid()||$recorded_paid){$latest=null;foreach($rows as $candidate){if($candidate->status==='verified'){$latest=$candidate;break;}}}
 crmp_private_page_nocache();
 wp_enqueue_style('crmp-checkout',CRMP_URL.'assets/checkout.css',array(),crmp_asset_version('assets/checkout.css'));
 wp_enqueue_script('crmp-checkout',CRMP_URL.'assets/checkout.js',array(),crmp_asset_version('assets/checkout.js'),true);wp_localize_script('crmp-checkout','crmpCheckout',array('maxUploadBytes'=>crmp_upload_limit(),'proofPolicy'=>crmp_proof_policy('order')));
 $state=$latest?(in_array($latest->status,array('verifying','rejecting'),true)?'pending':$latest->status):'awaiting';
 if($removed_status!=='')$state=$removed_status;
 if($order->is_paid()||$order->get_date_paid()||$recorded_paid){$state='verified';$removed_status='';}
 $head=array('verified'=>'Payment Verified','pending'=>'Payment Under Review','rejected'=>'Payment Proof Resubmission','awaiting'=>'Complete Your Manual Payment');
 $desc=array('verified'=>'Your payment has been confirmed. Thank you!','pending'=>'We received your payment proof. Our team is reviewing it.','rejected'=>'Your latest payment proof was rejected. Please submit new payment proof. If you have already paid, do not pay again; contact the store if you need help.','awaiting'=>'Transfer the amount using the details below, then submit your payment proof. If you have already paid, submit proof instead of paying again.');
 if($state==='verified'&&!$order->is_paid()){$head['verified']='Payment recorded';$desc['verified']='This order has a recorded payment, but its current order status is '.wc_get_order_status_name($order->get_status()).'. Do not pay again. Contact the store to confirm the next step.';}
 if($removed_status!==''&&$other_pending){$desc[$state]='Please contact the store for the next step. If you have already paid, do not pay again.';}
 if($removed_status==='pending'&&!$other_pending){$head['pending']='Pending Payment — Submit Proof';$desc['pending']='Your payment is pending. Please submit your payment proof. If you have already paid, do not pay again. Contact the store if you need help.';}
 echo '<section id="crmp-payment-proof" class="crmp-payment-panel crmp-state-'.esc_attr($state).'" aria-label="Manual payment status">'.wp_kses_post(crmp_customer_brand_header($state)).'<div class="crmp-payment-head"><span class="crmp-payment-icon" aria-hidden="true">'.($state==='verified'?'✓':($state==='rejected'?'!':($state==='pending'?'◷':'↗'))).'</span><div><span class="crmp-payment-eyebrow">MANUAL PAYMENT · '.esc_html(crmp_method_label($slug)).'</span><h2>'.esc_html($head[$state]??$head['awaiting']).'</h2><p>'.esc_html($desc[$state]??$desc['awaiting']).'</p></div></div>';
 echo '<div class="crmp-payment-facts"><div><span>Order</span><strong>#'.esc_html($order->get_order_number()).'</strong></div><div><span>Amount</span><strong>'.wp_kses_post(wc_price($order->get_total(),array('currency'=>$order->get_currency()))).'</strong></div><div><span>Payment method</span><strong>'.esc_html(crmp_method_label($slug)).'</strong></div></div>';
 echo '<div class="crmp-customer-summary"><span>Customer</span><strong>'.esc_html($order->get_formatted_billing_full_name()?:'Customer').'</strong></div>';
 if($latest){
  echo '<div class="crmp-payment-result"><div><span>Status</span><strong>'.esc_html(crmp_status_label($latest->status)).'</strong></div>';
  if($latest->transaction_ref!=='')echo '<div><span>Transaction ID</span><strong>'.esc_html($latest->transaction_ref).'</strong></div>';
  echo '<div><span>Submitted</span><strong>'.esc_html($latest->submitted_at).'</strong></div>';
  if($latest->reviewed_at)echo '<div><span>Reviewed</span><strong>'.esc_html($latest->reviewed_at).'</strong></div>';
  echo '</div>';
  if(($latest->status==='rejected'||$latest->status==='verified')&&!empty($latest->note))echo '<div class="crmp-reviewer-note"><strong>'.($latest->status==='rejected'?'Reason for rejection':'Reviewer note').'</strong><p>'.nl2br(esc_html($latest->note)).'</p></div>';
 }
 if($state==='verified'||($state==='pending'&&$removed_status===''&&(!$latest||$latest->status!=='pending'))||($removed_status!==''&&$other_pending)){echo '</section>';return;}
 if(!in_array($order->get_status(),array('on-hold','pending','failed'),true)){echo '</section>';return;}
 if((!$slug||empty($v['account']))&&!crmp_enabled_methods()){echo '<p>Contact the store for payment instructions.</p></section>';return;}
 $replacing=$latest&&in_array($latest->status,array('pending','rejected'),true)&&!crmp_transaction_removed($latest);
 $limit=crmp_proof_update_limit();$remaining=$replacing?max(0,$limit-(int)($latest->replacement_count??0)):$limit;
 if($replacing){
  echo '<div class="crmp-pending-summary" role="status"><strong>'.($state==='pending'?'Payment proof submitted — Awaiting admin review':'You may update your payment proof.').'</strong><p>If you need to correct your payment details or screenshot, you can submit <strong>'.absint($remaining).'</strong> more '.($remaining===1?'update':'updates').'.</p>';
  if(!$remaining){echo '<p>No proof updates remaining. Your submitted proof is awaiting admin review. Do not make another payment.</p></div></section>';return;}
  echo '<p>Need to make a correction? Use the button below to update your proof.</p><button type="button" class="button crmp-proof-toggle" aria-expanded="false" aria-controls="crmp-proof-editor-'.absint($order->get_id()).'">Update payment proof</button>';
  echo '</div>';
 }
 if(!$replacing)echo '<button type="button" class="button crmp-proof-toggle" aria-expanded="false" aria-controls="crmp-proof-editor-'.absint($order->get_id()).'">Submit payment proof</button>';
 echo '<form id="crmp-proof-editor-'.absint($order->get_id()).'" hidden class="crmp-payment-proof crmp-proof-form" data-proof-policy="'.esc_attr(wp_json_encode(crmp_proof_policy('order'))).'" data-ajax-url="'.esc_url(admin_url('admin-ajax.php')).'" method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="crmp_submit_evidence"><input type="hidden" name="order_id" value="'.absint($order->get_id()).'"><input type="hidden" name="order_key" value="'.esc_attr($order->get_order_key()).'">';wp_nonce_field('crmp_evidence_'.$order->get_id());if($replacing)echo '<input type="hidden" name="replace_id" value="'.absint($latest->id).'"><input type="hidden" name="proof_version" value="'.absint($latest->proof_version).'">';
 crmp_customer_proof_fields($order,$latest,$replacing,$slug);
 echo '<button type="button" class="button crmp-proof-cancel" aria-controls="crmp-proof-editor-'.absint($order->get_id()).'">Cancel update</button>';
 echo '</form><p class="crmp-proof-footer">Secure payment verification</p></section>';
}
add_action('woocommerce_thankyou_crmp_manual',function($order_id){crmp_render_payment_evidence(wc_get_order($order_id));});
add_action('woocommerce_order_details_after_order_table',function($order){
 // WooCommerce also renders the order-details hook on Thank You pages: avoid a duplicate panel.
 if(function_exists('is_order_received_page')&&is_order_received_page())return;
 crmp_render_payment_evidence($order);
},8);
add_action('admin_post_nopriv_crmp_submit_evidence','crmp_handle_evidence');add_action('admin_post_crmp_submit_evidence','crmp_handle_evidence');
function crmp_proof_result($success,$message,$code=400){
 if(wp_doing_ajax()){
  if($success)wp_send_json_success(array('message'=>$message));
  wp_send_json_error(array('message'=>$message),$code);
 }
 if(!$success){if(function_exists('WC')&&WC()->session)wc_add_notice($message,'error');wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;}
}
add_action('wp_ajax_crmp_submit_evidence','crmp_handle_evidence');
add_action('wp_ajax_nopriv_crmp_submit_evidence','crmp_handle_evidence');
function crmp_handle_evidence(){
 crmp_require_post();if(!function_exists('wc_get_order'))crmp_proof_result(false,'Payments unavailable.',503);
 $id=absint($_POST['order_id']??0);$order=wc_get_order($id);
 if(!$order||$order->get_payment_method()!=='crmp_manual')crmp_proof_result(false,'Invalid order.',404);
 if(!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce']??'')),'crmp_evidence_'.$id))crmp_proof_result(false,'Your form has expired. Refresh this page and try again.',403);
 $key=sanitize_text_field(wp_unslash($_POST['order_key']??''));
 if($order->get_order_key()==='' || $key==='' || !hash_equals($order->get_order_key(),$key) || ($order->get_customer_id() && (int)$order->get_customer_id()!==get_current_user_id() && !current_user_can('manage_woocommerce')))crmp_proof_result(false,'Invalid order authorization.',403);
 crmp_acquire_order_lock($id);$order=wc_get_order($id);
 if(crmp_order_is_deleted($order))crmp_proof_result(false,'Order unavailable.',404);
 if(crmp_paid_transaction_id($id))crmp_proof_result(false,'A verified payment is already recorded. Do not pay again; contact the store.',409);
 $back=$order->get_checkout_order_received_url();
 $ref=crmp_customer_reference($_POST,'order');
 // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Helper whitelists and validates method, date, note and confirmation after order authorization.
 $details=crmp_customer_proof_details($order,$_POST,true);if(is_wp_error($details))crmp_proof_result(false,$details->get_error_message());
 // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw file array is validated by crmp_validate_upload before storage.
 $file=isset($_FILES['crmp_screenshot'])&&!empty($_FILES['crmp_screenshot']['name'])?$_FILES['crmp_screenshot']:null;
 $choice=crmp_validate_proof_choice($ref,(bool)$file,'order');if(is_wp_error($choice))crmp_proof_result(false,$choice->get_error_message());
 if($file&&!crmp_validate_upload($file))crmp_proof_result(false,'Invalid screenshot. Use JPG, PNG or WebP within the configured size limit.');
 if($order->get_date_paid() || !in_array($order->get_status(),array('on-hold','pending','failed'),true))crmp_proof_result(false,'This order cannot accept payment proof.');
 $replace_id=absint($_POST['replace_id']??0);
 if($replace_id){$result=crmp_replace_pending_proof($order,$replace_id,absint($_POST['proof_version']??0),$ref,$file,$details);if(is_wp_error($result))crmp_proof_result(false,$result->get_error_message(),409);if(wp_doing_ajax())crmp_proof_result(true,'Payment proof replaced successfully. Pending verification.');wp_safe_redirect($back);exit;}
 global $wpdb;$table=crmp_table();
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live private proof revision lookup; authorize access against the current transaction before serving evidence.
 $previous=$wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE order_id=%d AND trashed_at IS NULL AND purged_at IS NULL ORDER BY id DESC LIMIT 1",$table,$id));
 if($previous&&$previous->status==='rejected')crmp_proof_result(false,'Refresh this page to submit replacement proof for the existing transaction.',409);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE order_id=%d AND (status='verified' OR (trashed_at IS NULL AND purged_at IS NULL AND status IN ('pending','verifying','rejecting'))) LIMIT 1",$table,$id));
 if($existing){if(wp_doing_ajax())crmp_proof_result(true,'Payment proof has already been submitted.');wp_safe_redirect($back);exit;}
 $attachment=0;
 if($file){$attachment=crmp_store_upload($file,$id);if(is_wp_error($attachment))crmp_proof_result(false,$attachment->get_error_message(),500);}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 $ok=$wpdb->insert($table,array('order_id'=>$id,'method'=>$details['method'],'payment_date'=>$details['payment_date'],'customer_note'=>$details['customer_note'],'proof_context'=>$details['proof_context'],'field_policy'=>$details['field_policy'],'customer_confirmed'=>$details['customer_confirmed'],'amount'=>$order->get_total(),'currency'=>$order->get_currency(),'transaction_ref'=>$ref,'screenshot_id'=>$attachment,'status'=>'pending','submitted_at'=>current_time('mysql')),null);
 if(!$ok){if($attachment)wp_delete_attachment($attachment,true);crmp_proof_result(false,'Unable to save payment proof. Please contact the store.',500);}
 $crmp_submission_id=(int)$wpdb->insert_id;crmp_customer_save_method($order,$details['method']);do_action('crmp_proof_submitted',$crmp_submission_id);
 $order->add_order_note('Manual payment proof submitted; pending admin verification. Transaction #'.$crmp_submission_id);
 if(wp_doing_ajax())crmp_proof_result(true,'Payment proof submitted successfully. Pending verification.');
 wp_safe_redirect($back);exit;
}

add_action('woocommerce_rest_checkout_process_payment_with_context',function($context,$result){
 if($context->payment_method!=='crmp_manual')return;
 foreach(array('crmp_selected_method','crmp_transaction_ref','crmp_screenshot_data','crmp_payment_date','crmp_customer_note','crmp_proof_confirm') as $field){if(isset($context->payment_data[$field]) && !is_string($context->payment_data[$field]))throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('crmp_invalid_input','Invalid payment data.',400);}
 $slug=sanitize_key($context->payment_data['crmp_selected_method']??'');
 if(!isset(crmp_enabled_methods()[$slug]))throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('crmp_invalid_method','Please select a valid manual payment method.',400);
 $details=crmp_checkout_proof_details(wp_slash($context->payment_data));if(is_wp_error($details))throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('crmp_invalid_proof',esc_html($details->get_error_message()),400);
 $payment_data=$context->payment_data;foreach(array('crmp_transaction_ref','crmp_payment_date','crmp_customer_note','crmp_proof_confirm') as $field){$payment_data[$field]=wp_slash($payment_data[$field]??'');$_POST[$field]=$payment_data[$field];}$context->set_payment_data($payment_data);
 $_POST['crmp_selected_method']=$slug;$_POST['crmp_screenshot_data']=$context->payment_data['crmp_screenshot_data']??'';
},10,2);

/** Store required checkout evidence only after WooCommerce creates an order. */
function crmp_capture_checkout_evidence($order){
 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from the authenticated WooCommerce gateway process_payment callback.
 $ref=crmp_customer_reference($_POST);
 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates the checkout nonce; image content is validated below.
 $data=isset($_POST['crmp_screenshot_data'])?sanitize_text_field(wp_unslash($_POST['crmp_screenshot_data'])):'';
 // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authenticated WooCommerce callback; shared helper validates method, date and note.
 $details=crmp_checkout_proof_details($_POST,$order,$_FILES);if(is_wp_error($details))return $details;
 // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- WooCommerce verifies checkout authorization and nonce before this callback. Multipart metadata and upload origin are validated by crmp_checkout_proof_details/crmp_validate_upload; storage verifies image bytes.
 $upload=isset($_FILES['crmp_screenshot'])&&is_array($_FILES['crmp_screenshot'])&&((int)($_FILES['crmp_screenshot']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE||!empty($_FILES['crmp_screenshot']['name']))?$_FILES['crmp_screenshot']:null;
 $order_id=$order->get_id();crmp_acquire_order_lock($order_id);$order=wc_get_order($order_id);
 if(crmp_order_is_deleted($order)||$order->is_paid()||$order->get_date_paid()||!in_array($order->get_status(),array('pending','on-hold','failed'),true))return new WP_Error('crmp_order_paid','This order cannot accept further payment proof.');
 if($data && !crmp_validate_data_uri($data))return new WP_Error('crmp_bad_image','Invalid payment screenshot. Please use JPG, PNG or WebP within the size limit.');
 global $wpdb;$table=crmp_table();
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live private proof revision lookup; authorize access against the current transaction before serving evidence.
 $previous=$wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE order_id=%d AND trashed_at IS NULL AND purged_at IS NULL ORDER BY id DESC LIMIT 1",$table,$order_id));
 if($previous&&$previous->status==='rejected')return new WP_Error('crmp_existing_proof','Use the order page to replace proof for the existing transaction.');
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE order_id=%d AND (status='verified' OR (trashed_at IS NULL AND purged_at IS NULL AND status IN ('pending','verifying','rejecting'))) LIMIT 1",$table,$order->get_id()));
 if($existing||!empty($details['deferred']))return true;
 $attachment=0;
 if($data){$attachment=crmp_store_data_uri($data,$order->get_id());if(is_wp_error($attachment))return $attachment;}elseif($upload){$attachment=crmp_store_upload($upload,$order->get_id());if(is_wp_error($attachment))return $attachment;}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 $ok=$wpdb->insert($table,array('order_id'=>$order->get_id(),'method'=>$order->get_meta('_crmp_selected_method'),'amount'=>$order->get_total(),'currency'=>$order->get_currency(),'transaction_ref'=>$ref,'payment_date'=>$details['payment_date'],'customer_note'=>$details['customer_note'],'proof_context'=>$details['proof_context'],'field_policy'=>$details['field_policy'],'customer_confirmed'=>$details['customer_confirmed'],'screenshot_id'=>$attachment,'status'=>'pending','submitted_at'=>current_time('mysql')),null);
 if(!$ok){if($attachment)wp_delete_attachment($attachment,true);return new WP_Error('crmp_save','Could not save payment proof. Please contact the store.');}
 $crmp_submission_id=(int)$wpdb->insert_id;do_action('crmp_proof_submitted',$crmp_submission_id);
 $order->add_order_note('Payment proof submitted at checkout; pending manual verification. Transaction #'.$crmp_submission_id);
 return true;
}

// Keep private payment evidence out of WordPress attachment REST responses.
add_filter('rest_prepare_attachment',function($response,$post){
 if(crmp_is_private_evidence($post->ID) && !current_user_can('manage_woocommerce'))return new WP_Error('crmp_private_evidence','Private payment evidence is not available.',array('status'=>403));
 return $response;
},10,2);
