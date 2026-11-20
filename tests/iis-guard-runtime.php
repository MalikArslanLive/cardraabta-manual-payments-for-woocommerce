<?php
/** Focused native IIS/FastCGI test driver; never included in the release ZIP. */
define('ABSPATH',rtrim(getenv('CRMP_IIS_CORE'),'/\\').'/');
require_once ABSPATH.'wp-includes/version.php';
require_once ABSPATH.'wp-includes/plugin.php';
require_once ABSPATH.'wp-includes/class-wp-error.php';
require_once ABSPATH.'wp-includes/formatting.php';
// The guard function has no gateway dependency; this only permits loading its file.
// This test does not claim to run WordPress/WooCommerce checkout on Windows.
class WC_Payment_Gateway {}
require_once getenv('CRMP_IIS_GATEWAYS');
$root=getenv('CRMP_IIS_UPLOADS');$guards=array();
foreach(array('cardraabta-manual-payments-for-woocommerce') as $name){
 $directory=$root.'/'.$name;$result=crmp_protect_evidence_directory($directory);
 if($result!==true){http_response_code(500);exit('Guard creation failed');}
 clearstatcache();
 if(!is_file($directory.'/index.php')||filesize($directory.'/index.php')!==0){http_response_code(500);exit('Nonempty index');}
 $guards[$name]=array('index_bytes'=>filesize($directory.'/index.php'),'config_sha256'=>hash_file('sha256',$directory.'/web.config'));
}
header('Content-Type: application/json');
echo json_encode(array('php'=>PHP_VERSION,'wordpress_helpers'=>$wp_version,'sapi'=>PHP_SAPI,'server'=>$_SERVER['SERVER_SOFTWARE']??'','guards'=>$guards));
