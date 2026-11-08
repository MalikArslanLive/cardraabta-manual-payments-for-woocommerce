<?php
/** Run with wp eval-file on a MySQL/MariaDB test database only. */
global $wpdb;
if(get_class($wpdb)==='WP_SQLite_DB'){throw new RuntimeException('Named lock tests require MySQL/MariaDB.');}
crmp_acquire_order_lock(922337);
$connection=mysqli_init();
$host=DB_HOST;$port=3306;
if(preg_match('/^([^:]+):(\d+)$/',$host,$parts)){$host=$parts[1];$port=(int)$parts[2];}
$connection->real_connect($host,DB_USER,DB_PASSWORD,DB_NAME,$port);
$name='crmp_'.md5($wpdb->prefix).'_922337';
$statement=$connection->prepare('SELECT GET_LOCK(?,0)');$statement->bind_param('s',$name);$statement->execute();$statement->bind_result($result);$statement->fetch();$statement->close();
if((string)$result!=='0')throw new RuntimeException('Concurrent order lock was not excluded.');
echo "PASS concurrent order connection excluded\n";
crmp_acquire_reference_lock('bank','DUPLICATE-REF');
$name=substr('crmp_ref_'.hash('sha256',$wpdb->prefix.'|bank'),0,64);
$statement=$connection->prepare('SELECT GET_LOCK(?,0)');$statement->bind_param('s',$name);$statement->execute();$statement->bind_result($result);$statement->fetch();$statement->close();
if((string)$result!=='0')throw new RuntimeException('Concurrent reference lock was not excluded.');
echo "PASS duplicate-reference connection excluded\n";
$name='crmp_'.md5($wpdb->prefix).'_922338';
$statement=$connection->prepare('SELECT GET_LOCK(?,0)');$statement->bind_param('s',$name);$statement->execute();$statement->bind_result($result);$statement->fetch();$statement->close();
if((string)$result!=='1')throw new RuntimeException('Unable to hold competing connection fixture.');
if(crmp_acquire_order_lock(922338,false)!==false)throw new RuntimeException('Background operation did not skip a busy order.');
echo "PASS background maintenance skips a competing lock without terminating the admin request\n";
$statement=$connection->prepare('SELECT RELEASE_LOCK(?)');$statement->bind_param('s',$name);$statement->execute();$statement->close();
// Simulate a lost cached lock and a competing connection before the next action.
$lost='crmp_'.md5($wpdb->prefix).'_922337';$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lost));
$statement=$connection->prepare('SELECT GET_LOCK(?,0)');$statement->bind_param('s',$lost);$statement->execute();$statement->close();
if(crmp_acquire_order_lock(922337,false)!==false)throw new RuntimeException('Stale request-local lock cache bypassed a competing holder.');
echo "PASS stale lock cache fails closed after external release\n";
$statement=$connection->prepare('SELECT RELEASE_LOCK(?)');$statement->bind_param('s',$lost);$statement->execute();$statement->close();
if(!crmp_acquire_order_lock(922337,false))throw new RuntimeException('Unable to reacquire lost lock safely.');
echo "PASS lost lock safely reacquired after competitor releases\n";
$connection->close();
if(crmp_acquire_order_lock(922338,false)!==true)throw new RuntimeException('Released competing lock could not be acquired.');
echo "PASS skipped lock remains available for the next maintenance attempt\n";
