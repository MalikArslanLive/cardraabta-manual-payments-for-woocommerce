<?php
/** Run in the isolated WordPress test installation, both classic orders and HPOS. */
global $closed_pass,$closed_fail;$closed_pass=0;$closed_fail=0;
function cp_check($label,$ok){global $closed_pass,$closed_fail;echo ($ok?'PASS ':'FAIL ').$label."\n";$ok?$closed_pass++:$closed_fail++;}
function cp_row($order,$status,$ref){global $wpdb;$wpdb->insert(crmp_table(),array('order_id'=>$order->get_id(),'method'=>'bank','amount'=>'10.00','currency'=>'PKR','status'=>$status,'transaction_ref'=>$ref,'submitted_at'=>current_time('mysql'),'note'=>'Preserve submitted history'));return (int)$wpdb->insert_id;}
function cp_get($id){global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',crmp_table(),$id));}
function cp_order(){ $o=wc_create_order();$o->set_currency('PKR');$o->set_total('10.00');$o->set_payment_method('crmp_manual');$o->update_meta_data('_crmp_selected_method','bank');$o->set_status('on-hold');$o->save();return $o;}
wp_set_current_user(1);global $wpdb;
$order=cp_order();$old=cp_row($order,'rejected','CP-OLD-'.wp_rand());$rejected=cp_row($order,'rejected','CP-REJECT-'.wp_rand());$pending=cp_row($order,'pending','CP-NEW-'.wp_rand());$pending2=cp_row($order,'pending','CP-NEW2-'.wp_rand());$before=cp_get($pending);$reject_before=cp_get($rejected);
$payment_calls=0;$counter=static function($id)use($order,&$payment_calls){if((int)$id===$order->get_id())$payment_calls++;};add_action('woocommerce_payment_complete',$counter);
cp_check('older rejected submission verifies successfully',crmp_review_transaction($old,'verified')===true);
cp_check('paid order completed exactly once',$payment_calls===1&&wc_get_order($order->get_id())->is_paid());
foreach(array($pending,$pending2) as $id){$r=cp_get($id);cp_check('pending sibling closes '.$id,$r->status==='closed'&&(int)$r->closed_by_transaction_id===$old&&!empty($r->closed_at));cp_check('closed sibling cannot be approved '.$id,is_wp_error(crmp_review_transaction($id,'verified')));cp_check('closed sibling cannot be rejected '.$id,is_wp_error(crmp_review_transaction($id,'rejected')));}
$after=cp_get($pending);cp_check('closure preserves proof and original note',$after->transaction_ref===$before->transaction_ref&&$after->submitted_at===$before->submitted_at&&$after->note===$before->note&&$after->reviewed_by===$before->reviewed_by&&$after->reviewed_at===$before->reviewed_at);
cp_check('rejected sibling closes and preserves its previous decision',cp_get($rejected)->status==='closed'&&cp_get($rejected)->closed_from_status==='rejected'&&cp_get($rejected)->note===$reject_before->note&&cp_get($rejected)->reviewed_by===$reject_before->reviewed_by&&cp_get($rejected)->reviewed_at===$reject_before->reviewed_at);
cp_check('only one verified transaction counted',(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE order_id=%d AND status='verified'",crmp_table(),$order->get_id()))===1);
crmp_close_pending_submissions($order->get_id());cp_check('repeat closure changes nothing',cp_get($pending)==$after&&$payment_calls===1);
$_POST=array('crmp_transaction_ref'=>'CP-PAID-'.wp_rand());cp_check('paid order refuses new checkout proof',is_wp_error(crmp_capture_checkout_evidence(wc_get_order($order->get_id()))));$_POST=array();
ob_start();crmp_render_payment_evidence(wc_get_order($order->get_id()));$customer_html=ob_get_clean();cp_check('customer sees verified payment instead of newer closed proof',str_contains($customer_html,'Payment Verified')&&str_contains($customer_html,cp_get($old)->transaction_ref)&&!str_contains($customer_html,'Submit payment proof'));
$_GET=array('status'=>'closed');ob_start();crmp_transactions_page();$admin_html=ob_get_clean();$_GET=array();cp_check('closed filter and source transaction shown',str_contains($admin_html,'Closed — Order already paid')&&str_contains($admin_html,'Paid through Transaction #'.$old));preg_match('/<tr id="crmp-detail-'.$pending.'".*?<\/tr>/s',$admin_html,$match);cp_check('closed submission has no review controls',!empty($match)&&!str_contains($match[0],'crmp-review-form'));
// Simulate an older installation or interruption that left another pending row behind.
$legacy=cp_row($order,'pending','CP-LEGACY-'.wp_rand());delete_option('crmp_closure_cursor');crmp_reconcile_paid_submissions();cp_check('existing paid pending submission is reconciled',cp_get($legacy)->status==='closed'&&(int)cp_get($legacy)->closed_by_transaction_id===$old);
// Newer proof approved after an older rejection: both ID directions must close consistently.
$newer_order=cp_order();$earlier=cp_row($newer_order,'rejected','CP-EARLIER-'.wp_rand());$newer=cp_row($newer_order,'pending','CP-NEWER-'.wp_rand());
$wpdb->update(crmp_table(),array('reviewed_by'=>1,'reviewed_at'=>'2026-11-03 01:02:03','note'=>'Original rejection reason'),array('id'=>$earlier));
cp_check('newer submission verifies successfully',crmp_review_transaction($newer,'verified')===true);
$earlier_row=cp_get($earlier);cp_check('older rejected submission closes through newer winner',$earlier_row->status==='closed'&&$earlier_row->closed_from_status==='rejected'&&(int)$earlier_row->closed_by_transaction_id===$newer);
cp_check('older rejection audit remains intact',$earlier_row->note==='Original rejection reason'&&(int)$earlier_row->reviewed_by===1&&$earlier_row->reviewed_at==='2026-11-03 01:02:03');
ob_start();crmp_admin_review_details($earlier_row);$history_html=ob_get_clean();cp_check('details explain previous rejected decision',str_contains($history_html,'Previous decision:')&&str_contains($history_html,'Rejected')&&str_contains($history_html,'Paid through Transaction #'.$newer));
$legacy_rejected=cp_row($newer_order,'rejected','CP-OLD-REJECTED-'.wp_rand());delete_option('crmp_closure_cursor');crmp_reconcile_paid_submissions();cp_check('existing paid rejected record is reconciled',cp_get($legacy_rejected)->status==='closed'&&cp_get($legacy_rejected)->closed_from_status==='rejected');
foreach(array($earlier,$newer,$legacy_rejected) as $id){$wpdb->delete(crmp_table(),array('id'=>$id));}$newer_order->delete(true);
$external=cp_order();$external_id=cp_row($external,'pending','CP-EXTERNAL-'.wp_rand());$external->payment_complete('EXTERNAL-'.wp_rand());crmp_close_pending_submissions($external->get_id());cp_check('external payment closes proof without claiming it was verified',cp_get($external_id)->status==='closed'&&(int)cp_get($external_id)->closed_by_transaction_id===0);
$deleted=cp_order();$deleted_id=cp_row($deleted,'pending','CP-DELETED-'.wp_rand());$deleted->payment_complete('DELETED-'.wp_rand());$deleted_order_id=$deleted->get_id();$deleted->delete(true);$deleted_before=cp_get($deleted_id);crmp_close_pending_submissions($deleted_order_id);cp_check('deleted paid order retains its previous ledger state',cp_get($deleted_id)==$deleted_before);
$unpaid=cp_order();$unpaid_id=cp_row($unpaid,'pending','CP-UNPAID-'.wp_rand());cp_check('unpaid order does not close pending proof',crmp_close_pending_submissions($unpaid->get_id())===0&&cp_get($unpaid_id)->status==='pending');
wp_set_current_user(0);cp_check('unauthorized closure is denied',is_wp_error(crmp_close_pending_submissions($order->get_id())));wp_set_current_user(1);
remove_action('woocommerce_payment_complete',$counter);cp_check('all sibling operations avoid repeat payment completion',$payment_calls===1);
foreach(array($old,$rejected,$pending,$pending2,$legacy,$external_id,$deleted_id,$unpaid_id) as $id){$wpdb->delete(crmp_table(),array('id'=>$id));}
foreach(array($order,$external,$unpaid) as $o){$o->delete(true);}
echo "RESULT: $closed_pass passed, $closed_fail failed\n";if($closed_fail)throw new RuntimeException('Closed submission regressions failed.');
