<?php
if(!defined('ABSPATH'))exit;
class CRMP_Block_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
 protected $name='crmp_manual';
 public function initialize(){$this->settings=get_option('woocommerce_crmp_manual_settings',array());}
 public function is_active(){return ($this->settings['enabled']??'no')==='yes' && count(crmp_enabled_methods())>0 && get_woocommerce_currency()==='PKR';}
 public function get_payment_method_script_handles(){wp_enqueue_style('crmp-checkout',CRMP_URL.'assets/checkout.css',array(),crmp_asset_version('assets/checkout.css'));wp_register_script('crmp-blocks',CRMP_URL.'assets/blocks.js',array('wc-blocks-registry','wc-settings','wp-element'),crmp_asset_version('assets/blocks.js'),true);return array('crmp-blocks');}
 public function get_payment_method_data(){$items=array();foreach(crmp_enabled_methods() as $slug=>$v)$items[]=array('slug'=>$slug,'title'=>crmp_method_label($slug),'account_title'=>$v['account_title']??'','account'=>$v['account']??'','instructions'=>$v['instructions']??'','qr_url'=>!empty($v['qr_id'])?wp_get_attachment_image_url($v['qr_id'],'medium'):'');return array('require_proof'=>crmp_checkout_proof_required(),'proof_policy'=>crmp_proof_policy(),'payment_date_max'=>current_datetime()->format('Y-m-d'),'max_upload_bytes'=>crmp_upload_limit(),'title'=>$this->settings['title']??'Manual Payment — Bank & Wallet Transfer','description'=>$this->settings['description']??'Select a payment method and follow the payment proof requirements shown below.','methods'=>$items);}
}
