<?php
/** Pending proof revisions. Caller authenticates the customer and order-specific nonce. */
if(!defined('ABSPATH'))exit;
function crmp_proof_update_limit(){return max(1,min(10,(int)get_option('crmp_proof_update_limit',3)));}
function crmp_pending_protected($row){return $row&&$row->status==='pending'&&!crmp_order_is_deleted(wc_get_order($row->order_id));}
function crmp_replace_pending_proof($order,$id,$version,$reference,$file=null,$details=array()){
 global $wpdb;
 crmp_acquire_order_lock($order->get_id());$order=wc_get_order($order->get_id());$row=crmp_get_transaction($id);
 if(!$row||!$order||(int)$row->order_id!==$order->get_id()||crmp_transaction_removed($row)||!in_array($row->status,array('pending','rejected'),true))return new WP_Error('crmp_not_pending','This proof is unavailable or already being reviewed. Refresh the page.');
 // Require the current visible record, including when an order has multiple submissions.
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 $latest_id=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE order_id=%d AND trashed_at IS NULL AND purged_at IS NULL ORDER BY id DESC LIMIT 1',crmp_table(),$order->get_id()));
 if($latest_id!==(int)$id)return new WP_Error('crmp_stale_record','A newer submission exists. Refresh to replace the current proof.');
 if((int)$row->proof_version!==$version)return new WP_Error('crmp_stale_proof','Proof changed in another request. Refresh before replacing it.');
 if(!crmp_reviewable_order($order,$row)||crmp_paid_transaction_id($order->get_id()))return new WP_Error('crmp_ineligible','This order cannot accept replacement proof. Contact the store.');
 $count=(int)($row->replacement_count??0);$limit=crmp_proof_update_limit();if($count>=$limit)return new WP_Error('crmp_update_limit','No proof updates remaining. Your payment proof has already been submitted. Do not pay again.');
 $details=array_merge(array('method'=>$row->method,'payment_date'=>'','customer_note'=>'','proof_context'=>'','field_policy'=>'','customer_confirmed'=>''),$details);
 if(!crmp_customer_method_allowed($details['method'],$row->method))return new WP_Error('crmp_method','Select an available payment method.');
 $reference=crmp_text_limit(trim(sanitize_text_field($reference)),191);
 if($reference===''&&!$file)return new WP_Error('crmp_empty','Enter a transaction ID or upload a screenshot.');
 if(get_transient('crmp_replace_'.$id))return new WP_Error('crmp_rate','Please wait a few seconds before replacing proof again.');
 $attachment=0;
 if($file){$attachment=crmp_store_upload($file,$order->get_id());if(is_wp_error($attachment))return $attachment;}
 // Archive before swapping so an interruption never destroys the previous proof.
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 $saved=$wpdb->insert($wpdb->prefix.'crmp_proof_revisions',array('transaction_id'=>$id,'proof_version'=>$version,'transaction_ref'=>$row->transaction_ref,'method'=>$row->method,'payment_date'=>$row->payment_date??'','customer_note'=>$row->customer_note??'','proof_context'=>$row->proof_context??'','field_policy'=>$row->field_policy??'','customer_confirmed'=>$row->customer_confirmed??'','screenshot_id'=>$row->screenshot_id,'submitted_at'=>$row->submitted_at,'replaced_at'=>current_time('mysql'),'review_status'=>$row->status,'review_note'=>$row->note,'reviewed_by'=>$row->reviewed_by,'reviewed_at'=>$row->reviewed_at));
 if(!$saved){if($attachment)wp_delete_attachment($attachment,true);return new WP_Error('crmp_revision','Unable to preserve previous proof. Please retry.');}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 $revision_id=(int)$wpdb->insert_id;
 // Each replacement is a complete new proof. Omitting a screenshot removes it from the current submission, but retains it in history.
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 $saved=$wpdb->update(crmp_table(),array('transaction_ref'=>$reference,'method'=>$details['method'],'payment_date'=>$details['payment_date'],'customer_note'=>$details['customer_note'],'proof_context'=>$details['proof_context'],'field_policy'=>$details['field_policy'],'customer_confirmed'=>$details['customer_confirmed'],'screenshot_id'=>$attachment,'submitted_at'=>current_time('mysql'),'proof_version'=>$version+1,'replacement_count'=>$count+1,'status'=>'pending','note'=>'','reviewed_by'=>0,'reviewed_at'=>null),array('id'=>$id,'status'=>$row->status,'proof_version'=>$version,'replacement_count'=>$count));
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 if($saved!==1){$wpdb->delete($wpdb->prefix.'crmp_proof_revisions',array('id'=>$revision_id));if($attachment)wp_delete_attachment($attachment,true);return new WP_Error('crmp_replace','Unable to update proof. Refresh and retry.');}
 crmp_customer_save_method($order,$details['method']);
 do_action('crmp_proof_replaced',$id);
 set_transient('crmp_replace_'.$id,1,10);
 $order->add_order_note('Customer replaced payment proof; pending review. Transaction #'.$id.', revision '.($version+1).'.');
 return true;
}
function crmp_render_proof_history($row){
 if(!current_user_can('manage_woocommerce')||!empty($row->purged_at))return;global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private payment ledger has no object API; live reads and writes must bypass caches for concurrency and evidence cleanup.
 $revisions=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE transaction_id=%d ORDER BY id DESC',$wpdb->prefix.'crmp_proof_revisions',$row->id));
 if(!$revisions)return;
 echo '<details class="crmp-proof-history"><summary>Previous proof and review history ('.count($revisions).')</summary><p>Read-only history. Review the current proof above before making a decision.</p>';
 foreach($revisions as $r){
  echo '<section class="crmp-proof-revision"><h4>Revision '.absint($r->proof_version).' — '.esc_html($r->submitted_at).'</h4>';
  crmp_customer_detail_summary($r,false);
  echo '<p><strong>Transaction ID:</strong> '.esc_html($r->transaction_ref!==''?$r->transaction_ref:'Not supplied').'<br>Previous status: '.esc_html(crmp_status_label($r->review_status)).($r->reviewed_at?'<br>Reviewed: '.esc_html($r->reviewed_at).' by '.esc_html(crmp_reviewer_label($r->reviewed_by)):'').($r->review_note?'<br>Reviewer note: '.nl2br(esc_html($r->review_note)):'');
  if($r->screenshot_id)echo '<br><a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=crmp_screenshot&id='.$row->id.'&revision='.$r->id),'crmp_screenshot_'.$row->id)).'">View previous screenshot</a>';
  echo '</p></section>';
 }

 echo '</details>';
}
/** Process eligible items independently; skipped records never prevent other selected items from being handled. */
function crmp_bulk_manage($ids,$operation,$note='',$versions=array()){
 $processed=array();$skipped=array();
 if(!current_user_can('manage_woocommerce'))return new WP_Error('crmp_forbidden','Not allowed.');
 if(!in_array($operation,array('trash','restore','delete','reject','approve'),true))return new WP_Error('crmp_operation','Invalid operation.');
 $note=trim(sanitize_textarea_field($note));
 if($operation==='reject'&&$note==='')return new WP_Error('crmp_reason','Enter a reason for rejecting the selected pending proofs.');
 foreach($ids as $id){$id=absint($id);$row=crmp_get_transaction($id);
  if(!$row||!empty($row->purged_at)||(in_array($operation,array('trash','reject','approve'),true)?!empty($row->trashed_at):empty($row->trashed_at))){$skipped[$id]='Record changed or is unavailable.';continue;}
  if(!crmp_acquire_order_lock($row->order_id,false)){$skipped[$id]='Another payment request is in progress.';continue;}
  $row=crmp_get_transaction($id);
  if(in_array($operation,array('reject','approve'),true)){
   $eligible=$operation==='approve'?array('pending','rejected'):array('pending');
   if(!$row||!in_array($row->status,$eligible,true)){$skipped[$id]=$operation==='approve'?'Only Pending or Rejected proof can be bulk approved.':'Only Pending proof can be bulk rejected.';continue;}
   if(!isset($versions[$id])){$skipped[$id]='Refresh to review the latest proof.';continue;}
   $result=crmp_review_transaction($id,$operation==='approve'?'verified':'rejected',$note,absint($versions[$id]));
  }else{$result=crmp_manage_transaction($id,$operation);}
  if(is_wp_error($result)){$skipped[$id]=$result->get_error_message();continue;}$processed[]=$id;
 }
 $verbs=array('trash'=>'moved to Trash','restore'=>'restored','delete'=>'permanently deleted','reject'=>'rejected','approve'=>'approved');
 return array('processed'=>$processed,'skipped'=>$skipped,'message'=>count($processed).' '.$verbs[$operation].'; '.count($skipped).' skipped.'.($skipped?' '.implode(' ',array_unique(array_values($skipped))):''));
}
/** WordPress-style list pagination, with the current filters preserved. */
function crmp_list_navigation($which,$count,$per_page,$page,$trash,$status,$search,$navigation_args=array()){
 $pages=max(1,(int)ceil($count/$per_page));$args=array_merge(array('page'=>'crmp-transactions','trash'=>$trash?'1':'0','status'=>$status,'s'=>$search),$navigation_args);
 // translators: %s: Number of transactions.
 $items=_n('%s item','%s items',$count,'cardraabta-manual-payments-for-woocommerce');
 echo '<div class="tablenav '.esc_attr($which).'"><div class="tablenav-pages'.($pages===1?' one-page':'').'"><span class="displaying-num">'.esc_html(sprintf($items,number_format_i18n($count))).'</span>';
 if($pages>1){
  if($which==='top'){echo '<form class="crmp-pagination-form" method="get" action="'.esc_url(admin_url('admin.php')).'">';foreach($args as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';}
  echo '<span class="pagination-links">';
  foreach(array(array('first-page','First page','&laquo;',1,$page<=1),array('prev-page','Previous page','&lsaquo;',max(1,$page-1),$page<=1)) as $link)crmp_page_link($link,$args);
  echo '<span class="paging-input">';
  if($which==='top')echo '<label class="screen-reader-text" for="crmp-current-page">Current Page</label><input class="current-page" id="crmp-current-page" type="text" inputmode="numeric" name="paged" value="'.absint($page).'" size="'.absint(strlen((string)$pages)).'">';else echo '<span class="tablenav-paging-text">'.absint($page);
  echo ' of <span class="total-pages">'.esc_html(number_format_i18n($pages)).'</span>'.($which==='bottom'?'</span>':'').'</span> ';
  foreach(array(array('next-page','Next page','&rsaquo;',min($pages,$page+1),$page>=$pages),array('last-page','Last page','&raquo;',$pages,$page>=$pages)) as $link)crmp_page_link($link,$args);
  echo '</span>';if($which==='top')echo '</form>';
 }
 echo '</div><br class="clear"></div>';
}
function crmp_page_link($link,$args){
 if($link[4]){echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">'.wp_kses_post($link[2]).'</span> ';return;}
 echo '<a class="'.esc_attr($link[0]).' button" href="'.esc_url(add_query_arg(array_merge($args,array('paged'=>$link[3])),admin_url('admin.php'))).'"><span class="screen-reader-text">'.esc_html($link[1]).'</span><span aria-hidden="true">'.wp_kses_post($link[2]).'</span></a> ';
}
/** Manager-only CSV, called only after the export capability and nonce checks. */
function crmp_export_proof_history(){
 global $wpdb;
 while(ob_get_level()>0){if(!ob_end_clean())break;}
 nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="cardraabta-proof-history.csv"');
 $out=fopen('php://output','w');
 fputcsv($out,array('Transaction record','Order','Method','Amount','Currency','Proof version','Submission','Transaction reference','Screenshot ID','Proof status','Submitted','Reviewed','Reviewer','Review note','Replaced','Location','Payment date','Customer note','Proof source','Customer confirmation','Field settings'),',','"','');
 foreach(array(false,true) as $history){
  $cursor=0;
  do{
   if($history){
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Stream retained private revisions in bounded batches; purged transactions must never be exported.
    $rows=$wpdb->get_results($wpdb->prepare('SELECT r.*,t.order_id,t.amount,t.currency,t.trashed_at FROM %i r INNER JOIN %i t ON t.id=r.transaction_id WHERE t.purged_at IS NULL AND r.id>%d ORDER BY r.id ASC LIMIT 500',$wpdb->prefix.'crmp_proof_revisions',crmp_table(),$cursor));
   }else{
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Stream authoritative retained current proof in bounded batches.
    $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE purged_at IS NULL AND id>%d ORDER BY id ASC LIMIT 500',crmp_table(),$cursor));
   }
   foreach($rows as $row){
    $cursor=(int)$row->id;
    $cells=array($history?$row->transaction_id:$row->id,$row->order_id,($history?($row->method?:'Not recorded'):$row->method),$row->amount,$row->currency,$row->proof_version,$history?'Previous':'Current',$row->transaction_ref,$row->screenshot_id,$history?$row->review_status:$row->status,$row->submitted_at,$row->reviewed_at,crmp_reviewer_label($row->reviewed_by),$history?$row->review_note:$row->note,$history?$row->replaced_at:'',!empty($row->trashed_at)?'Trash':'Active',$row->payment_date??'',$row->customer_note??'',crmp_recorded_proof_source($row),crmp_recorded_confirmation($row),crmp_recorded_field_settings($row));
    fputcsv($out,array_map(static function($value){$value=(string)$value;return preg_match('/^\s*[=+@\-]/u',$value)?"'".$value:$value;},$cells),',','"','');
   }
  }while(count($rows)===500);
 }
 // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV response stream, not a filesystem file.
 fclose($out);
}
/** Preserve a rejected decision when the same proof is later approved. */
function crmp_archive_rejected_review($row){
 global $wpdb;$table=$wpdb->prefix.'crmp_proof_revisions';
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Idempotent private review snapshot, checked under the existing order lock.
 $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE transaction_id=%d AND proof_version=%d AND review_status='rejected' AND review_note=%s AND COALESCE(reviewed_at,'')=%s AND reviewed_by=%d LIMIT 1",$table,$row->id,$row->proof_version,(string)$row->note,(string)$row->reviewed_at,$row->reviewed_by));
 if($exists)return true;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Preserve evidence and the previous decision before changing the active review.
 $saved=$wpdb->insert($table,array('transaction_id'=>$row->id,'proof_version'=>$row->proof_version,'transaction_ref'=>$row->transaction_ref,'method'=>$row->method,'payment_date'=>$row->payment_date??'','customer_note'=>$row->customer_note??'','proof_context'=>$row->proof_context??'','field_policy'=>$row->field_policy??'','customer_confirmed'=>$row->customer_confirmed??'','screenshot_id'=>$row->screenshot_id,'submitted_at'=>$row->submitted_at,'replaced_at'=>current_time('mysql'),'review_status'=>'rejected','review_note'=>$row->note,'reviewed_by'=>$row->reviewed_by,'reviewed_at'=>$row->reviewed_at));
 return $saved?true:new WP_Error('crmp_review_history','Unable to preserve the previous rejection. No approval was applied. Please retry.');
}

/** Return to the manually selected list context; never use an order ID as a search filter. */
function crmp_review_return_url($id,$context=array()){
 $args=array('page'=>'crmp-transactions','view'=>absint($id));
 $search=isset($context['list_s'])&&is_scalar($context['list_s'])?sanitize_text_field(wp_unslash((string)$context['list_s'])):'';
 $status=isset($context['list_status'])&&is_scalar($context['list_status'])?sanitize_key(wp_unslash((string)$context['list_status'])):'';
 $page=isset($context['list_paged'])&&is_scalar($context['list_paged'])?max(1,absint($context['list_paged'])):1;
 if($search!=='')$args['s']=$search;
 if(in_array($status,array('pending','verified','rejected','closed'),true))$args['status']=$status;
 if($page>1)$args['paged']=$page;
 return add_query_arg($args,admin_url('admin.php'));
}
