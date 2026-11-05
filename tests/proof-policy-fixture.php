<?php
/** Explicit configurable policy for tests exercising reference/note workflows. */
if (!defined('ABSPATH')) exit;
function crmp_test_flexible_policy(){
 $policy=array_merge(crmp_default_proof_policy(),array('reference_enabled'=>true,'screenshot_required'=>false,'note_enabled'=>true,'confirmation_enabled'=>true));
 update_option('crmp_require_checkout_proof','yes');
 update_option('crmp_proof_policy',$policy);update_option('crmp_order_proof_same','yes');
 return $policy;
}
