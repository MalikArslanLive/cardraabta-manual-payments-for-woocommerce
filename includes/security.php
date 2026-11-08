<?php
/** Shared authorization, locking and encrypted evidence helpers. */
if (!defined('ABSPATH')) { exit; }
function crmp_scalar($value) { return is_scalar($value) ? sanitize_text_field(wp_unslash((string)$value)) : ''; }
/** Character-safe limits preserve Urdu and other UTF-8 notes/references. */
function crmp_text_limit($text,$limit){
 $text=wp_check_invalid_utf8((string)$text,true);$limit=max(0,min(20000,(int)$limit));
 if(function_exists('mb_substr'))return mb_substr($text,0,$limit,'UTF-8');
 return preg_match('/\A.{0,'.$limit.'}/us',$text,$match)?$match[0]:'';
}
/** Respectful page caches must never store an authorized private proof page. */
function crmp_private_page_nocache(){
 // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache interoperability constant; renaming it would disable privacy protection.
 if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
 if(!headers_sent()){nocache_headers();header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');}
}
add_filter('nocache_headers',static function($headers){if(defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE)$headers['Cache-Control']='private, no-store, no-cache, must-revalidate, max-age=0';return $headers;});
add_action('template_redirect',static function(){
 if(!function_exists('is_checkout'))return;
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence only enables no-cache protection; no authorization or state change uses this input.
 if(is_checkout()||is_account_page()||isset($_GET['key']))crmp_private_page_nocache();
},0);
function crmp_upload_limit() { return max(1,min(8,(int)get_option('crmp_max_upload_mb',5)))*1024*1024; }
function crmp_require_post() {
 if (sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']??''))!=='POST') { wp_die('POST required.', '', array('response'=>405)); }
}
function crmp_can_view_order($order) {
 if (current_user_can('manage_woocommerce')) { return true; }
 if ($order->get_customer_id()) { return get_current_user_id()===(int)$order->get_customer_id(); }
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display authorization uses the guest order key; no state is changed.
 $key=sanitize_text_field(wp_unslash($_GET['key']??''));
 return $key!=='' && hash_equals($order->get_order_key(),$key);
}
function crmp_reviewable_order($order,$row) {
 return $order && $order->get_payment_method()==='crmp_manual' && !$order->is_paid()
  && !$order->get_date_paid() && in_array($order->get_status(),array('pending','on-hold','failed'),true)
  && $order->get_currency()===$row->currency
  && wc_format_decimal($order->get_total(),2)===wc_format_decimal($row->amount,2)
  && $order->get_meta('_crmp_selected_method')===$row->method;
}
/** Connection-scoped locks serialize proof and review requests for each order. Fail closed. */
function crmp_acquire_order_lock($id,$fail_closed=true) {
 global $wpdb;return crmp_acquire_named_lock('crmp_'.md5($wpdb->prefix).'_'.absint($id),$fail_closed);
}
function crmp_acquire_reference_lock($method,$reference) {
 // Serialize approvals for the receiving method so database collation-equivalent references also share a lock.
 global $wpdb;crmp_acquire_named_lock('crmp_ref_'.hash('sha256',$wpdb->prefix.'|'.$method));
}
function crmp_acquire_named_lock($name,$fail_closed=true) {
 global $wpdb; static $held=array();
 $name=substr($name,0,64);
 if(isset($held[$name])){
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A reconnect or external release must invalidate the request-local lock cache.
  if((string)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)=CONNECTION_ID()',$name))==='1')return true;
  unset($held[$name]);
 }
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$name))!=='1') {
  if(!$fail_closed)return false;
  wp_die('Another payment request is in progress. Please try again.', '', array('response'=>409));
 }
 $held[$name]=true;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private custom ledger/connection locks have no WordPress object API; uncached state is required for payment integrity.
 register_shutdown_function(static function()use($name){global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));});
 return true;
}
/** Plugin-owned uploads folder for all encrypted evidence. */
function crmp_evidence_directory_name(){return 'cardraabta-manual-payments-for-woocommerce';}
/** Confine evidence to the plugin-owned directory under runtime uploads. */
function crmp_evidence_path_allowed($path){
 $real=is_string($path)?realpath($path):false;
 if(!$real)return false;
 $uploads=wp_upload_dir();
 if(!empty($uploads['error']))return false;
 $root=realpath(trailingslashit($uploads['basedir']).crmp_evidence_directory_name());
 return $root && strpos($real,$root.DIRECTORY_SEPARATOR)===0;
}
/** Stable random key. Site backups must include options and private evidence files. */
function crmp_evidence_key() {
 $key=get_option('crmp_evidence_key','');
 if(!is_string($key) || strlen($key)!==64){
  $candidate=bin2hex(random_bytes(32));
  add_option('crmp_evidence_key',$candidate,'',false);
  $key=get_option('crmp_evidence_key','');
 }
 if(!is_string($key) || !ctype_xdigit($key) || strlen($key)!==64)throw new RuntimeException('Private evidence key is unavailable.');
 return hex2bin($key);
}
function crmp_read_evidence($path) {
 if(!is_string($path) || !is_file($path) || filesize($path)>12*1024*1024)return new WP_Error('crmp_size','Invalid evidence size.');
 if(!crmp_evidence_path_allowed($path) || strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='crmp')return new WP_Error('crmp_format','Invalid evidence.');
 if(!function_exists('openssl_decrypt'))return new WP_Error('crmp_crypto','OpenSSL unavailable.');
 $stored=file_get_contents($path);
 if(!is_string($stored) || substr($stored,0,6)!=="CRMP1\n")return new WP_Error('crmp_format','Invalid evidence.');
 $raw=base64_decode(substr($stored,6),true);
 if($raw===false || strlen($raw)<29)return new WP_Error('crmp_format','Invalid evidence.');
 try{$key=crmp_evidence_key();}catch(Throwable $error){return new WP_Error('crmp_crypto','Private evidence key is unavailable. Restore the original key from a trusted backup.');}
 $bytes=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'crmp-evidence-v1');
 if(!is_string($bytes))return new WP_Error('crmp_decrypt','Evidence authentication failed.');
 $info=@getimagesizefromstring($bytes);
 if(!$info || !in_array($info['mime'],array('image/jpeg','image/png','image/webp'),true))return new WP_Error('crmp_image','Invalid evidence.');
 return array('bytes'=>$bytes,'mime'=>$info['mime']);
}
/** Standard Media Library permissions must not bypass evidence authorization. */
function crmp_is_private_evidence($id){
 $post=get_post($id);
 return $post && $post->post_type==='attachment' && get_post_meta($id,'_crmp_private_evidence',true);
}
function crmp_evidence_meta_caps($caps,$cap,$user_id,$args){
 if(in_array($cap,array('read_post','edit_post','delete_post'),true) && !empty($args[0]) && crmp_is_private_evidence($args[0]) && !user_can($user_id,'manage_woocommerce'))return array('do_not_allow');
 return $caps;
}
add_filter('map_meta_cap','crmp_evidence_meta_caps',10,4);
add_filter('wp_get_attachment_url',static function($url,$id){return crmp_is_private_evidence($id)?'':$url;},10,2);

