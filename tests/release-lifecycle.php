<?php
if(!defined('ABSPATH'))exit;
function rl_check($name,$ok){if(!$ok)throw new RuntimeException('FAIL RELEASE '.$name);echo 'PASS RELEASE '.$name."\n";}
wp_set_current_user(1);global $wpdb;
$valid=array('bank'=>array('enabled'=>'1','title'=>'Bank','account_title'=>'Demo Store','account'=>'TEST-IBAN','qr_id'=>999999999));rl_check('missing QR image rejected',is_wp_error(crmp_save_method_config($valid)));
$private=wp_insert_attachment(array('post_mime_type'=>'image/png','post_status'=>'private','post_title'=>'Private test evidence'));update_post_meta($private,'_crmp_private_evidence',1);$valid['bank']['qr_id']=$private;rl_check('private evidence cannot become public QR',is_wp_error(crmp_save_method_config($valid)));wp_delete_attachment($private,true);
$wpdb->insert(crmp_table(),array('order_id'=>999999,'method'=>'bank','amount'=>12,'currency'=>'PKR','transaction_ref'=>'UPGRADE-KEEP','screenshot_id'=>123,'status'=>'verified','note'=>'Preserve old review','submitted_at'=>current_time('mysql')));$receipt=(int)$wpdb->insert_id;
// Recreate the previous schema, then run the real idempotent migration.
$wpdb->query('ALTER TABLE '.$wpdb->prefix.'crmp_transactions DROP COLUMN payment_date, DROP COLUMN customer_note');
$wpdb->query('ALTER TABLE '.$wpdb->prefix.'crmp_proof_revisions DROP COLUMN method, DROP COLUMN payment_date, DROP COLUMN customer_note');
$wpdb->query('ALTER TABLE '.$wpdb->prefix.'crmp_email_queue DROP INDEX log_visible, DROP INDEX log_age, DROP COLUMN recipient_masked, DROP COLUMN is_test, DROP COLUMN log_hidden');update_option('crmp_db_version','8');
$install_errors=array();$capture_install_error=static function($query)use(&$install_errors,$wpdb){if($wpdb->last_error!=='')$install_errors[]=$wpdb->last_error;return $query;};
add_filter('query',$capture_install_error);crmp_install();crmp_install();remove_filter('query',$capture_install_error);
rl_check('repeated schema installation has no database errors',!$install_errors&&$wpdb->last_error==='');
$indexes=$wpdb->get_results('SHOW INDEX FROM '.$wpdb->prefix.'crmp_email_queue');$log_indexes=array();foreach($indexes as $index){if(in_array($index->Key_name,array('log_visible','log_age'),true))$log_indexes[$index->Key_name][]=$index->Column_name;}
rl_check('upgrade restores complete email log indexes',($log_indexes['log_visible']??array())===array('log_hidden','id')&&($log_indexes['log_age']??array())===array('log_hidden','created_at'));
$row=crmp_get_transaction($receipt);rl_check('upgrade retains prior paid receipt and evidence link',$row->status==='verified'&&$row->transaction_ref==='UPGRADE-KEEP'&&(int)$row->screenshot_id===123&&$row->note==='Preserve old review');rl_check('upgrade adds fields without invented dates',$row->payment_date===''&&property_exists($row,'customer_note')&&get_option('crmp_db_version')==='11');
$cols=$wpdb->get_col('SHOW COLUMNS FROM '.$wpdb->prefix.'crmp_email_queue');rl_check('upgrade adds email-log fields',!array_diff(array('recipient_masked','is_test','log_hidden'),$cols));
crmp_email_schedule('crmp_send_email_job',array(922337),time()+3600);crmp_email_deactivate();rl_check('deactivation clears WP email schedules',!wp_next_scheduled('crmp_email_queue_sweep')&&!wp_next_scheduled('crmp_send_email_job',array(922337)));if(function_exists('as_has_scheduled_action'))rl_check('deactivation clears Action Scheduler group',!as_has_scheduled_action('crmp_send_email_job',array(922337),'crmp-emails'));
$headers=get_plugin_data(CRMP_PATH.'cardraabta-manual-payments-for-woocommerce.php',false,false);rl_check('author, author URI and source URI correct',$headers['Author']==='Malik Arslan'&&$headers['AuthorURI']==='https://malikarslan.com/'&&$headers['PluginURI']==='https://github.com/MalikArslanLive/cardraabta-manual-payments-for-woocommerce'&&$headers['Version']===CRMP_VERSION);rl_check('uninstall guard included',is_file(CRMP_PATH.'uninstall.php')&&str_contains(file_get_contents(CRMP_PATH.'uninstall.php'),'WP_UNINSTALL_PLUGIN'));
echo "RESULT: UPGRADE/LIFECYCLE CHECKS PASSED\n";
