<?php
/** Manager-only log visibility is separate from the outbox and its duplicate-send protection. */
if(!defined('ABSPATH'))exit;
function crmp_mask_email($email){$parts=explode('@',(string)$email,2);return count($parts)===2?substr($parts[0],0,1).'***@'.$parts[1]:'';}
function crmp_email_status_label($status){return array('pending'=>'Queued','sending'=>'Sending / outcome unknown','accepted'=>'Sent to mail server','failed'=>'Failed to send','skipped'=>'Not sent — no longer needed','expired'=>'Expired — not sent')[$status]??ucfirst($status);}
function crmp_email_log_hide($ids=null){
 if(!current_user_can('manage_woocommerce'))return new WP_Error('crmp_forbidden','Not allowed.');global $wpdb;
 $where='log_hidden=0';$args=array($wpdb->prefix.'crmp_email_queue');
 if($ids!==null){$ids=array_values(array_unique(array_filter(array_map('absint',$ids))));if(!$ids)return 0;$where.=' AND id IN ('.implode(',',array_fill(0,count($ids),'%d')).')';$args=array_merge($args,$ids);}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only a fixed WHERE clause and generated %d placeholders are interpolated; all values go through prepare. Keep outbox state/dedup keys intact.
 return $wpdb->query($wpdb->prepare("UPDATE %i SET log_hidden=1,recipient_masked='',payload=CASE WHEN status IN ('pending','sending') THEN payload ELSE '{}' END,recipient=CASE WHEN status IN ('pending','sending') THEN recipient ELSE '' END,last_error='' WHERE $where",$args));
}
function crmp_email_log_auto_cleanup(){
 if(get_option('crmp_email_log_auto_cleanup','yes')!=='yes')return;global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Hide old log entries without deleting queued work or duplicate-event protection.
 $wpdb->query($wpdb->prepare("UPDATE %i SET log_hidden=1,recipient_masked='' WHERE log_hidden=0 AND created_at < %s",$wpdb->prefix.'crmp_email_queue',gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS)));
}
add_filter('set_screen_option_crmp_email_logs_per_page',static function($saved,$option,$value){return max(1,min(100,absint($value)));},10,3);
function crmp_email_logs_page(){
 if(!current_user_can('manage_woocommerce'))return;global $wpdb;$table=$wpdb->prefix.'crmp_email_queue';crmp_email_log_auto_cleanup();
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only manager log filters.
 $status=sanitize_key(wp_unslash($_GET['status']??''));if(!in_array($status,array('pending','sending','accepted','failed','skipped','expired'),true))$status='';
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only manager log filters.
 $source=sanitize_key(wp_unslash($_GET['source']??''));if(!in_array($source,array('test','notification'),true))$source='';
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only manager log search; query values are escaped below.
 $search=sanitize_text_field(wp_unslash($_GET['s']??''));
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination, clamped to existing page count.
 $page=max(1,absint($_GET['paged']??1));$per=max(1,min(100,(int)(get_user_option('crmp_email_logs_per_page')?:20)));
 $where="log_hidden=0 AND (%s='' OR status=%s) AND (%s='' OR is_test=%d) AND (%s='' OR CAST(order_id AS CHAR) LIKE %s OR CAST(transaction_id AS CHAR) LIKE %s OR template_id LIKE %s OR recipient_masked LIKE %s)";
 $like='%'.$wpdb->esc_like($search).'%';$args=array($status,$status,$source,$source==='test'?1:0,$search,$like,$like,$like,$like);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed WHERE template and prepared values shared by bounded count/list queries.
 $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE $where",array_merge(array($table),$args)));
 $page=min($page,max(1,(int)ceil($count/$per)));
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed WHERE template, prepared pagination and filter values.
 $rows=$wpdb->get_results($wpdb->prepare("SELECT id,template_id,order_id,transaction_id,recipient,recipient_masked,is_test,status,attempts,last_error,created_at,updated_at FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",array_merge(array($table),$args,array($per,($page-1)*$per))));
 $ids=array_map(static function($row){return (int)$row->id;},$rows);
 wp_enqueue_script('crmp-email-logs',CRMP_URL.'assets/email-logs.js',array('jquery'),crmp_asset_version('assets/email-logs.js'),true);
 wp_localize_script('crmp-email-logs','crmpEmailLogs',array('ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('crmp_email_settings'),'ids'=>$ids,'selectionNonce'=>wp_create_nonce('crmp_email_log_selection_'.implode(',',$ids))));
 echo '<div class="wrap crmp-wrap"><h1>Email Logs</h1><p>Sent to mail server means the mail system accepted the message; inbox delivery is unconfirmed. Failed means the send operation returned an error. Delivery confirmation needs support from your email provider.</p><p>Removing logs only clears their visible record. It does not cancel queued emails, change payment history or allow duplicate sends.</p><div class="crmp-panel"><label><input type="checkbox" id="crmp-log-auto-cleanup" '.checked(get_option('crmp_email_log_auto_cleanup','yes'),'yes',false).'> Automatically clear email logs after 30 days</label> <button type="button" class="button" id="crmp-log-save-retention">Save log setting</button></div>';
 echo '<form method="get" class="crmp-filters"><input type="hidden" name="page" value="crmp-email-logs"><input type="search" name="s" aria-label="Search email logs" placeholder="Order, transaction or email type" value="'.esc_attr($search).'"><select name="status"><option value="">All statuses</option>';foreach(array('pending','sending','accepted','failed','skipped','expired') as $value)echo '<option value="'.esc_attr($value).'" '.selected($status,$value,false).'>'.esc_html(crmp_email_status_label($value)).'</option>';echo '</select><select name="source"><option value="">All email types</option><option value="test" '.selected($source,'test',false).'>Test emails</option><option value="notification" '.selected($source,'notification',false).'>Notifications</option></select><button class="button">Filter</button></form>';
 echo '<p id="crmp-log-feedback" role="status" aria-live="polite"></p><div class="crmp-list-toolbar"><div><select id="crmp-log-bulk-action" aria-label="Bulk actions"><option value="">Bulk actions</option><option value="delete">Delete selected logs</option></select> <button type="button" class="button" id="crmp-log-apply">Apply</button> <span id="crmp-log-count">0 selected</span> <button type="button" class="button" id="crmp-log-clear-all">Clear all email logs</button></div>';
 crmp_list_navigation('top',$count,$per,$page,false,$status,$search,array('page'=>'crmp-email-logs','source'=>$source));echo '</div><div class="crmp-table-scroll"><table class="widefat striped crmp-log-table"><thead>';crmp_email_log_headers();echo '</thead><tbody>';
 foreach($rows as $row){$type=crmp_email_catalog()[$row->template_id]??array('Unknown','admin');$masked=$row->recipient_masked?:crmp_mask_email($row->recipient);echo '<tr><td class="check-column"><input type="checkbox" class="crmp-log-select" value="'.absint($row->id).'" aria-label="Select log '.absint($row->id).'"></td><td>'.esc_html(ucfirst($type[1]).' — '.$type[0]).'</td><td>'.($row->is_test?'Test':'Notification').'</td><td>'.($row->order_id?'#'.absint($row->order_id):'—').($row->transaction_id?'<br>Transaction #'.absint($row->transaction_id):'').'</td><td>'.esc_html($masked?:'Removed').'</td><td>'.esc_html(crmp_email_status_label($row->status)).($row->status==='accepted'?'<br><small>Inbox delivery unconfirmed</small>':'').($row->last_error?'<br><small>'.esc_html($row->last_error).'</small>':'').'</td><td>'.absint($row->attempts).'</td><td>'.esc_html($row->created_at).' UTC</td><td>'.esc_html($row->updated_at).' UTC</td><td><button type="button" class="button crmp-log-delete" data-id="'.absint($row->id).'">Delete log</button>'.($row->status==='failed'?' <button type="button" class="button crmp-log-retry" data-id="'.absint($row->id).'">Retry failed email</button>':'').'</td></tr>';}
 if(!$rows)echo '<tr><td colspan="10">No email logs found.</td></tr>';echo '</tbody><tfoot>';crmp_email_log_headers();echo '</tfoot></table></div>';crmp_list_navigation('bottom',$count,$per,$page,false,$status,$search,array('page'=>'crmp-email-logs','source'=>$source));echo '</div>';
}
function crmp_email_log_headers(){echo '<tr><th class="check-column"><input type="checkbox" class="crmp-log-select-page" aria-label="Select all logs on this page"></th><th>Email</th><th>Type</th><th>Order / Transaction</th><th>Recipient</th><th>Status</th><th>Attempts</th><th>Created</th><th>Updated</th><th>Actions</th></tr>';}
add_action('wp_ajax_crmp_email_log_manage',static function(){
 crmp_email_ajax_authorize();
 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- crmp_email_ajax_authorize checks manager capability, POST and nonce.
 $operation=sanitize_key(wp_unslash($_POST['operation']??''));
 if($operation==='retention'){
  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Manager POST nonce verified above.
  update_option('crmp_email_log_auto_cleanup',!empty($_POST['enabled'])?'yes':'no',false);wp_send_json_success(array('message'=>'Log cleanup preference saved.'));
 }
 if($operation==='clear'){$result=crmp_email_log_hide();}
 elseif($operation==='delete'){
  // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IDs are mapped to integers and selection nonce is bound to the displayed page.
  $ids=isset($_POST['ids'])&&is_array($_POST['ids'])?array_values(array_unique(array_map('absint',$_POST['ids']))):array();
  // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IDs are mapped to integers before use in the page-specific nonce.
  $page_ids=isset($_POST['page_ids'])&&is_array($_POST['page_ids'])?array_values(array_unique(array_map('absint',$_POST['page_ids']))):array();
  if(!$ids||count($page_ids)>100||array_diff($ids,$page_ids)||!check_ajax_referer('crmp_email_log_selection_'.implode(',',$page_ids),'selection_nonce',false))wp_send_json_error(array('message'=>'Invalid log selection. Refresh the page.'),403);
  $result=crmp_email_log_hide($ids);
 }else wp_send_json_error(array('message'=>'Invalid log operation.'),400);
 if(is_wp_error($result)||$result===false)wp_send_json_error(array('message'=>'Logs could not be cleared. Please retry.'),500);
 wp_send_json_success(array('message'=>absint($result).' logs removed. Queued work and duplicate protection are unchanged.'));
});
/** Immediate test sends have their own immutable log row and never use real order/customer data. */
function crmp_send_logged_test_email($id,$template,$recipient){global $wpdb;$table=$wpdb->prefix.'crmp_email_queue';
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Log the explicit test request before rendering or invoking the mail transport.
 $ok=$wpdb->insert($table,array('event_key'=>hash('sha256',wp_generate_uuid4()),'template_id'=>$id,'order_id'=>0,'is_test'=>1,'recipient'=>$recipient,'recipient_masked'=>crmp_mask_email($recipient),'payload'=>'{}','status'=>'sending','attempts'=>1,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)));
 if(!$ok)return new WP_Error('crmp_test_log','Unable to create a test-email log. No test email was sent.');
 return crmp_run_test_email((int)$wpdb->insert_id,$id,$template,$recipient);
}
function crmp_run_test_email($job_id,$id,$template,$recipient){
 $sent=false;$message='Test email failed to send. Check the site mail/SMTP configuration.';
 try{$data=array('customer_name'=>'Alex','order_number'=>'12345','amount'=>'Rs 1,499','transaction_reference'=>'SAMPLE-12345','admin_note'=>$template['sample_note'],'store_name'=>wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES));$sent=crmp_email_transport()->send($recipient,'[TEST] '.crmp_email_subject($template,$data),crmp_email_html($id,$template,$data,home_url('/')),array('Content-Type: text/html; charset=UTF-8'),array());}catch(Throwable $error){$message='Test email rendering or transport failed. Check the store email configuration.';}
 global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Record the actual transport result; never claim inbox delivery.
 $wpdb->update($wpdb->prefix.'crmp_email_queue',array('status'=>$sent?'accepted':'failed','last_error'=>$sent?'':$message,'updated_at'=>current_time('mysql',true)),array('id'=>$job_id,'status'=>'sending'));
 crmp_email_scrub_hidden_job($job_id);
 return $sent?true:new WP_Error('crmp_test_send',$message);
}
function crmp_send_test_log_job($job){global $wpdb;
 if(!is_email($job->recipient))return;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Retry only a claimed explicit test job, using saved template settings and sample data.
 if($wpdb->update($wpdb->prefix.'crmp_email_queue',array('status'=>'sending','attempts'=>(int)$job->attempts+1),array('id'=>$job->id,'status'=>'pending'))!==1)return;
 $c=crmp_email_config();crmp_run_test_email($job->id,$job->template_id,$c['templates'][$job->template_id],$job->recipient);
}

function crmp_email_scrub_hidden_job($id){global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A cleared log keeps payload only until its already queued send finishes.
 $wpdb->query($wpdb->prepare("UPDATE %i SET payload='{}',recipient='',recipient_masked='' WHERE id=%d AND log_hidden=1 AND status NOT IN ('pending','sending')",$wpdb->prefix.'crmp_email_queue',$id));
}