/** Validate the immutable ledger-to-order relationship independently of payment state. */
function crmp_order_matches_record($order,$row) {
 return $order && $order->get_payment_method()==='crmp_manual'
  && $order->get_currency()===$row->currency
  && wc_format_decimal($order->get_total(),2)===wc_format_decimal($row->amount,2)
  && $order->get_meta('_crmp_selected_method')===$row->method;
}
/** Missing and trashed orders retain an immutable transaction history. */
function crmp_order_is_deleted($order) {
 return !$order || $order->get_status()==='trash';
}
/** Close other pending or rejected submissions when the order is paid; preserve the original decision. */
function crmp_close_pending_submissions($order_id) {
 if(!current_user_can('manage_woocommerce'))return new WP_Error('crmp_forbidden','Not allowed.');
 crmp_acquire_order_lock($order_id);$order=wc_get_order($order_id);
 if(crmp_order_is_deleted($order) || (!$order->is_paid() && !$order->get_date_paid()))return 0;
 global $wpdb;$table=crmp_table();$verified_id=0;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Match the authoritative ledger to the paid order under its lock.
 $verified=$wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE order_id=%d AND status='verified' ORDER BY id DESC",$table,$order_id));
 foreach($verified as $record){$reference=$record->transaction_ref!==''?$record->transaction_ref:'CRMP-'.$record->id;if(crmp_order_matches_record($order,$record)&&hash_equals($reference,(string)$order->get_transaction_id())){$verified_id=(int)$record->id;break;}}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Close siblings in either ID direction; preserve the previous decision and all review fields.
 $saved=$wpdb->query($wpdb->prepare("UPDATE %i SET closed_from_status=status,status='closed',closed_at=%s,closed_by_transaction_id=%d WHERE order_id=%d AND status IN ('pending','rejected') AND purged_at IS NULL",$table,current_time('mysql'),$verified_id,$order_id));
 return $saved===false?new WP_Error('crmp_close','Unable to close other submissions. Refresh Transactions to retry.'): $saved;
}
/** Repair existing paid/pending records in bounded batches, including after an interrupted close. */
function crmp_reconcile_paid_submissions($limit=100) {
 if(!current_user_can('manage_woocommerce') || !function_exists('wc_get_order'))return;
 global $wpdb;$cursor=absint(get_option('crmp_closure_cursor',0));$limit=max(1,min(100,(int)$limit));
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded authoritative ledger scan; WooCommerce checks each order before mutation.
 $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT order_id FROM %i WHERE status IN ('pending','rejected') AND purged_at IS NULL AND order_id>%d ORDER BY order_id ASC LIMIT %d",crmp_table(),$cursor,$limit));
 foreach($ids as $order_id){$order=wc_get_order($order_id);if(!crmp_order_is_deleted($order)&&($order->is_paid()||$order->get_date_paid())&&crmp_acquire_order_lock($order_id,false)){crmp_close_pending_submissions($order_id);}}
 update_option('crmp_closure_cursor',count($ids)===$limit?absint(end($ids)):0,false);
}
/** Offer paid-order recovery only for the original matching verification. */
function crmp_review_recovery_available($row,$order) {
 if(!$row || $row->status!=='verifying' || crmp_transaction_removed($row) || crmp_order_is_deleted($order) || !$order->is_paid() || !crmp_order_matches_record($order,$row))return false;
 $reference=$row->transaction_ref!==''?$row->transaction_ref:'CRMP-'.$row->id;
 return hash_equals($reference,(string)$order->get_transaction_id());
}
/** A durable reservation survives process termination between WooCommerce and ledger writes. */
function crmp_review_transaction($id,$decision,$note='',$expected_version=null) {
 if(!current_user_can('manage_woocommerce'))return new WP_Error('crmp_forbidden','Not allowed.');
 if(!in_array($decision,array('verified','rejected'),true))return new WP_Error('crmp_decision','Invalid decision.');
 global $wpdb;$table=crmp_table();
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Authoritative custom-ledger read.
 $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',$table,$id));
 if(!$row)return new WP_Error('crmp_missing','Transaction not found.');
 crmp_acquire_order_lock($row->order_id);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read again under the order lock.
 $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',$table,$id));
 if(!$row)return new WP_Error('crmp_missing','Transaction not found.');
 if($expected_version!==null&&(int)$row->proof_version!==(int)$expected_version)return new WP_Error('crmp_stale_proof','Payment proof changed. Refresh and review the latest submission before confirming.');
 $order=wc_get_order($row->order_id);
 if(crmp_transaction_removed($row))return new WP_Error('crmp_removed','This transaction is in Trash or permanently deleted and cannot be reviewed.');
 if(crmp_order_is_deleted($order))return new WP_Error('crmp_order_deleted','Order deleted. This transaction is read-only; its previous status has been preserved.');
 if(in_array($row->status,array('pending','rejected'),true)&&($order->is_paid()||$order->get_date_paid())){crmp_close_pending_submissions($row->order_id);return new WP_Error('crmp_order_paid','This order is already paid. Other submissions are closed and cannot be reviewed.');}
 if(crmp_paid_transaction_id($row->order_id)&&$row->status!=='verifying')return new WP_Error('crmp_order_paid','This order already has a verified payment. Trashing or deleting its receipt does not allow another approval.');
 $reserved=$decision==='verified'?'verifying':'rejecting';
 $retry_rejected=$row && $row->status==='rejected' && $decision==='verified';
 if(!$row || (!in_array($row->status,array('pending',$reserved),true) && !$retry_rejected))return new WP_Error('crmp_reviewed','This transaction cannot be changed. Rejected entries may only be verified after confirmation; verified entries are final.');
 if(!crmp_order_matches_record($order,$row))return new WP_Error('crmp_mismatch','Order details changed; reconcile before review.');
 $reference=$row->transaction_ref!==''?$row->transaction_ref:'CRMP-'.$id;
 // A previous request completed the order and terminated before finalizing the ledger.
 $recover=$decision==='verified' && crmp_review_recovery_available($row,$order);
 if(!$recover && !crmp_reviewable_order($order,$row))return new WP_Error('crmp_state','Order is not eligible for this decision.');
 if($decision==='verified'){
  crmp_acquire_reference_lock($row->method,$reference);
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reservations must participate in duplicate-reference checks.
  $duplicate=$row->transaction_ref!==''?$wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE status IN ('verified','verifying') AND method=%s AND transaction_ref=%s AND id<>%d LIMIT 1",$table,$row->method,$row->transaction_ref,$id)):null;
  if($duplicate)return new WP_Error('crmp_duplicate','This reference is already verified or reserved. Reconcile before proceeding.');
 }
 if($retry_rejected){$archived=crmp_archive_rejected_review($row);if(is_wp_error($archived))return $archived;}
 // Reserved retries retain their original author and note; a new approval of rejected proof records its own note.
 $review_note=($row->status==='pending'||$retry_rejected)?sanitize_textarea_field(crmp_text_limit(sanitize_textarea_field($note),2000)):(string)$row->note;
 if($row->status==='pending' || $retry_rejected){
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-swap durable review reservation.
  $saved=$wpdb->update($table,array('status'=>$reserved,'reviewed_at'=>current_time('mysql'),'reviewed_by'=>get_current_user_id(),'note'=>$review_note),array('id'=>$id,'status'=>$row->status));
  if($saved!==1)return new WP_Error('crmp_reserve','Unable to reserve this review. No payment decision was applied.');
 }
 try{
  if($decision==='verified' && !$recover){
   $order->payment_complete($reference);
   $order=wc_get_order($row->order_id);
   if(!$order || !$order->is_paid() || !hash_equals($reference,(string)$order->get_transaction_id()))return crmp_email_attention_error($id,'crmp_incomplete','WooCommerce did not confirm completion. The reserved review requires reconciliation.');
   $order->add_order_note('Manual payment verified by admin. Record #'.$id);
  }elseif($decision==='rejected'){
   $order->update_status('on-hold','Manual payment evidence rejected. Record #'.$id.'. '.$review_note);
   if($order->is_paid())return crmp_email_attention_error($id,'crmp_incomplete','Order state changed; the reserved review requires reconciliation.');
  }
 }catch(Throwable $error){return crmp_email_attention_error($id,'crmp_interrupted','Review was interrupted. The reserved decision is retained; reconcile and retry the same decision.');}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Finalize only after the WooCommerce transition is verified.
 $saved=$wpdb->update($table,array('status'=>$decision,'replacement_count'=>0),array('id'=>$id,'status'=>$reserved));
 if($saved!==1)return crmp_email_attention_error($id,'crmp_finalize','Order action completed but ledger finalization failed. Reconcile and retry the same decision.');
 do_action('crmp_payment_reviewed',$id,$decision);
 if($decision==='verified'){$order->update_meta_data('_crmp_verified_transaction_id',$id);$order->save();crmp_close_pending_submissions($row->order_id);}
 return true;
}
