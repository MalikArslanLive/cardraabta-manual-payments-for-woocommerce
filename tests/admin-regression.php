<?php
// Run only in the isolated WordPress test installation.
$GLOBALS['passed']=0;$GLOBALS['failed']=0;
function ar_check($label,$ok){global $passed,$failed;echo ($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;}
function ar_render($callback){ob_start();$callback();return ob_get_clean();}
wp_set_current_user(1);
do_action('admin_menu');global $menu;$menu_labels=array_map(static function($item){return is_array($item)?(string)$item[0]:'';},(array)$menu);ar_check('admin sidebar uses clear CardRaabta Payments label',in_array('CardRaabta Payments',$menu_labels,true)&&!in_array('CardRaabta Manual',$menu_labels,true));
$original=get_option('crmp_method_config',array());
$valid=array('bank'=>array('enabled'=>'1','title'=>'Bank','account_title'=>'Example Store','account'=>'PK001','instructions'=>'Transfer funds.'));
$result=crmp_save_method_config($valid);
ar_check('valid receiving account saved',!is_wp_error($result)&&get_option('crmp_method_config')['bank']['account']==='PK001');
$baseline=get_option('crmp_method_config');
foreach(crmp_methods() as $slug=>$method){
 $bad=$valid;$bad[$slug]=array('enabled'=>'1','title'=>$method[0],'account_title'=>'','account'=>'');
 $result=crmp_save_method_config($bad);
 ar_check($slug.' incomplete enable rejected',is_wp_error($result)&&str_contains($result->get_error_message(),$method[0]));
 ar_check($slug.' invalid form changes nothing',get_option('crmp_method_config')===$baseline);
}
foreach(array('title','account_title','account') as $field){$bad=$valid;$bad['bank'][$field]='   ';ar_check('required '.$field.' rejected',is_wp_error(crmp_save_method_config($bad)));}
$bad=$valid;$bad['bank']['account']=array('malformed');ar_check('array field rejected without fatal error',is_wp_error(crmp_save_method_config($bad)));
$disabled=array('custom'=>array('title'=>'','account_title'=>'','account'=>''));ar_check('disabled empty methods allowed',!is_wp_error(crmp_save_method_config($disabled)));
$valid['unknown']=array('enabled'=>'1','account'=>'bad');$result=crmp_save_method_config($valid);ar_check('unknown methods ignored',!isset($result['unknown']));
wp_set_current_user(0);ar_check('unauthorized save denied',is_wp_error(crmp_save_method_config($valid)));ar_check('unauthorized admin screen empty',ar_render('crmp_admin_page')==='');wp_set_current_user(1);
$_POST=array('crmp_save'=>'1','_wpnonce'=>wp_create_nonce('crmp_save_methods'),'methods'=>array('bank'=>array('enabled'=>'1','title'=>'Entered bank','account_title'=>'Store','account'=>'')));
$_SERVER['REQUEST_METHOD']='POST';$_REQUEST=$_POST;$baseline=get_option('crmp_method_config');$html=ar_render('crmp_admin_page');ar_check('form shows validation error',str_contains($html,'Payment methods were not saved.'));ar_check('form retains submitted name',str_contains($html,'value="Entered bank"'));ar_check('invalid form preserves stored configuration',get_option('crmp_method_config')===$baseline);$_POST=array();$_REQUEST=array();
$user=get_userdata(1);$row=(object)array('reviewed_by'=>1,'reviewed_at'=>'2026-11-03 00:00:00','note'=>"Please resubmit\nUnreadable receipt");
$html=ar_render(function()use($row){crmp_admin_review_details($row);});ar_check('reviewer name and ID shown',str_contains($html,esc_html($user->display_name).' (User #1)'));ar_check('saved note and line breaks shown',str_contains($html,"Please resubmit<br />\nUnreadable receipt"));ar_check('review timestamp shown',str_contains($html,$row->reviewed_at));
$row->note='';$html=ar_render(function()use($row){crmp_admin_review_details($row);});ar_check('missing note explicit fallback',str_contains($html,'No notes were added.'));
$row->note='<script>alert(1)</script>';$html=ar_render(function()use($row){crmp_admin_review_details($row);});ar_check('stored note HTML escaped',!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'));
ar_check('missing reviewer fallback',crmp_reviewer_label(0)==='Reviewer not recorded');ar_check('deleted reviewer ID retained',crmp_reviewer_label(999999999)==='Deleted user (User #999999999)');
global $wpdb;
$wpdb->insert(crmp_table(),array('order_id'=>999999999,'method'=>'bank','amount'=>'10','currency'=>'PKR','status'=>'rejected','submitted_at'=>current_time('mysql'),'reviewed_at'=>current_time('mysql'),'reviewed_by'=>1,'note'=>'Existing rejection reason'));
$id=(int)$wpdb->insert_id;$_GET=array();$html=ar_render('crmp_transactions_page');
ar_check('detail row is immediately after its transaction',str_contains($html,'</button></td></tr><tr id="crmp-detail-'.$id.'" class="crmp-detail-row" hidden><td colspan="10">'));
ar_check('view is an accessible non-submit toggle',str_contains($html,'class="button crmp-toggle-details" aria-expanded="false" aria-controls="crmp-detail-'.$id.'">View Details</button>'));
ar_check('deleted order retains review details',str_contains($html,'Order Deleted (#999999999)')&&str_contains($html,'Existing rejection reason'));
preg_match('/<tr id="crmp-detail-'.$id.'".*?<\/tr>/s',$html,$match);$deleted_html=$match[0]??'';
ar_check('deleted order explains read-only history',str_contains($deleted_html,'Order deleted.')&&str_contains($deleted_html,'previous status'));
ar_check('deleted order has no review form or action buttons',!str_contains($deleted_html,'crmp-review-form')&&!str_contains($deleted_html,'Mark as Paid')&&!str_contains($deleted_html,'Reject Payment'));
ar_check('transaction page enqueues cache-busted admin script',wp_script_is('crmp-transactions-admin','enqueued')&&wp_scripts()->registered['crmp-transactions-admin']->ver===crmp_asset_version('assets/admin.js'));
ar_check('table and detail show reviewer',substr_count($html,esc_html($user->display_name).' (User #1)')>=2);
$_GET=array('view'=>$id);$html=ar_render('crmp_transactions_page');ar_check('review redirect reopens same row',str_contains($html,'<tr id="crmp-detail-'.$id.'" class="crmp-detail-row"><td colspan="10">')&&str_contains($html,'aria-expanded="true" aria-controls="crmp-detail-'.$id.'">Hide Details'));
$_GET=array();
$order=wc_create_order();$order->set_status('on-hold');$order->save();
$wpdb->insert(crmp_table(),array('order_id'=>$order->get_id(),'method'=>'bank','amount'=>'10','currency'=>'PKR','status'=>'pending','submitted_at'=>current_time('mysql')));$live_id=(int)$wpdb->insert_id;
$html=ar_render('crmp_transactions_page');preg_match('/<tr id="crmp-detail-'.$live_id.'".*?<\/tr>/s',$html,$match);$live_html=$match[0]??'';
ar_check('existing order retains inline confirmation and review actions',str_contains($live_html,'class="crmp-inline-confirm" hidden')&&str_contains($live_html,'Mark as Paid')&&str_contains($live_html,'Reject Payment')&&str_contains($live_html,'Yes, continue')&&str_contains($live_html,'Cancel'));
file_put_contents('/tmp/crmp-ui-fixture.json',wp_json_encode(array('html'=>$html,'live_id'=>$live_id,'deleted_id'=>$id)));
$order->delete(false);$html=ar_render('crmp_transactions_page');preg_match('/<tr id="crmp-detail-'.$live_id.'".*?<\/tr>/s',$html,$match);$trash_html=$match[0]??'';
ar_check('trashed order is also read-only',str_contains($trash_html,'Order deleted.')&&!str_contains($trash_html,'crmp-review-form'));
$order->delete(true);$wpdb->delete(crmp_table(),array('id'=>$live_id));
$wpdb->update(crmp_table(),array('status'=>'verified','note'=>''),array('id'=>$id));$html=ar_render('crmp_transactions_page');ar_check('approved detail shows empty note fallback',str_contains($html,'No notes were added.'));
$_GET=array();$wpdb->delete(crmp_table(),array('id'=>$id));update_option('crmp_method_config',$original,false);
echo "RESULT: ".$GLOBALS['passed']." passed, ".$GLOBALS['failed']." failed\n";if($GLOBALS['failed'])throw new RuntimeException('Admin regressions failed.');
