<?php
/** Actual uninstall-hook/activation round trip, on a disposable integration site. */
if(!defined('ABSPATH'))exit;
require_once ABSPATH.'wp-admin/includes/plugin.php';
$phase=$args[0]??'';wp_set_current_user(1);global $wpdb;
$slug='cardraabta-manual-payments-for-woocommerce/cardraabta-manual-payments-for-woocommerce.php';
if($phase==='setup'){
 if(!is_plugin_active($slug))throw new RuntimeException('Plugin must be active for retention setup.');
 $order=wc_create_order();$order->set_currency('PKR');$order->set_total('10.00');$order->set_payment_method('crmp_manual');$order->set_status('on-hold');$order->update_meta_data('_crmp_selected_method','bank');$order->save();
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
 $attachment=crmp_store_evidence_bytes($png,$order->get_id());if(is_wp_error($attachment))throw new RuntimeException($attachment->get_error_message());
 $saved=$wpdb->insert(crmp_table(),array('order_id'=>$order->get_id(),'method'=>'bank','amount'=>'10.00','currency'=>'PKR','transaction_ref'=>'RETENTION-'.wp_generate_uuid4(),'screenshot_id'=>$attachment,'status'=>'pending','submitted_at'=>current_time('mysql')));
 if(!$saved)throw new RuntimeException('Retention fixture could not be saved.');
 $id=(int)$wpdb->insert_id;$row=crmp_get_transaction($id);$path=get_attached_file($attachment);
 update_option('crmp_retention_test_fixture',array('id'=>$id,'attachment'=>$attachment,'path'=>$path,'file_hash'=>hash_file('sha256',$path),'plain_hash'=>hash('sha256',$png),'key_hash'=>hash('sha256',get_option('crmp_evidence_key')),'row_hash'=>hash('sha256',wp_json_encode($row)),'settings'=>get_option('crmp_method_config',array())),false);
 crmp_email_schedule('crmp_send_email_job',array(987654321),time()+3600);
 echo "PASS retention fixture stores encrypted evidence and existing settings\n";return;
}
$fixture=get_option('crmp_retention_test_fixture');if(!is_array($fixture))throw new RuntimeException('Missing retention fixture.');
$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',$wpdb->prefix.'crmp_transactions',$fixture['id']));
if(!$row||hash('sha256',wp_json_encode($row))!==$fixture['row_hash'])throw new RuntimeException('Uninstall/reinstall changed the retained ledger.');
if(hash('sha256',get_option('crmp_evidence_key',''))!==$fixture['key_hash'])throw new RuntimeException('Uninstall/reinstall changed the evidence key.');
if(get_option('crmp_method_config',array())!==$fixture['settings'])throw new RuntimeException('Uninstall/reinstall changed receiving settings.');
if(get_attached_file($fixture['attachment'])!==$fixture['path']||!is_file($fixture['path'])||hash_file('sha256',$fixture['path'])!==$fixture['file_hash'])throw new RuntimeException('Uninstall/reinstall removed or changed evidence.');
if($phase==='inactive'){
 if(is_plugin_active($slug))throw new RuntimeException('Uninstall did not deactivate the plugin.');
 if(wp_next_scheduled('crmp_email_queue_sweep')||wp_next_scheduled('crmp_send_email_job',array(987654321)))throw new RuntimeException('Deactivation retained WordPress email schedules.');
 if(function_exists('as_has_scheduled_action')&&as_has_scheduled_action('crmp_send_email_job',array(987654321),'crmp-emails'))throw new RuntimeException('Deactivation retained Action Scheduler work.');
 echo "PASS actual uninstall hook retains records/settings/key/files and cancels email schedules\n";return;
}
if($phase!=='reactivated'||!is_plugin_active($slug))throw new RuntimeException('Invalid retention phase or failed reactivation.');
$payload=crmp_read_evidence($fixture['path']);
if(is_wp_error($payload)||hash('sha256',$payload['bytes'])!==$fixture['plain_hash'])throw new RuntimeException('Reactivated plugin cannot decrypt retained evidence.');
delete_option('crmp_retention_test_fixture');
echo "PASS reactivation preserves the ledger and decrypts retained evidence with the original key\n";
