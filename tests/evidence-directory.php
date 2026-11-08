<?php
/** Run with wp eval-file on a disposable WordPress installation. */
if(!defined('ABSPATH'))exit;
$uploads=wp_upload_dir();
$private=trailingslashit($uploads['basedir']).'crmp-guard-test-'.wp_generate_uuid4();
if(!wp_mkdir_p($private))throw new RuntimeException('Cannot create guard fixture.');
try{
 $result=crmp_protect_evidence_directory($private);
 if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
 if(!is_file($private.'/index.php') || file_get_contents($private.'/index.php')!=='')throw new RuntimeException('Directory index must be empty, without executable PHP.');
 foreach(array('.htaccess','web.config') as $name)if(!is_file($private.'/'.$name) || filesize($private.'/'.$name)===0)throw new RuntimeException('Missing server access rule.');
 echo "PASS evidence guards create an empty index and server access rules\n";
 $custom=array('.htaccess'=>"# Administrator rule\nRequire all denied\n",'index.php'=>'<!-- Administrator index -->','web.config'=>'<!-- Administrator configuration -->');
 foreach($custom as $name=>$contents)file_put_contents($private.'/'.$name,$contents);
 $result=crmp_protect_evidence_directory($private);
 if(is_wp_error($result))throw new RuntimeException('Repeated guard setup failed.');
 foreach($custom as $name=>$contents)if(file_get_contents($private.'/'.$name)!==$contents)throw new RuntimeException('Administrator file was overwritten.');
 echo "PASS evidence guards preserve existing administrator files on repeated setup\n";
}finally{
 foreach(array('.htaccess','index.php','web.config') as $name)wp_delete_file($private.'/'.$name);
 rmdir($private);
}

// A custom per-site uploads base must control every new evidence path.
$custom_base=trailingslashit($uploads['basedir']).'crmp-upload-test-'.wp_generate_uuid4();
$custom_url=trailingslashit($uploads['baseurl']).basename($custom_base);
$filter=static function($dirs)use($custom_base,$custom_url){$dirs['basedir']=$custom_base;$dirs['baseurl']=$custom_url;$dirs['path']=$custom_base;$dirs['url']=$custom_url;$dirs['subdir']='';return $dirs;};
add_filter('upload_dir',$filter);
$id=0;$unsupported='';$plaintext='';$outside='';
try{
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
 $id=crmp_store_evidence_bytes($png,0);
 if(is_wp_error($id))throw new RuntimeException($id->get_error_message());
 $path=get_attached_file($id);$expected=$custom_base.'/cardraabta-manual-payments-for-woocommerce';
 if(dirname($path)!==$expected || !crmp_evidence_path_allowed($path))throw new RuntimeException('Evidence must use the plugin slug under the runtime uploads base.');
 if(file_get_contents($expected.'/index.php')!=='')throw new RuntimeException('Upload generated a nonempty index.');
 if(substr(file_get_contents($path),0,6)!=="CRMP1\n")throw new RuntimeException('Evidence is not encrypted at rest.');
 $read=crmp_read_evidence($path);
 if(is_wp_error($read) || $read['bytes']!==$png)throw new RuntimeException('Evidence decryption failed.');
 echo "PASS slug directory uses filtered per-site uploads base and encrypted bytes round-trip\n";
 wp_mkdir_p($custom_base.'/crmp-private');$unsupported=$custom_base.'/crmp-private/unsupported.crmp';copy($path,$unsupported);
 if(crmp_evidence_path_allowed($unsupported) || !is_wp_error(crmp_read_evidence($unsupported)))throw new RuntimeException('Unsupported evidence directory was accepted.');
 $plaintext=$expected.'/unsupported.png';file_put_contents($plaintext,$png);
 if(!is_wp_error(crmp_read_evidence($plaintext)))throw new RuntimeException('Plaintext evidence was accepted.');
 $renamed=$expected.'/plaintext.crmp';file_put_contents($renamed,$png);
 try{if(!is_wp_error(crmp_read_evidence($renamed)))throw new RuntimeException('Renamed plaintext evidence was accepted.');}finally{wp_delete_file($renamed);}
 if(function_exists('crmp_migrate_legacy_evidence'))throw new RuntimeException('Removed evidence migration remains registered.');
 $outside=$custom_base.'/outside.crmp';copy($path,$outside);
 if(crmp_evidence_path_allowed($outside) || !is_wp_error(crmp_read_evidence($outside)) || crmp_evidence_path_allowed($expected.'/../outside.crmp'))throw new RuntimeException('An outside file bypassed the evidence path boundary.');
 echo "PASS unsupported directory, plaintext and outside/traversal paths are rejected; no migration remains\n";
}finally{
 if($id && !is_wp_error($id))wp_delete_attachment($id,true);
 if($unsupported)wp_delete_file($unsupported);
 if($plaintext)wp_delete_file($plaintext);
 if($outside)wp_delete_file($outside);
 foreach(array('.htaccess','index.php','web.config') as $name)wp_delete_file($custom_base.'/cardraabta-manual-payments-for-woocommerce/'.$name);
 if(is_dir($custom_base.'/cardraabta-manual-payments-for-woocommerce'))rmdir($custom_base.'/cardraabta-manual-payments-for-woocommerce');
 if(is_dir($custom_base.'/crmp-private'))rmdir($custom_base.'/crmp-private');
 if(is_dir($custom_base))rmdir($custom_base);
 remove_filter('upload_dir',$filter);
}
