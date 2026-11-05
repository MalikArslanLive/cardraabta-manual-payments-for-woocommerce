<?php
/** Administrator-only transaction visibility and related-record inspection. */
if(!defined('ABSPATH'))exit;
function crmp_get_transaction($id){
 global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Authoritative custom ledger lookup.
 return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',crmp_table(),absint($id)));
}
function crmp_transaction_removed($row){return !empty($row->trashed_at)||!empty($row->purged_at);}
function crmp_paid_transaction_id($order_id){
 $order=wc_get_order($order_id);if(!$order)return 0;
 $saved=absint($order->get_meta('_crmp_verified_transaction_id'));if($saved)return $saved;
 global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Payment ownership includes trashed and purged receipt records.
 return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE order_id=%d AND status='verified' ORDER BY id ASC LIMIT 1",crmp_table(),$order_id));
}
function crmp_paid_source_html($row){
 $source=absint($row->closed_by_transaction_id??0);
 if(!$source&&$row->status!=='verified')$source=crmp_paid_transaction_id($row->order_id);
 if(!$source||$source===(int)$row->id)return '';
 $record=crmp_get_transaction($source);$label='Paid through Transaction #'.$source;
 if(!$record||!empty($record->purged_at))return '<p class="crmp-paid-source">'.esc_html($label.' — Permanently deleted').'</p>';
 if((int)$record->order_id!==(int)$row->order_id)return '';
 if(!empty($record->trashed_at))$label.=' — In Trash';
 return '<p class="crmp-paid-source">'.esc_html($label).' <button type="button" class="button-link crmp-view-related" data-transaction-id="'.absint($source).'" aria-expanded="false">'.esc_html(!empty($record->trashed_at)?'View in Trash':'View').'</button></p><div class="crmp-related-result" hidden></div>';
}
function crmp_render_related_transaction($row){
 echo '<section class="crmp-related-panel" tabindex="-1" aria-label="Transaction #'.absint($row->id).'"><h3>Transaction #'.absint($row->id).(!empty($row->trashed_at)?' — In Trash':'').'</h3><p><strong>Order:</strong> #'.absint($row->order_id).' | <strong>Method:</strong> '.esc_html(crmp_method_label($row->method)).' | <strong>Amount:</strong> '.wp_kses_post(wc_price($row->amount,array('currency'=>$row->currency))).'</p><p><strong>Status:</strong> '.esc_html(crmp_status_label($row->status)).'</p><p><strong>Transaction ID:</strong> '.esc_html($row->transaction_ref!==''?$row->transaction_ref:'Not supplied').'</p>';
 crmp_admin_review_details($row);
 if($row->screenshot_id)echo '<p><a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=crmp_screenshot&id='.$row->id),'crmp_screenshot_'.$row->id)).'">View submitted screenshot</a></p>';
 echo '<p class="description">Read-only preview. Viewing this record does not restore it or change the order.</p></section>';
}
function crmp_manage_transaction($id,$operation){
 if(!current_user_can('manage_woocommerce'))return new WP_Error('crmp_forbidden','Not allowed.');
 if(!in_array($operation,array('trash','restore','delete'),true))return new WP_Error('crmp_operation','Invalid operation.');
 $row=crmp_get_transaction($id);if(!$row)return new WP_Error('crmp_missing','Transaction not found.');
 crmp_acquire_order_lock($row->order_id);$row=crmp_get_transaction($id);if(!$row)return new WP_Error('crmp_missing','Transaction not found.');
 if(in_array($row->status,array('verifying','rejecting'),true))return new WP_Error('crmp_reserved','Finish reconciling this payment review before moving or deleting it.');
 if(in_array($operation,array('trash','delete'),true)&&crmp_pending_protected($row))return new WP_Error('crmp_pending_review','Payment review is pending. Verify or Reject this proof before moving or deleting it.');
 global $wpdb;$table=crmp_table();
 if($operation==='trash'){
  if(!empty($row->purged_at))return new WP_Error('crmp_deleted','Transaction permanently deleted.');
  if(!empty($row->trashed_at))return true;
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Visibility is separate from immutable payment status.
  $saved=$wpdb->update($table,array('trashed_at'=>current_time('mysql'),'trashed_by'=>get_current_user_id()),array('id'=>$id));
 }elseif($operation==='restore'){
  if(!empty($row->purged_at))return new WP_Error('crmp_deleted','Permanently deleted transactions cannot be restored.');
  if(empty($row->trashed_at))return true;
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restore visibility only; never run a payment action.
  $saved=$wpdb->update($table,array('trashed_at'=>null,'trashed_by'=>0),array('id'=>$id));
 }else{
  if(!empty($row->purged_at)){crmp_cleanup_purged_evidence($row);return true;}
  if(empty($row->trashed_at))return new WP_Error('crmp_not_trash','Move this transaction to Trash before deleting it.');
  // Preserve the receipt ID and reference so deletion cannot enable repeat approval or reference reuse.
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Purge proof and notes while retaining the minimal payment receipt.
  $saved=$wpdb->update($table,array('purged_at'=>current_time('mysql'),'note'=>'','customer_note'=>'','payment_date'=>'','proof_context'=>'','field_policy'=>'','customer_confirmed'=>'','screenshot_path'=>''),array('id'=>$id));
 }
 if($saved===false)return new WP_Error('crmp_write','Unable to save this operation. Please retry.');
 if($operation==='delete'){crmp_cleanup_purged_evidence(crmp_get_transaction($id));}
 if($operation==='restore'){crmp_close_pending_submissions($row->order_id);}
 return true;
}
function crmp_cleanup_purged_evidence($row){
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 if(!$row||empty($row->purged_at))return;crmp_email_purge_transaction($row->id);global $wpdb;$revisions=$wpdb->prefix.'crmp_proof_revisions';
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 $history_ids=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT screenshot_id FROM %i WHERE transaction_id=%d AND screenshot_id>0',$revisions,$row->id));
 if($wpdb->last_error!=='')return;
 $ids=array_unique(array_merge(array((int)$row->screenshot_id),array_map('intval',$history_ids)));
 $failed=false;
 foreach($ids as $attachment){if(!$attachment)continue;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live reference checks protect screenshots still used by retained transactions.
  $shared=$wpdb->get_var($wpdb->prepare(
   'SELECT EXISTS(SELECT 1 FROM %i WHERE screenshot_id=%d AND purged_at IS NULL)
    OR EXISTS(SELECT 1 FROM %i r INNER JOIN %i t ON t.id=r.transaction_id WHERE r.screenshot_id=%d AND t.purged_at IS NULL)',
   crmp_table(),$attachment,$revisions,crmp_table(),$attachment
  ));
  if($shared===null){$failed=true;continue;}
  if(!$shared&&get_post($attachment)&&!wp_delete_attachment($attachment,true)){$failed=true;continue;}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
  $wpdb->update($revisions,array('screenshot_id'=>0,'transaction_ref'=>''),array('transaction_id'=>$row->id,'screenshot_id'=>$attachment));
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
  if($attachment===(int)$row->screenshot_id)$wpdb->update(crmp_table(),array('screenshot_id'=>0),array('id'=>$row->id));
 }
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 if(!$failed)$wpdb->delete($revisions,array('transaction_id'=>$row->id));
}
function crmp_retry_purged_evidence(){
 if(!current_user_can('manage_woocommerce'))return;global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded retry of private-evidence cleanup.
 $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE purged_at IS NOT NULL AND (screenshot_id>0 OR id IN (SELECT transaction_id FROM %i)) LIMIT %d',crmp_table(),$wpdb->prefix.'crmp_proof_revisions',25));
 foreach($rows as $row){if(crmp_acquire_order_lock($row->order_id,false))crmp_cleanup_purged_evidence(crmp_get_transaction($row->id));}
}
function crmp_related_transaction_payload($id){
 if(!current_user_can('manage_woocommerce'))return new WP_Error('crmp_forbidden','Not allowed.');
 $row=crmp_get_transaction($id);if(!$row||!empty($row->purged_at))return new WP_Error('crmp_deleted','This transaction was permanently deleted. Its payment reference remains preserved.');
 ob_start();crmp_render_related_transaction($row);return array('html'=>ob_get_clean());
}
function crmp_transaction_ajax_authorize(){
 if(!current_user_can('manage_woocommerce'))wp_send_json_error(array('message'=>'Not allowed.'),403);
 crmp_require_post();check_ajax_referer('crmp_transactions','nonce');if(!function_exists('wc_get_order'))wp_send_json_error(array('message'=>'Activate WooCommerce before managing transactions.'),503);
}
add_action('wp_ajax_crmp_related_transaction',static function(){
 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- crmp_transaction_ajax_authorize checks manager capability, POST and nonce.
 crmp_transaction_ajax_authorize();$payload=crmp_related_transaction_payload(absint($_POST['id']??0));
 if(is_wp_error($payload))wp_send_json_error(array('message'=>$payload->get_error_message()),404);
 wp_send_json_success($payload);
});
add_action('wp_ajax_crmp_manage_transactions',static function(){
 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- crmp_transaction_ajax_authorize checks manager capability, POST and nonce.
 crmp_transaction_ajax_authorize();$operation=sanitize_key(wp_unslash($_POST['operation']??''));
 if(in_array($operation,array('bulk-trash','bulk-restore','bulk-delete','bulk-reject','bulk-approve'),true)){
  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Manager POST nonce checked above; the second nonce binds selection to rendered page IDs.
  $page_ids=isset($_POST['page_ids'])&&is_array($_POST['page_ids'])?array_values(array_unique(array_map('absint',$_POST['page_ids']))):array();
  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Page-bound nonce verification follows immediately.
  $ids=isset($_POST['ids'])&&is_array($_POST['ids'])?array_values(array_unique(array_map('absint',$_POST['ids']))):array();
  if(!$ids||count($page_ids)>100||array_diff($ids,$page_ids)||!check_ajax_referer('crmp_bulk_'.implode(',',$page_ids),'bulk_nonce',false))wp_send_json_error(array('message'=>'Invalid selection. Refresh the transaction page.'),403);
  $note=isset($_POST['note'])&&is_scalar($_POST['note'])?crmp_text_limit(sanitize_textarea_field(wp_unslash((string)$_POST['note'])),2000):'';
  $versions=isset($_POST['versions'])&&is_array($_POST['versions'])?array_map('absint',$_POST['versions']):array();
  $result=crmp_bulk_manage($ids,substr($operation,5),$note,$versions);
  if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message()),400);
  wp_send_json_success($result);
 }
 if($operation==='empty'){
  global $wpdb;$cursor=absint($_POST['after_id']??0);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
  $ids=$wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE trashed_at IS NOT NULL AND purged_at IS NULL AND id>%d ORDER BY id LIMIT %d',crmp_table(),$cursor,50));
  $result=crmp_bulk_manage($ids,'delete');
  $result['more']=count($ids)===50;$result['after_id']=$ids?absint(end($ids)):$cursor;
  wp_send_json_success($result);
 }
 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authorized and nonce-checked at the start of this callback.
 $result=crmp_manage_transaction(absint($_POST['id']??0),$operation);
 if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message()),409);
 wp_send_json_success(array('processed'=>1,'more'=>false));
});
add_action('admin_init','crmp_retry_purged_evidence');
