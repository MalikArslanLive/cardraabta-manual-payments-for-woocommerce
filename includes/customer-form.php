<?php
/** Customer proof UI stays inside WooCommerce's authenticated, theme-rendered order pages. */
if(!defined('ABSPATH'))exit;
function crmp_checkout_proof_required(){return get_option('crmp_require_checkout_proof','yes')!=='no';}
function crmp_default_proof_policy(){return array('screenshot_enabled'=>true,'screenshot_required'=>true,'reference_enabled'=>false,'reference_required'=>false,'date_enabled'=>true,'date_required'=>true,'note_enabled'=>false,'note_required'=>false,'confirmation_enabled'=>false,'confirmation_required'=>false);}
function crmp_validate_proof_policy($raw){
 $policy=array();foreach(crmp_default_proof_policy() as $key=>$value)$policy[$key]=in_array($raw[$key]??false,array(true,1,'1','yes'),true);
 foreach(array('screenshot','reference','date','note','confirmation') as $field)if(!$policy[$field.'_enabled'])$policy[$field.'_required']=false;
 if(!$policy['screenshot_enabled']&&!$policy['reference_enabled'])return new WP_Error('crmp_proof_policy','Enable at least one payment-proof option so customers can submit proof.');
 return $policy;
}
function crmp_proof_policy($context='checkout'){
 $option=$context==='order'&&get_option('crmp_order_proof_same','yes')!=='yes'?'crmp_order_proof_policy':'crmp_proof_policy';
 $raw=get_option($option,crmp_default_proof_policy());
 $policy=is_array($raw)?crmp_validate_proof_policy($raw):new WP_Error('crmp_proof_policy','Invalid configuration.');
 return is_wp_error($policy)?crmp_default_proof_policy():$policy;
}
function crmp_proof_input_error($input,$context='checkout'){
 foreach(array('crmp_transaction_ref','crmp_screenshot_data','crmp_payment_date','crmp_customer_note','crmp_proof_confirm') as $key)if(isset($input[$key])&&!is_scalar($input[$key]))return new WP_Error('crmp_input','Invalid payment proof data.');
 if(crmp_proof_policy($context)['reference_enabled']){
  $reference=trim(crmp_scalar($input['crmp_transaction_ref']??''));
  if(strlen($reference)>764||preg_match_all('/./us',$reference)>191)return new WP_Error('crmp_reference_length','Keep the transaction reference within 191 characters.');
 }
 return true;
}
function crmp_customer_reference($input,$context='checkout'){return crmp_proof_policy($context)['reference_enabled']?crmp_text_limit(trim(crmp_scalar($input['crmp_transaction_ref']??'')),191):'';}
function crmp_validate_proof_choice($reference,$has_screenshot,$context='checkout'){
 $policy=crmp_proof_policy($context);
 if($has_screenshot&&!$policy['screenshot_enabled'])return new WP_Error('crmp_screenshot_disabled','Screenshot uploads are disabled. Enter the transaction reference.');
 if($policy['screenshot_required']&&!$has_screenshot)return new WP_Error('crmp_screenshot_required','Upload your payment screenshot before submitting.');
 if($policy['reference_required']&&$reference==='')return new WP_Error('crmp_reference_required','Enter your transaction reference before submitting.');
 if($reference===''&&!$has_screenshot)return new WP_Error('crmp_proof_required','Enter a transaction reference or upload a payment screenshot before placing your order.');
 return true;
}
function crmp_customer_method_allowed($method,$current){return is_string($method)&&($method===$current||isset(crmp_enabled_methods()[$method]));}
// The third argument selects the after-order policy; retained for existing internal callers.
// allow_empty is selected by the server only for optional checkout with no proof data.
function crmp_customer_proof_details($order,$input,$order_context=false,$allow_empty=false){
 $valid=crmp_proof_input_error($input,$order_context?'order':'checkout');if(is_wp_error($valid))return $valid;
 $current=$order?(string)$order->get_meta('_crmp_selected_method'):'';$method=sanitize_key(crmp_scalar($input['crmp_proof_method']??$input['crmp_selected_method']??$current));
 if($method===''||!crmp_customer_method_allowed($method,$current))return new WP_Error('crmp_method','Select an available payment method.');
 $policy=crmp_proof_policy($order_context?'order':'checkout');
 if(!$allow_empty&&$policy['confirmation_required']&&crmp_scalar($input['crmp_proof_confirm']??'')!=='1')return new WP_Error('crmp_confirmation','Confirm that the payment details are accurate and that you have already made this payment.');
 $date=$policy['date_enabled']?crmp_scalar($input['crmp_payment_date']??''):'';
 if(!$allow_empty&&$policy['date_required']&&$date==='')return new WP_Error('crmp_date_required','Select the date you made this payment.');
 if($date!==''){$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,wp_timezone());if(!$parsed||(int)$parsed->format('Y')<1||$parsed->format('Y-m-d')!==$date||$date>current_datetime()->format('Y-m-d'))return new WP_Error('crmp_date','Enter a valid payment date that is not in the future.');}
 $note=$policy['note_enabled']&&isset($input['crmp_customer_note'])&&is_scalar($input['crmp_customer_note'])?sanitize_textarea_field(wp_unslash((string)$input['crmp_customer_note'])):'';
 if(!$allow_empty&&$policy['note_required']&&trim($note)==='')return new WP_Error('crmp_note_required','Enter the additional payment note.');
 if(strlen($note)>8000||preg_match_all('/./us',$note)>2000)return new WP_Error('crmp_note','Keep the additional note within 2,000 characters.');
 return array('deferred'=>$allow_empty,'method'=>$method,'payment_date'=>$date,'customer_note'=>$note,'proof_context'=>$order_context?'order':'checkout','field_policy'=>wp_json_encode($policy),'customer_confirmed'=>$policy['confirmation_enabled']?(crmp_scalar($input['crmp_proof_confirm']??'')==='1'?'yes':'no'):'');
}
function crmp_customer_save_method($order,$method){
 if($order->get_meta('_crmp_selected_method')!==$method){$order->update_meta_data('_crmp_selected_method',$method);$order->save();}
}
function crmp_customer_branding(){
 $base=sanitize_hex_color(get_option('woocommerce_email_base_color','#3858e9'))?:'#3858e9';
 wp_add_inline_style('crmp-checkout','.crmp-payment-panel,.crmp-checkout,.crmp-block{--crmp-brand-fallback:'.$base.';}');
}
function crmp_customer_brand_header($state){
 $label=array('verified'=>'Payment verified','rejected'=>'Payment rejected','pending'=>'Payment under review','awaiting'=>'Awaiting payment proof');
 return '<div class="crmp-proof-brand"><span class="crmp-proof-badge">'.esc_html($label[$state]??$label['awaiting']).'</span></div>';
}
function crmp_render_proof_inputs($checkout=false){
 $policy=crmp_proof_policy($checkout?'checkout':'order');$enforce=!$checkout||crmp_checkout_proof_required();
 $ids=$checkout?array('reference'=>'crmp_checkout_ref','date'=>'crmp_checkout_date','screenshot'=>'crmp_checkout_file','note'=>'crmp_checkout_note','confirmation'=>'crmp_checkout_confirm'):array('reference'=>'crmp-proof-reference','date'=>'crmp-payment-date','screenshot'=>'crmp-proof-file','note'=>'crmp-customer-note','confirmation'=>'crmp-proof-confirm');
 $columns=(int)$policy['reference_enabled']+(int)$policy['date_enabled'];
 if($columns)echo '<div class="crmp-payment-fields'.($columns===1?' crmp-fields-single':'').'">';
 if($policy['reference_enabled'])echo '<label for="'.esc_attr($ids['reference']).'">Transaction reference <small>('.($policy['reference_required']?($enforce?'required':'required with proof'):'optional').')</small><input type="text" id="'.esc_attr($ids['reference']).'" name="crmp_transaction_ref" maxlength="191" autocomplete="off" placeholder="Enter reference"'.($enforce&&$policy['reference_required']?' required':'').'></label>';
 if($policy['date_enabled'])echo '<label for="'.esc_attr($ids['date']).'">Payment date <small>('.($policy['date_required']?($enforce?'required':'required with proof'):'optional').')</small><input type="date" id="'.esc_attr($ids['date']).'" name="crmp_payment_date" max="'.esc_attr(current_datetime()->format('Y-m-d')).'"'.($enforce&&$policy['date_required']?' required':'').'></label>';
 if($columns)echo '</div>';
 if($policy['screenshot_enabled'])echo '<label class="crmp-proof-upload"'.($checkout?'':' for="'.esc_attr($ids['screenshot']).'"').'><strong>Payment screenshot <small>('.($policy['screenshot_required']?($enforce?'required':'required with proof'):'optional').')</small></strong><span class="crmp-upload-surface"><span class="crmp-upload-icon" aria-hidden="true">↑</span><span class="crmp-upload-name">Choose payment screenshot</span><small>JPG, PNG or WebP · Max '.absint(crmp_upload_limit()/1024/1024).' MB</small><input type="file"'.($checkout?'':' id="'.esc_attr($ids['screenshot']).'"').' name="crmp_screenshot" accept="image/jpeg,image/png,image/webp"'.($enforce&&$policy['screenshot_required']?' required':'').'></span></label><div class="crmp-upload-feedback" role="status" aria-live="polite" hidden><span class="crmp-upload-status"></span><progress class="crmp-upload-progress" max="100" aria-label="Screenshot preparation or submission progress" hidden></progress></div>';
 if($checkout&&$policy['screenshot_enabled'])echo '<input type="hidden" id="crmp-checkout-screenshot-data" name="crmp_screenshot_data" value=""><input type="hidden" id="crmp-checkout-screenshot-name" name="crmp_screenshot_name" value="">';
 if($policy['note_enabled'])echo '<label class="crmp-proof-label" for="'.esc_attr($ids['note']).'">Additional note <small>('.($policy['note_required']?($enforce?'required':'required with proof'):'optional').')</small></label><textarea id="'.esc_attr($ids['note']).'" name="crmp_customer_note" maxlength="2000" rows="3" placeholder="Add details that may help us verify your payment"'.($enforce&&$policy['note_required']?' required':'').'></textarea>';
 if($policy['confirmation_enabled'])echo '<label class="crmp-proof-confirm" for="'.esc_attr($ids['confirmation']).'"><input type="checkbox" id="'.esc_attr($ids['confirmation']).'" name="crmp_proof_confirm" value="1"'.($enforce&&$policy['confirmation_required']?' required':'').'><span>I confirm that these payment details are accurate and I have already made this payment.</span></label>';
 $hint=$policy['screenshot_required']&&$policy['reference_required']?'A screenshot and transaction reference are both required.':($policy['screenshot_required']?'Upload your payment screenshot.':($policy['reference_required']?'Enter your transaction reference.':'Provide a screenshot, transaction reference, or both.'));
 echo '<p class="crmp-hint">'.esc_html(($checkout&&!$enforce?'When submitting proof now: ':'').$hint).($checkout?($enforce?' Complete the payment proof before placing your order.':' You may leave all proof fields empty and submit proof later. When submitting now, complete the required proof fields.'):' A replacement is the complete current proof; include everything you want reviewed.').'</p>';
}
function crmp_customer_proof_fields($order,$latest,$replacing,$slug){
 $methods=crmp_enabled_methods();if(!isset($methods[$slug])){$configured=get_option('crmp_method_config',array());$methods[$slug]=$configured[$slug]??array();}
 echo '<input type="hidden" name="crmp_proof_ui" value="1"><h3>'.($replacing?'Update payment details':'Submit payment details').'</h3><label class="crmp-proof-label" for="crmp-proof-method">Payment method</label><select id="crmp-proof-method" name="crmp_proof_method" required>';
 foreach($methods as $method=>$config)echo '<option value="'.esc_attr($method).'" '.selected($slug,$method,false).'>'.esc_html(crmp_method_label($method)).'</option>';
 echo '</select>';crmp_customer_receiving_details($methods,$slug);
 crmp_render_proof_inputs(false);
 echo '<button class="button alt crmp-payment-submit" type="submit">'.($replacing?'Replace Payment Proof':'Submit Payment Proof').'</button><p class="crmp-payment-disclaimer">Do not make another payment if you have already paid. Your payment is confirmed after manual review.</p><p class="crmp-proof-feedback" role="status" aria-live="polite" hidden></p>';
}
/** Describe recorded settings, never the store's current settings. */
function crmp_recorded_field_settings($row){
 $policy=json_decode($row->field_policy??'',true);if(!is_array($policy))return 'Not recorded';
 $labels=array('screenshot'=>'Screenshot','reference'=>'Reference','date'=>'Date','note'=>'Customer note','confirmation'=>'Confirmation');$parts=array();
 foreach($labels as $key=>$label)$parts[]=$label.': '.(empty($policy[$key.'_enabled'])?'disabled':(!empty($policy[$key.'_required'])?'required':'optional'));
 return implode('; ',$parts);
}
function crmp_recorded_confirmation($row){
 if(empty($row->field_policy))return 'Not recorded';
 return ($row->customer_confirmed??'')==='yes'?'Confirmed':(($row->customer_confirmed??'')==='no'?'Not confirmed':'Not collected');
}
function crmp_recorded_proof_source($row){return array('checkout'=>'Checkout','order'=>'Order page')[$row->proof_context??'']??'Not recorded';}
function crmp_customer_detail_summary($row,$include_version=true){
 if(!current_user_can('manage_woocommerce'))return;
 if($include_version&&isset($row->proof_version))echo '<p><strong>Proof version:</strong> '.absint($row->proof_version).'</p>';
 if(!empty($row->method))echo '<p><strong>Submitted payment method:</strong> '.esc_html(crmp_method_label($row->method)).'</p>';
 if(!empty($row->payment_date))echo '<p><strong>Payment date (customer provided):</strong> '.esc_html($row->payment_date).'</p>';
 echo '<p><strong>Proof source:</strong> '.esc_html(crmp_recorded_proof_source($row)).'<br><strong>Customer confirmation:</strong> '.esc_html(crmp_recorded_confirmation($row)).'<br><strong>Fields at submission:</strong> '.esc_html(crmp_recorded_field_settings($row)).'</p>';
 if(!empty($row->customer_note))echo '<p><strong>Customer note:</strong><br>'.nl2br(esc_html($row->customer_note)).'</p>';
}

