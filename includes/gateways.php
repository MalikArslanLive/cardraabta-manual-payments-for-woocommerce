<?php
if(!defined('ABSPATH'))exit;
class CRMP_Gateway_Manual extends WC_Payment_Gateway {
 public function __construct(){
  $this->id='crmp_manual';$this->method_title='CardRaabta Manual Payments for WooCommerce';$this->method_description='One gateway for Pakistani wallet, bank and Raast transfers. Customers submit payment proof at checkout or later, as configured.';$this->has_fields=true;$this->supports=array('products');$this->init_form_fields();$this->init_settings();
  $this->enabled=$this->get_option('enabled','no');$this->title=$this->get_option('title','Manual Payment — Bank & Wallet Transfer');$this->description=$this->get_option('description','Select a wallet or bank method and follow the payment proof requirements shown below.');
  add_action('woocommerce_update_options_payment_gateways_'.$this->id,array($this,'process_admin_options'));
 }
 public function init_form_fields(){$this->form_fields=array('enabled'=>array('title'=>'Enable / Disable','type'=>'checkbox','label'=>'Enable CardRaabta Manual Payments for WooCommerce','default'=>'no'),'title'=>array('title'=>'Checkout title','type'=>'text','default'=>'Manual Payment — Bank & Wallet Transfer'),'description'=>array('title'=>'Checkout description','type'=>'textarea','default'=>'Select your payment method and follow the payment proof requirements shown below.'));}
 public function is_available(){return parent::is_available() && get_woocommerce_currency()==='PKR' && count(crmp_enabled_methods())>0;}
 public function payment_fields(){
  wp_enqueue_style('crmp-checkout',CRMP_URL.'assets/checkout.css',array(),crmp_asset_version('assets/checkout.css'));wp_enqueue_script('crmp-checkout',CRMP_URL.'assets/checkout.js',array(),crmp_asset_version('assets/checkout.js'),true);wp_localize_script('crmp-checkout','crmpCheckout',array('maxUploadBytes'=>crmp_upload_limit(),'proofPolicy'=>crmp_proof_policy(),'requireProof'=>crmp_checkout_proof_required()));
  echo '<div class="crmp-checkout" data-require-proof="'.(crmp_checkout_proof_required()?'1':'0').'" data-proof-policy="'.esc_attr(wp_json_encode(crmp_proof_policy())).'"><p>'.esc_html($this->description).'</p><label for="crmp_method"><strong>Select payment method</strong></label><select id="crmp_method" name="crmp_selected_method" required><option value="">Select method</option>';
  foreach(crmp_enabled_methods() as $slug=>$v)echo '<option value="'.esc_attr($slug).'">'.esc_html(crmp_method_label($slug)).'</option>';
  echo '</select><div class="crmp-method-preview" aria-live="polite" hidden>';
  foreach(crmp_enabled_methods() as $slug=>$v){
   echo '<div class="crmp-method-info" data-method="'.esc_attr($slug).'" hidden><div class="crmp-receiving-field"><span class="crmp-receiving-label">Account title</span><strong>'.esc_html($v['account_title']??'').'</strong></div><div class="crmp-receiving-field"><span class="crmp-receiving-label">Account number / IBAN</span><div class="crmp-account-copy"><code>'.esc_html($v['account']).'</code> <button type="button" class="button crmp-copy" data-copy="'.esc_attr($v['account']).'">Copy account number</button></div></div>';
   if(!empty($v['qr_id']))echo '<div class="crmp-qr"><span class="crmp-receiving-label">Scan QR code to pay</span>'.wp_get_attachment_image(absint($v['qr_id']),'medium').'</div>';
   echo '<p>'.esc_html($v['instructions']??'').'</p></div>';
  }
  echo '</div><div class="crmp-checkout-proof"><h3>Payment proof <small>'.(crmp_checkout_proof_required()?'(required before placing the order)':'(optional now; submit later)').'</small></h3>';
  crmp_render_proof_inputs(true);
  echo '</div><p class="crmp-checkout-disclaimer">Already paid? Do not pay again. All payment proof is reviewed manually.</p></div>';
 }
 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates checkout/pay-for-order nonces before invoking this gateway callback.
 public function validate_fields(){$slug=sanitize_key(wp_unslash($_POST['crmp_selected_method']??''));if(!isset(crmp_enabled_methods()[$slug])){wc_add_notice('Please select a configured payment method.','error');return false;}$details=crmp_checkout_proof_details($_POST,null,$_FILES);if(is_wp_error($details)){wc_add_notice($details->get_error_message(),'error');return false;}return true;}
 public function process_payment($order_id){
  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce authenticates the checkout or pay-for-order request before process_payment.
  $order=wc_get_order($order_id);if(!$order || $order->is_paid() || $order->get_date_paid() || !in_array($order->get_status(),array('pending','failed','on-hold'),true) || $order->get_currency()!=='PKR')return array('result'=>'failure');$slug=sanitize_key(wp_unslash($_POST['crmp_selected_method']??''));if(!isset(crmp_enabled_methods()[$slug])){wc_add_notice('Select a payment method.','error');return array('result'=>'failure');}
  // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce authorizes checkout; shared helper validates all proof details.
  $details=crmp_checkout_proof_details($_POST,null,$_FILES);if(is_wp_error($details)){wc_add_notice($details->get_error_message(),'error');return array('result'=>'failure');}
  crmp_acquire_order_lock($order_id);$order=wc_get_order($order_id);
  if(!$order || $order->is_paid() || $order->get_date_paid() || !in_array($order->get_status(),array('pending','failed','on-hold'),true) || $order->get_currency()!=='PKR' || crmp_paid_transaction_id($order_id))return array('result'=>'failure');
  global $wpdb;
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Keep existing proof tied to its original receiving method under the order lock.
  $other_method=$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE order_id=%d AND method<>%s LIMIT 1',crmp_table(),$order_id,$slug));
  if($other_method){wc_add_notice('This order already has payment proof for another receiving method. Keep the original method or contact the store to reconcile it.','error');return array('result'=>'failure');}
  $order->update_meta_data('_crmp_selected_method',$slug);$order->update_status('on-hold','Awaiting manual transfer and customer payment proof via '.crmp_method_label($slug));$order->save();$saved=crmp_capture_checkout_evidence($order);
  if(is_wp_error($saved)){
   $order->add_order_note('Manual payment proof could not be saved at checkout: '.$saved->get_error_message().'. Customer may submit proof on the order page.');
   wc_add_notice('Payment proof could not be saved. '.$saved->get_error_message().' Your order remains unpaid. Retry submitting proof or contact the store; do not transfer money again.','error');
   return array('result'=>'failure');
  }
  if(WC()->cart)WC()->cart->empty_cart();return array('result'=>'success','redirect'=>$this->get_return_url($order));
 }
}
function crmp_validate_upload($file){
 if(!is_array($file) || !isset($file['tmp_name'],$file['name'],$file['size']) || !is_string($file['tmp_name']) || !is_string($file['name']) || !is_scalar($file['size']) || !is_uploaded_file($file['tmp_name']) || !empty($file['error'])){wc_add_notice('Screenshot upload failed.','error');return false;}
 if((int)$file['size']<1 || (int)$file['size']>crmp_upload_limit()){wc_add_notice('Screenshot is too large.','error');return false;}
 $info=wp_check_filetype_and_ext($file['tmp_name'],$file['name']);if(!in_array($info['type']??'',array('image/png','image/jpeg','image/webp'),true)){wc_add_notice('Only JPG, PNG or WebP screenshots are allowed.','error');return false;}return true;
}
function crmp_validate_data_uri($data){if(!is_string($data) || strlen($data)>11*1024*1024){wc_add_notice('Screenshot too large.','error');return false;}return (bool)preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#',$data);}
function crmp_store_data_uri($data,$order_id){
 if(!crmp_validate_data_uri($data))return new WP_Error('crmp_image','Invalid screenshot.');
 $bytes=base64_decode(substr($data,strpos($data,',')+1),true);
 if($bytes===false || strlen($bytes)>crmp_upload_limit())return new WP_Error('crmp_size','Screenshot exceeds size limit.');
 $type=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
 if(!in_array($type,array('image/png','image/jpeg','image/webp'),true))return new WP_Error('crmp_type','Invalid screenshot image.');
 return crmp_store_evidence_bytes($bytes,$order_id);
}
/** Restrict WordPress upload handling to a private subdirectory for this call only. */
function crmp_private_upload_dir($dirs){
 $dirs['subdir']='/crmp-private';
 $dirs['path']=trailingslashit($dirs['basedir']).'crmp-private';
 $dirs['url']=trailingslashit($dirs['baseurl']).'crmp-private';
 return $dirs;
}
/** Defense in depth for Apache/OpenLiteSpeed and IIS; Nginx needs a server rule. */
function crmp_protect_evidence_directory($private){
 $guards=array(
  '.htaccess'=>"RewriteEngine On\nRewriteRule ^ - [F,L]\n",
  'index.php'=>"<?php\nhttp_response_code(403);\nexit;\n",
  'web.config'=>"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n"
 );
 foreach($guards as $name=>$contents){
  $path=trailingslashit($private).$name;
  if(is_file($path))continue; // Preserve administrator-maintained access rules.
  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive local creation preserves administrator rules; WP_Filesystem has no exclusive-create primitive.
  $handle=@fopen($path,'x');
  if(!$handle){if(is_file($path))continue;return new WP_Error('crmp_protection','Could not prepare private evidence access rules. Contact the store.');}
  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Write and close the exclusively-created local guard handle without remote filesystem credentials.
  $saved=fwrite($handle,$contents);fclose($handle);
  if($saved!==strlen($contents)){wp_delete_file($path);return new WP_Error('crmp_protection','Could not save private evidence access rules. Contact the store.');}
 }
 return true;
}
function crmp_store_upload($file,$order_id,$sideload=false){
 if(!$sideload && !crmp_validate_upload($file))return new WP_Error('crmp_upload','Invalid upload.');
 if(!is_array($file) || !is_string($file['tmp_name']??null) || !is_file($file['tmp_name']) || filesize($file['tmp_name'])>crmp_upload_limit())return new WP_Error('crmp_size','Invalid image size.');
 $bytes=file_get_contents($file['tmp_name']);
 return crmp_store_evidence_bytes($bytes,$order_id);
}
/** Validate and encrypt screenshot bytes before writing the evidence file. */
function crmp_store_evidence_bytes($bytes,$order_id){
 if(!is_string($bytes) || strlen($bytes)<1 || strlen($bytes)>crmp_upload_limit())return new WP_Error('crmp_size','Invalid image size.');
 $image=@getimagesizefromstring($bytes);
 if(!$image || !in_array($image['mime'],array('image/jpeg','image/png','image/webp'),true) || $image[0]>12000 || $image[1]>12000 || $image[0]*$image[1]>40000000)return new WP_Error('crmp_image','Invalid image content or dimensions.');
 if(!function_exists('openssl_encrypt'))return new WP_Error('crmp_crypto','OpenSSL is required for private evidence storage.');
 try{$iv=random_bytes(12);$key=crmp_evidence_key();}catch(Throwable $error){return new WP_Error('crmp_crypto','Private evidence protection is unavailable. Contact the store before retrying.');}
 $tag='';$cipher=openssl_encrypt($bytes,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'crmp-evidence-v1');
 if($cipher===false)return new WP_Error('crmp_crypto','Unable to protect payment evidence.');
 $dirs=wp_upload_dir();$private=trailingslashit($dirs['basedir']).'crmp-private';
 if(!empty($dirs['error']) || !wp_mkdir_p($private))return new WP_Error('crmp_dir','Evidence storage unavailable.');
 $protected=crmp_protect_evidence_directory($private);if(is_wp_error($protected))return $protected;
 $path=$private.'/evidence-'.bin2hex(random_bytes(24)).'.crmp';
 $payload="CRMP1\n".base64_encode($iv.$tag.$cipher);
 if(file_put_contents($path,$payload,LOCK_EX)!==strlen($payload)){wp_delete_file($path);return new WP_Error('crmp_write','Could not save evidence.');}
 // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private local upload requires restrictive permissions; credentials-based WP_Filesystem cannot run at guest checkout.
 chmod($path,0600);
 $id=wp_insert_attachment(array('post_mime_type'=>$image['mime'],'post_title'=>'Private payment evidence '.absint($order_id),'post_status'=>'private','post_parent'=>0),$path,0,true);
 if(is_wp_error($id) || !$id){wp_delete_file($path);return new WP_Error('crmp_attachment','Could not register payment evidence.');}
 update_post_meta($id,'_crmp_private_evidence',1);
 return $id;
}

function crmp_gateway_classes(){return array(CRMP_Gateway_Manual::class);}
