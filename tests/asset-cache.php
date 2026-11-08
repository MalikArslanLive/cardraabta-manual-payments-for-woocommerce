<?php
/** Exercise two fresh WP processes with changed contents and unchanged file times. */
if(!defined('ABSPATH'))exit;
$file='assets/cache-check-'.wp_generate_uuid4().'.css';$path=CRMP_PATH.$file;$stamp=1700000000;
$command='eval '.escapeshellarg('echo crmp_asset_version('.var_export($file,true).');').' --path='.escapeshellarg(ABSPATH);
try{
 file_put_contents($path,'a{color:red}');touch($path,$stamp);
 $first=trim(WP_CLI::runcommand($command,array('return'=>'stdout','launch'=>true)));
 file_put_contents($path,'a{color:tan}');touch($path,$stamp);clearstatcache(true,$path);
 $second=trim(WP_CLI::runcommand($command,array('return'=>'stdout','launch'=>true)));
 if($first===$second||strpos($first,CRMP_VERSION.'.')!==0||strpos($second,CRMP_VERSION.'.')!==0)throw new RuntimeException('Same-version deployment reused a stale asset cache key.');
 echo "PASS asset version changes across requests with equal file size and file timestamp\n";
 if(crmp_asset_version('assets/checkout.js')!==crmp_asset_version('assets/checkout.js'))throw new RuntimeException('Asset cache key is unstable.');
 echo "RESULT: CONTENT-BASED ASSET CACHE CHECKS PASSED\n";
}finally{wp_delete_file($path);}