function crmp_customer_receiving_details($methods,$selected){
 echo '<div class="crmp-proof-receiving"><p class="crmp-hint">Receiving account details. If you have already paid, do not pay again; submit or update your proof below.</p>';
 foreach($methods as $method=>$config){
  echo '<div class="crmp-proof-method-info" data-proof-method="'.esc_attr($method).'"'.($method!==$selected?' hidden':'').'><h4>'.esc_html(crmp_method_label($method)).' receiving details</h4><div class="crmp-payment-account"><div><span>Account title</span><strong>'.esc_html($config['account_title']??'').'</strong><span>Account number / IBAN</span><strong class="crmp-account-number">'.esc_html($config['account']??'Contact the store').'</strong></div>';
  if(!empty($config['account']))echo '<button type="button" class="button crmp-copy" data-copy="'.esc_attr($config['account']).'">Copy account number</button>';
  echo '</div>';
  if(!empty($config['qr_id']))echo '<div class="crmp-payment-qr"><span>Scan QR code to pay</span>'.wp_get_attachment_image(absint($config['qr_id']),'medium',false,array('alt'=>crmp_method_label($method).' payment QR code')).'<small>Only scan to make a payment if you have not already paid.</small></div>';
  if(!empty($config['instructions']))echo '<p class="crmp-payment-instructions">'.esc_html($config['instructions']).'</p>';
  echo '</div>';
 }
 echo '</div>';
}

