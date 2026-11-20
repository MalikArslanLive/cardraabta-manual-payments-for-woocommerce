<?php
require_once __DIR__."/proof-policy-fixture.php";crmp_test_flexible_policy();
function n11($name,$ok){if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
function o11(){ $o=wc_create_order();$o->set_currency('PKR');$o->set_total('12.00');$o->set_payment_method('crmp_manual');$o->update_meta_data('_crmp_selected_method','bank');$o->set_status('on-hold');$o->save();return $o; }
function r11($o,$status='pending'){global $wpdb;$wpdb->insert(crmp_table(),array('order_id'=>$o->get_id(),'method'=>'bank','amount'=>'12.00','currency'=>'PKR','status'=>$status,'transaction_ref'=>'RELEASE-'.wp_rand(),'submitted_at'=>current_time('mysql')));return (int)$wpdb->insert_id;}
wp_set_current_user(1);global $wpdb;update_option('crmp_method_config',array('bank'=>array('enabled'=>'yes','account'=>'PK001','title'=>'Bank','account_title'=>'Test Store')));
$o=o11();$id=r11($o);$other=o11();$rejected=r11($other,'rejected');
n11('pending cannot Trash',is_wp_error(crmp_manage_transaction($id,'trash'))&&empty(crmp_get_transaction($id)->trashed_at));
$out=crmp_bulk_manage(array($id,$rejected),'trash');n11('mixed bulk Trash processes eligible and reports pending skip',count($out['processed'])===1&&isset($out['skipped'][$id])&&empty(crmp_get_transaction($id)->trashed_at));
n11('common rejection reason is mandatory',is_wp_error(crmp_bulk_manage(array($id),'reject','',array($id=>1))));
$before=crmp_get_transaction($id);n11('same pending ID can receive new proof',crmp_replace_pending_proof($o,$id,1,'REPLACEMENT-'.wp_rand())===true);
$after=crmp_get_transaction($id);n11('replacement keeps ID and Pending and increments version',(int)$after->id===$id&&$after->status==='pending'&&(int)$after->proof_version===2);
n11('old proof archived',$wpdb->get_var($wpdb->prepare('SELECT transaction_ref FROM %i WHERE transaction_id=%d',$wpdb->prefix.'crmp_proof_revisions',$id))===$before->transaction_ref);
n11('stale customer replacement is blocked',is_wp_error(crmp_replace_pending_proof($o,$id,1,'STALE')));
n11('stale approval cannot pay order',is_wp_error(crmp_review_transaction($id,'verified','',1))&&!wc_get_order($o->get_id())->is_paid());
$out=crmp_bulk_manage(array($id),'reject','Unclear receipt',array($id=>1));n11('bulk reject refuses replaced proof',isset($out['skipped'][$id])&&crmp_get_transaction($id)->status==='pending');
$out=crmp_bulk_manage(array($id),'reject','Please upload a readable receipt',array($id=>2));n11('bulk rejection preserves unpaid order and saves reason',count($out['processed'])===1&&crmp_get_transaction($id)->status==='rejected'&&!wc_get_order($o->get_id())->is_paid()&&crmp_get_transaction($id)->note==='Please upload a readable receipt');
delete_transient('crmp_replace_'.$id);
$review=crmp_get_transaction($id);
n11('rejected proof reuses same transaction',crmp_replace_pending_proof($o,$id,2,'RETRY-REFERENCE')===true);
$retry=crmp_get_transaction($id);
n11('resubmission resets current review and returns Pending',$retry->status==='pending'&&(int)$retry->proof_version===3&&$retry->note===''&&!(int)$retry->reviewed_by&&$retry->reviewed_at===null);
$history=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE transaction_id=%d AND proof_version=2',$wpdb->prefix.'crmp_proof_revisions',$id));
n11('rejected proof history includes decision reason and reviewer',$history->review_status==='rejected'&&$history->review_note===$review->note&&(int)$history->reviewed_by===(int)$review->reviewed_by&&$history->reviewed_at===$review->reviewed_at);
n11('old rejection cannot approve new proof',is_wp_error(crmp_review_transaction($id,'verified','',2)));
n11('new proof can be rejected',crmp_review_transaction($id,'rejected','Second review',3)===true);
n11('rejected record can Trash',crmp_manage_transaction($id,'trash')===true);
$deleted=o11();$deleted_id=r11($deleted);$deleted->delete(true);n11('deleted order exception preserves pending history but permits Trash',crmp_manage_transaction($deleted_id,'trash')===true&&crmp_get_transaction($deleted_id)->status==='pending');
$legacy=o11();$legacy_id=r11($legacy);$wpdb->update(crmp_table(),array('trashed_at'=>current_time('mysql')),array('id'=>$legacy_id));n11('legacy trashed Pending cannot permanently delete',is_wp_error(crmp_manage_transaction($legacy_id,'delete')));
$reserved=o11();$reserved_id=r11($reserved,'verifying');n11('reserved review cannot replace',is_wp_error(crmp_replace_pending_proof($reserved,$reserved_id,1,'BLOCKED')));
$paid=o11();$paid_id=r11($paid);n11('current version can approve',crmp_review_transaction($paid_id,'verified','',1)===true);n11('paid proof replacement blocked',is_wp_error(crmp_replace_pending_proof($paid,$paid_id,1,'BLOCKED')));
$private=o11();$private_id=r11($private);$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');$tmp=wp_tempnam('revision');file_put_contents($tmp,$png);$image=crmp_store_upload(array('tmp_name'=>$tmp),$private->get_id(),true);$path=get_attached_file($image);$wpdb->update(crmp_table(),array('screenshot_id'=>$image),array('id'=>$private_id));
n11('replacement retains archived image',crmp_replace_pending_proof($private,$private_id,1,'NEW-PROOF')===true&&file_exists($path));crmp_review_transaction($private_id,'rejected','Old image',2);crmp_manage_transaction($private_id,'trash');crmp_manage_transaction($private_id,'delete');n11('permanent purge removes revision images and references',!file_exists($path)&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE transaction_id=%d',$wpdb->prefix.'crmp_proof_revisions',$private_id))===0);wp_delete_file($tmp);
ob_start();crmp_list_navigation('top',21,20,1,false,'pending','RELEASE');crmp_list_navigation('bottom',21,20,1,false,'pending','RELEASE');$html=ob_get_clean();n11('top and bottom show native counts and paging',substr_count($html,'displaying-num')===2&&substr_count($html,'next-page button')===2&&substr_count($html,'name="paged"')===1&&str_contains($html,'21 items'));
ob_start();crmp_list_navigation('top',5,20,1,false,'','');$html=ob_get_clean();n11('single page shows total only',str_contains($html,'5 items')&&!str_contains($html,'pagination-links'));
// Bulk approval uses the same payment completion and stale-proof protections as single review.
$a=o11();$ai=r11($a);$b=o11();$bi=r11($b,'rejected');
$bulk=crmp_bulk_manage(array($ai,$bi),'approve','Funds received. Thank you.',array($ai=>1,$bi=>1));
n11('bulk approves pending and rejected proof',count($bulk['processed'])===2&&wc_get_order($a->get_id())->is_paid()&&wc_get_order($b->get_id())->is_paid());
n11('previous rejection is retained when same proof is approved',(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE transaction_id=%d AND review_status='rejected'",$wpdb->prefix.'crmp_proof_revisions',$bi))===1);
n11('same approval note is applied to every processed record',crmp_get_transaction($ai)->note==='Funds received. Thank you.'&&crmp_get_transaction($bi)->note==='Funds received. Thank you.');
$bulk=crmp_bulk_manage(array($ai,$bi),'approve','Again',array($ai=>1,$bi=>1));n11('repeat bulk approval never processes paid entries',!$bulk['processed']&&count($bulk['skipped'])===2);
$dup=o11();$di=r11($dup);$dj=r11($dup,'rejected');$completed=0;$counter=static function($order_id)use($dup,&$completed){if((int)$order_id===$dup->get_id())$completed++;};add_action('woocommerce_payment_complete',$counter);
$bulk=crmp_bulk_manage(array($di,$dj),'approve','Checked',array($di=>1,$dj=>1));remove_action('woocommerce_payment_complete',$counter);
n11('same-order bulk selection completes payment once',count($bulk['processed'])===1&&count($bulk['skipped'])===1&&$completed===1&&crmp_get_transaction($dj)->status==='closed');
$changed=o11();$ci=r11($changed);crmp_replace_pending_proof($changed,$ci,1,'Updated proof '.wp_rand());$bulk=crmp_bulk_manage(array($ci),'approve','Old review',array($ci=>1));n11('bulk approval blocks changed proof',!$bulk['processed']&&!wc_get_order($changed->get_id())->is_paid());
$bulk=crmp_bulk_manage(array($ci),'approve','No version');n11('bulk approval requires proof version',!$bulk['processed']);
$bulk=crmp_bulk_manage(array($ci),'approve','',array($ci=>2));n11('bulk approval allows optional empty note',count($bulk['processed'])===1);
$gone=o11();$gi=r11($gone);$gone->delete(true);$bulk=crmp_bulk_manage(array($gi),'approve','',array($gi=>1));n11('bulk approval skips deleted orders preserving previous status',!$bulk['processed']&&crmp_get_transaction($gi)->status==='pending');
$trash=o11();$ti=r11($trash,'rejected');crmp_manage_transaction($ti,'trash');$bulk=crmp_bulk_manage(array($ti),'approve','',array($ti=>1));n11('bulk approval skips Trash',!$bulk['processed']&&!wc_get_order($trash->get_id())->is_paid());
$wrong=o11();$wi=r11($wrong);$wrong->set_total(99);$wrong->save();$bulk=crmp_bulk_manage(array($wi),'approve','',array($wi=>1));n11('bulk approval rechecks order amount',!$bulk['processed']&&!wc_get_order($wrong->get_id())->is_paid());
wp_set_current_user(0);n11('bulk approval requires manager permission',is_wp_error(crmp_bulk_manage(array($wi),'approve','',array($wi=>1))));wp_set_current_user(1);
$plain=crmp_review_return_url(123);parse_str(wp_parse_url($plain,PHP_URL_QUERY),$query);n11('review redirect never auto-populates search',!isset($query['s'])&&(int)$query['view']===123);
$manual=crmp_review_return_url(123,array('list_s'=>'MY SEARCH','list_status'=>'pending','list_paged'=>3,'return_url'=>'https://example.test/'));parse_str(wp_parse_url($manual,PHP_URL_QUERY),$query);n11('review preserves only manual list context',$query['s']==='MY SEARCH'&&$query['status']==='pending'&&(int)$query['paged']===3&&!isset($query['return_url']));
$bad=crmp_review_return_url(123,array('list_s'=>array('bad'),'list_status'=>'bad','list_paged'=>array('bad')));parse_str(wp_parse_url($bad,PHP_URL_QUERY),$query);n11('malformed context safely defaults to unfiltered list',!isset($query['s'])&&!isset($query['status'])&&!isset($query['paged']));
// Real browser fixtures. No live customer or payment data.
$customer=o11();$customer_id=r11($customer);$eligible=o11();$eligible_id=r11($eligible,'rejected');$fixtures=array('order'=>$customer->get_id(),'key'=>$customer->get_order_key(),'id'=>$customer_id,'eligible'=>$eligible_id);
$single=o11();$fixtures['single']=r11($single);
for($i=0;$i<24;$i++){r11(o11());}
$page=wp_insert_post(array('post_title'=>'Payment proof testing','post_content'=>'[crmp_release_panel]','post_status'=>'publish','post_type'=>'page'));$fixtures['page']=$page;
$checkout=wp_insert_post(array('post_title'=>'Checkout display testing','post_content'=>'[crmp_release_checkout]','post_status'=>'publish','post_type'=>'page'));$fixtures['checkout']=$checkout;update_option('crmp_release_fixture',$fixtures,false);file_put_contents('/tmp/crmp-release-fixture.json',wp_json_encode($fixtures));
echo "RESULT: Release regression checks passed\n";
