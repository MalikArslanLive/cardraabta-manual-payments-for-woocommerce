<?php
/** Transactional notifications. No email is sent synchronously inside a payment decision. */
if(!defined('ABSPATH'))exit;
function crmp_install_email_queue($charset){
 global $wpdb;
 dbDelta("CREATE TABLE ".$wpdb->prefix."crmp_email_queue (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 event_key varchar(64) NOT NULL,
 template_id varchar(40) NOT NULL,
 transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
 order_id bigint(20) unsigned NOT NULL,
 proof_version bigint(20) unsigned NOT NULL DEFAULT 0,
 recipient varchar(254) NOT NULL,
 recipient_masked varchar(254) NOT NULL DEFAULT '',
 is_test tinyint unsigned NOT NULL DEFAULT 0,
 log_hidden tinyint unsigned NOT NULL DEFAULT 0,
 payload longtext NOT NULL,
 status varchar(20) NOT NULL DEFAULT 'pending',
 attempts int unsigned NOT NULL DEFAULT 0,
 last_error varchar(191) NOT NULL DEFAULT '',
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY event_key (event_key),
 KEY status_id (status,id),
 KEY transaction_id (transaction_id),
 KEY log_visible (log_hidden,id),
 KEY log_age (log_hidden,created_at)
 ) $charset;");
}
function crmp_email_catalog(){
 return array(
 'admin_received'=>array('New proof received','admin','received',true),
 'admin_replaced'=>array('Proof replaced','admin','replaced',true),
 'admin_verified'=>array('Payment approved','admin','verified',false),
 'admin_rejected'=>array('Payment rejected','admin','rejected',false),
 'admin_attention'=>array('Review needs attention','admin','attention',true),
 'admin_missing'=>array('Proof not submitted alert','admin','missing',true),
 'customer_received'=>array('Proof received','customer','received',true),
 'customer_replaced'=>array('Updated proof received','customer','replaced',true),
 'customer_verified'=>array('Payment verified','customer','verified',true),
 'customer_rejected'=>array('Payment rejected','customer','rejected',true),
 'customer_missing'=>array('Proof not submitted reminder','customer','missing',true));
}
function crmp_email_defaults($id){
 $catalog=crmp_email_catalog();$type=$catalog[$id]??$catalog['customer_received'];$event=$type[2];$admin=$type[1]==='admin';
 $messages=array('received'=>'Hi {customer_name}, we received your payment proof for order #{order_number}. Our team will review it shortly.','replaced'=>'Hi {customer_name}, we received your updated payment proof for order #{order_number}. It is now awaiting review.','verified'=>'Hi {customer_name}, your payment for order #{order_number} has been verified. Thank you!','rejected'=>'Hi {customer_name}, your payment proof for order #{order_number} could not be verified. Please review the reason below and submit updated proof.','attention'=>'Payment review for order #{order_number} was interrupted. Check the transaction and WooCommerce order before retrying the decision.','missing'=>'Hi {customer_name}, we have not received payment proof for order #{order_number}. If you have already paid, please submit your transaction reference or screenshot.');
 $message=$admin?'Order #{order_number} — '.$type[0].'. Review the payment details in your dashboard.':$messages[$event];if($event==='attention')$message=$messages[$event];
 return array('subject'=>'[{store_name}] '.$type[0].' — Order #{order_number}','heading'=>$type[0],'message'=>'<p>'.$message.'</p>','additional'=>!$admin&&in_array($event,array('rejected','missing'),true)?'If you have already paid, do not pay again. Contact us if you need help.':'','button'=>$admin?'Review payment':($event==='rejected'?'Resubmit payment proof':($event==='missing'?'Submit payment proof':'View order')),'show_note'=>in_array($event,array('verified','rejected'),true),'note_label'=>$event==='rejected'?'Reason':'Reviewer note','sample_note'=>'The reference number is not readable.');
}
function crmp_email_required_defaults($id,$template){
 $defaults=crmp_email_defaults($id);
 foreach(array('subject','heading','message','button') as $field){
  $text=is_scalar($template[$field]??null)?trim(str_replace("\xc2\xa0",' ',html_entity_decode(wp_strip_all_tags((string)$template[$field]),ENT_QUOTES,'UTF-8'))):'';
  if($text==='')$template[$field]=$defaults[$field];
 }
 return $template;
}
function crmp_email_config(){
 $saved=get_option('crmp_email_settings',array());$saved=is_array($saved)?$saved:array();$enabled=array();$templates=array();
 foreach(crmp_email_catalog() as $id=>$type){$enabled[$id]=isset($saved['enabled'])?!empty($saved['enabled'][$id]):$type[3];$templates[$id]=crmp_email_required_defaults($id,array_merge(crmp_email_defaults($id),is_array($saved['templates'][$id]??null)?$saved['templates'][$id]:array()));}
 return array('admin_emails'=>$saved['admin_emails']??get_option('admin_email'),'delay'=>max(1,min(10080,absint($saved['delay']??15))),'enabled'=>$enabled,'templates'=>$templates);
}
function crmp_email_schedule($hook,$args,$when){
 if(function_exists('as_schedule_single_action')){if(!as_get_scheduled_actions(array('hook'=>$hook,'args'=>$args,'group'=>'crmp-emails','status'=>'pending','per_page'=>1),'ids'))as_schedule_single_action($when,$hook,$args,'crmp-emails',false);}
 elseif(!wp_next_scheduled($hook,$args))wp_schedule_single_event($when,$hook,$args);
}
function crmp_email_snapshot($row,$order){return array('customer_name'=>$order->get_billing_first_name()?:'Customer','order_number'=>$order->get_order_number(),'amount'=>wp_strip_all_tags(html_entity_decode(wc_price($order->get_total(),array('currency'=>$order->get_currency())),ENT_QUOTES,'UTF-8')),'transaction_reference'=>$row?(string)$row->transaction_ref:'','admin_note'=>$row?(string)$row->note:'','store_name'=>wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES));}
function crmp_email_event($event,$id=0,$order_id=0){
 try{
  $row=$id?crmp_get_transaction($id):null;$order=wc_get_order($row?$row->order_id:$order_id);if(crmp_order_is_deleted($order))return;
  $config=crmp_email_config();$payload=crmp_email_snapshot($row,$order);$version=$row?(int)$row->proof_version:0;
  foreach(crmp_email_catalog() as $template=>$type){
   if($type[2]!==$event||empty($config['enabled'][$template]))continue;
   $recipients=$type[1]==='admin'?explode(',',$config['admin_emails']):array($order->get_billing_email());
   foreach(array_unique(array_map('trim',$recipients)) as $recipient){if(!is_email($recipient))continue;
    $key=hash('sha256',$event.'|'.($row?$row->id:'order-'.$order->get_id()).'|'.$version.'|'.$template.'|'.strtolower($recipient));
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Unique event key makes queue insertion idempotent across payment retries.
    $exists=$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE event_key=%s',$wpdb->prefix.'crmp_email_queue',$key));if($exists)continue;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Persistent outbox; sending is deferred until after payment handling.
    $ok=$wpdb->insert($wpdb->prefix.'crmp_email_queue',array('event_key'=>$key,'template_id'=>$template,'transaction_id'=>$id,'order_id'=>$order->get_id(),'proof_version'=>$version,'recipient'=>$recipient,'recipient_masked'=>crmp_mask_email($recipient),'payload'=>wp_json_encode($payload),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)));
    if($ok)crmp_email_schedule('crmp_send_email_job',array((int)$wpdb->insert_id),time()+2);
   }
  }
 }catch(Throwable $error){if(function_exists('wc_get_logger'))wc_get_logger()->error('Notification could not be queued. Payment state is unchanged.',array('source'=>'crmp-emails'));}
}
add_action('crmp_proof_submitted',static function($id){crmp_email_event('received',$id);});
add_action('crmp_proof_replaced',static function($id){crmp_email_event('replaced',$id);});
add_action('crmp_payment_reviewed',static function($id,$decision){crmp_email_event($decision,$id);},10,2);
add_action('crmp_payment_attention',static function($id){crmp_email_event('attention',$id);});
function crmp_email_attention_error($id,$code,$message){do_action('crmp_payment_attention',$id);return new WP_Error($code,$message);}
add_action('woocommerce_after_order_object_save',static function($order){
 if(!is_a($order,'WC_Order')||$order->get_payment_method()!=='crmp_manual'||!$order->get_date_created())return;
 $started=(int)get_option('crmp_email_started_at',0);if(!$started||$order->get_date_created()->getTimestamp()<$started||$order->get_meta('_crmp_missing_proof_checked'))return;
 $c=crmp_email_config();if(!$c['enabled']['admin_missing']&&!$c['enabled']['customer_missing'])return;
 crmp_email_schedule('crmp_missing_proof_reminder',array($order->get_id()),max(time()+2,$order->get_date_created()->getTimestamp()+$c['delay']*MINUTE_IN_SECONDS));
});
function crmp_missing_proof_eligible($order){
 if(crmp_order_is_deleted($order)||$order->get_payment_method()!=='crmp_manual'||$order->is_paid()||$order->get_date_paid()||!in_array($order->get_status(),array('pending','on-hold'),true))return false;
 global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Even previously removed proof counts as submitted; never remind that it was not submitted.
 return !(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d',crmp_table(),$order->get_id()));
}
add_action('crmp_missing_proof_reminder','crmp_missing_proof_reminder');
function crmp_missing_proof_reminder($id){
 if(!function_exists('wc_get_order'))return;
 if(!crmp_acquire_order_lock($id,false)){crmp_email_schedule('crmp_missing_proof_reminder',array($id),time()+60);return;}
 $order=wc_get_order($id);if(crmp_order_is_deleted($order)||!$order->get_date_created()||$order->get_meta('_crmp_missing_proof_checked'))return;
 $c=crmp_email_config();$due=$order->get_date_created()->getTimestamp()+$c['delay']*MINUTE_IN_SECONDS;if(time()<$due){crmp_email_schedule('crmp_missing_proof_reminder',array($id),$due);return;}
 if(crmp_missing_proof_eligible($order))crmp_email_event('missing',0,$id);
 $order->update_meta_data('_crmp_missing_proof_checked',time());$order->save();
}
function crmp_email_job_eligible($job,$order){
 if(crmp_order_is_deleted($order)||$order->get_payment_method()!=='crmp_manual')return false;
 $c=crmp_email_config();if(empty($c['enabled'][$job->template_id]))return false;
 $type=crmp_email_catalog()[$job->template_id]??null;if(!$type)return false;
 if($type[1]==='customer'&&strtolower($job->recipient)!==strtolower($order->get_billing_email()))return false;
 if($type[1]==='admin'&&!in_array(strtolower($job->recipient),array_map('strtolower',array_map('trim',explode(',',$c['admin_emails']))),true))return false;
 if($type[2]==='missing')return crmp_missing_proof_eligible($order);
 $row=crmp_get_transaction($job->transaction_id);if(!$row||crmp_transaction_removed($row)||(int)$row->proof_version!==(int)$job->proof_version)return false;
 if(in_array($type[2],array('received','replaced'),true))return $row->status==='pending'&&!$order->is_paid()&&!$order->get_date_paid()&&in_array($order->get_status(),array('pending','on-hold','failed'),true);
 if($type[2]==='verified')return $row->status==='verified'&&$order->is_paid()&&!$order->has_status(array('cancelled','refunded','failed'));
 if($type[2]==='rejected')return $row->status==='rejected'&&!$order->is_paid()&&!$order->get_date_paid()&&in_array($order->get_status(),array('pending','on-hold','failed'),true);
 return in_array($row->status,array('verifying','rejecting'),true);
}
add_action('crmp_send_email_job','crmp_send_email_job');
/** Older WooCommerce versions load WC_Email only when the mailer is initialized. */
function crmp_email_ready(){
 if(!function_exists('WC') || !function_exists('wc_get_order') || !WC())return false;
 if(!class_exists('WC_Email'))WC()->mailer();
 return class_exists('WC_Email');
}
function crmp_send_email_job($id){
 if(!crmp_email_ready())return;
 if(!crmp_acquire_named_lock('crmp_email_'.absint($id),false))return;global $wpdb;$table=$wpdb->prefix.'crmp_email_queue';
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Authoritative outbox state; sent or uncertain jobs must not be resent automatically.
 $job=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',$table,$id));if(!$job||$job->status!=='pending')return;
 if(!empty($job->is_test)){crmp_send_test_log_job($job);return;}
 if(!crmp_acquire_order_lock($job->order_id,false)){crmp_email_schedule('crmp_send_email_job',array((int)$id),time()+60);return;}
 $order=wc_get_order($job->order_id);
 if(!crmp_email_job_eligible($job,$order)){
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Suppress outdated, removed or disabled notifications immediately before sending.
  $wpdb->update($table,array('status'=>'skipped','last_error'=>'Notification no longer applies.','updated_at'=>current_time('mysql',true)),array('id'=>$id,'status'=>'pending'));crmp_email_scrub_hidden_job($id);return;
 }
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Durable compare-and-swap avoids duplicate dispatch.
 if($wpdb->update($table,array('status'=>'sending','attempts'=>(int)$job->attempts+1,'updated_at'=>current_time('mysql',true)),array('id'=>$id,'status'=>'pending'))!==1)return;
 $sent=false;$error='Mail transport rejected the message. Check your email/SMTP configuration.';
 try{$c=crmp_email_config();$mail=crmp_email_transport();$sent=$mail->send($job->recipient,crmp_email_subject($c['templates'][$job->template_id],json_decode($job->payload,true)),crmp_email_html($job->template_id,$c['templates'][$job->template_id],json_decode($job->payload,true),crmp_email_order_url($order,$job->template_id)),array('Content-Type: text/html; charset=UTF-8'),array());}catch(Throwable $failure){$error='Mail rendering or transport failed. Check the store email configuration.';}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Accepted means handed to mail transport, not confirmed delivery to inbox.
 $wpdb->update($table,array('status'=>$sent?'accepted':'failed','last_error'=>$sent?'':$error,'updated_at'=>current_time('mysql',true)),array('id'=>$id,'status'=>'sending'));
 crmp_email_scrub_hidden_job($id);
}
function crmp_email_order_url($order,$template){$type=crmp_email_catalog()[$template];return $type[1]==='admin'?add_query_arg(array('page'=>'crmp-transactions'),admin_url('admin.php')):(($order->get_customer_id()?$order->get_view_order_url():$order->get_checkout_order_received_url()).'#crmp-payment-proof');}
function crmp_email_subject($template,$data){$replace=array();foreach($data as $key=>$value)$replace['{'.$key.'}']=wp_strip_all_tags((string)$value);return sanitize_text_field(strtr($template['subject'],$replace));}
function crmp_email_html($id,$template,$data,$url){
 $replace=array();foreach($data as $key=>$value)$replace['{'.$key.'}']=esc_html((string)$value);
 $body=wpautop(wp_kses_post(strtr($template['message'],$replace)));
 $body.='<table cellspacing="0" cellpadding="8" style="width:100%;margin:16px 0;border:1px solid #ddd"><tr><th align="left">Order</th><td>'.esc_html($data['order_number']).'</td></tr><tr><th align="left">Amount</th><td>'.esc_html($data['amount']).'</td></tr>'.((string)($data['transaction_reference']??'')!==''?'<tr><th align="left">Transaction reference</th><td>'.esc_html($data['transaction_reference']).'</td></tr>':'').'</table>';
 if(!empty($template['show_note'])&&trim($data['admin_note'])!==''){$reject=(crmp_email_catalog()[$id][2]==='rejected');$body.='<div style="padding:14px;margin:18px 0;background:'.($reject?'#fff0f0':'#eef8f0').';border-left:4px solid '.($reject?'#cc3030':'#248449').'"><strong>'.esc_html($template['note_label']).':</strong> '.nl2br(esc_html($data['admin_note'])).'</div>';}
 if(trim($template['button'])!==''){$colour=sanitize_hex_color(get_option('woocommerce_email_base_color','#3858e9'))?:'#3858e9';$body.='<p><a href="'.esc_url($url).'" style="display:inline-block;padding:12px 20px;background:'.esc_attr($colour).';color:#fff;text-decoration:none;border-radius:4px">'.esc_html(crmp_email_subject(array('subject'=>$template['button']),$data)).'</a></p>';}
 if($template['additional']!=='')$body.=wpautop(wp_kses_post(strtr($template['additional'],$replace)));
 return WC()->mailer()->wrap_message(crmp_email_subject(array('subject'=>$template['heading']),$data),'<div style="overflow-wrap:anywhere;word-break:break-word">'.$body.'</div>');
}
function crmp_email_transport(){
 if(!crmp_email_ready())throw new RuntimeException('WooCommerce email transport is unavailable.');
 if(!class_exists('CRMP_Notification_Email')){
  class CRMP_Notification_Email extends WC_Email{
   public function __construct(){$this->id='crmp_notification';$this->title='CardRaabta notification';$this->description='Managed in CardRaabta Settings.';parent::__construct();$this->email_type='html';}
  }
 }
 return new CRMP_Notification_Email();
}
add_filter('cron_schedules',static function($schedules){$schedules['crmp_five_minutes']=array('interval'=>300,'display'=>'Every five minutes');return $schedules;});
add_action('init',static function(){
 if(!function_exists('wc_get_order'))return;
 if(!get_option('crmp_email_started_at'))add_option('crmp_email_started_at',time(),'','no');
 if(!wp_next_scheduled('crmp_email_queue_sweep'))wp_schedule_event(time()+300,'crmp_five_minutes','crmp_email_queue_sweep');
});
add_action('crmp_email_queue_sweep',static function(){global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Recover unscheduled pending jobs in bounded batches without retrying uncertain mail sends.
 $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE status='pending' ORDER BY id LIMIT 100",$wpdb->prefix.'crmp_email_queue'));foreach($ids as $id)crmp_email_schedule('crmp_send_email_job',array((int)$id),time()+2);
 crmp_email_log_auto_cleanup();
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Retain minimal email delivery metadata and snapshots for at most 30 days.
 $wpdb->query($wpdb->prepare("UPDATE %i SET payload='{}',recipient='',last_error='',status=CASE WHEN status='accepted' THEN 'accepted' ELSE 'expired' END WHERE updated_at < %s AND recipient<>''",$wpdb->prefix.'crmp_email_queue',gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS)));
});

/** Remove queued proof/notes with the transaction, retaining only deduplication metadata. */
function crmp_email_purge_transaction($id){global $wpdb;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Permanent deletion must scrub email snapshots as well as proof revision history.
 $wpdb->query($wpdb->prepare("UPDATE %i SET payload='{}',recipient='',recipient_masked='',last_error='',status=CASE WHEN status='accepted' THEN 'accepted' ELSE 'skipped' END WHERE transaction_id=%d",$wpdb->prefix.'crmp_email_queue',$id));
}

function crmp_email_deactivate(){
 foreach(array('crmp_email_queue_sweep','crmp_send_email_job','crmp_missing_proof_reminder') as $hook)wp_unschedule_hook($hook);
 if(function_exists('as_unschedule_all_actions'))as_unschedule_all_actions('',array(),'crmp-emails');
}
