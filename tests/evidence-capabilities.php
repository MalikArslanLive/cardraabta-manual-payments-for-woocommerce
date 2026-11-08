<?php
/** Verify the real WordPress Media Library/REST capability boundary. */
if(!defined('ABSPATH'))exit;
function ec_check($name,$ok){if(!$ok)throw new RuntimeException('FAIL EVIDENCE CAPABILITY '.$name);echo 'PASS EVIDENCE CAPABILITY '.$name."\n";}
wp_set_current_user(1);
$editor=wp_insert_user(array('user_login'=>'evidence-editor-'.wp_rand(),'user_pass'=>wp_generate_password(),'role'=>'editor'));
$author=wp_insert_user(array('user_login'=>'evidence-author-'.wp_rand(),'user_pass'=>wp_generate_password(),'role'=>'author'));
$private=wp_insert_attachment(array('post_title'=>'Private payment evidence capability test','post_status'=>'private','post_mime_type'=>'image/png'));
update_post_meta($private,'_crmp_private_evidence',1);
remove_filter('map_meta_cap','crmp_evidence_meta_caps',10);
try{
 ec_check('core editor permissions would allow reading another private attachment',user_can($editor,'read_post',$private));
 ec_check('core editor permissions would allow modifying evidence',user_can($editor,'edit_post',$private));
 ec_check('core editor permissions would allow deleting evidence',user_can($editor,'delete_post',$private));
}finally{add_filter('map_meta_cap','crmp_evidence_meta_caps',10,4);}
foreach(array('read_post','edit_post','delete_post') as $cap)ec_check('non-manager editor denied '.$cap,!user_can($editor,$cap,$private));
wp_update_post(array('ID'=>$private,'post_author'=>$author));
foreach(array('read_post','edit_post','delete_post') as $cap)ec_check('attachment authorship cannot bypass '.$cap,!user_can($author,$cap,$private));
wp_update_post(array('ID'=>$private,'post_title'=>'Renamed image','post_status'=>'inherit'));
ec_check('metadata protects renamed or accidentally public evidence',crmp_is_private_evidence($private)&&!user_can($editor,'edit_post',$private));
ec_check('renamed evidence never exposes a public attachment URL',!wp_get_attachment_url($private));
wp_set_current_user($editor);$response=apply_filters('rest_prepare_attachment',new WP_REST_Response(array()),get_post($private));ec_check('REST still denies renamed evidence',is_wp_error($response)&&$response->get_error_code()==='crmp_private_evidence');
wp_set_current_user(1);ec_check('authorized administrator retains original Media capability',user_can(1,'edit_post',$private));
$ordinary=wp_insert_attachment(array('post_title'=>'Ordinary public upload','post_status'=>'inherit','post_mime_type'=>'image/png'));
ec_check('ordinary uploads keep normal editor permissions',user_can($editor,'edit_post',$ordinary)&&user_can($editor,'delete_post',$ordinary));
$same_title=wp_insert_attachment(array('post_title'=>'Private payment evidence ordinary title','post_status'=>'private','post_mime_type'=>'image/png'));
ec_check('a matching title alone does not turn ordinary media into evidence',!crmp_is_private_evidence($same_title)&&user_can($editor,'delete_post',$same_title));
foreach(array($private,$ordinary,$same_title) as $id)wp_delete_attachment($id,true);
require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($editor);wp_delete_user($author);
echo "RESULT: MEDIA EVIDENCE CAPABILITY CHECKS PASSED\n";