/** Treat any enabled-field input as an attempted submission, even in optional mode. */
function crmp_checkout_proof_details($input,$order=null,$files=array()){
 $valid=crmp_proof_input_error($input);if(is_wp_error($valid))return $valid;
 $policy=crmp_proof_policy();$reference=crmp_customer_reference($input);$image=crmp_scalar($input['crmp_screenshot_data']??'');
 $upload=$files['crmp_screenshot']??null;
 if($upload!==null&&!is_array($upload))return new WP_Error('crmp_upload','Invalid screenshot upload.');
 $has_upload=is_array($upload)&&((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE||!empty($upload['name']));
 $attempt=$reference!==''||$image!==''||$has_upload;
 foreach(array('date'=>'crmp_payment_date','note'=>'crmp_customer_note','confirmation'=>'crmp_proof_confirm') as $field=>$key)if($policy[$field.'_enabled']&&crmp_scalar($input[$key]??'')!=='')$attempt=true;
 if(!crmp_checkout_proof_required()&&!$attempt)return crmp_customer_proof_details($order,$input,false,true);
 $choice=crmp_validate_proof_choice($reference,$image!==''||$has_upload);if(is_wp_error($choice))return $choice;
 if($has_upload&&!crmp_validate_upload($upload))return new WP_Error('crmp_upload','Invalid screenshot. Use JPG, PNG or WebP within the configured size limit.');
 return crmp_customer_proof_details($order,$input);
}
