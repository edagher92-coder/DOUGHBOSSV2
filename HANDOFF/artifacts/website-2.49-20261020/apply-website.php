<?php
// One-off private CLI publisher. Never install this script in public_html.
if (PHP_SAPI !== 'cli') { exit(2); }
const WEB_STAGE = '/home/doughbos/doughboss-web-stage-20261020-r2';
const WEB_LIVE = '/home/doughbos/public_html/wp-content/plugins/doughboss';
const WEB_BACKUP = '/home/doughbos/doughboss-deploy-backups/website-20261020-r2';
const WEB_ZIP_SHA = '880fd82295c595fe9f39c16213641971179eeae73df43dd4a536864cfad08ab6';
const WEB_DELTA = '8a5d75f988040c2b5c8c22c652f653fd15fd8e130972f3902236d73bbdb5ee26';
const WEB_MANIFEST_SHA = '517dccf7a8923d4f960f7274db9576fc1c2fa6f3a321fd09836911330f1ff180';
function web_fail($why) { fwrite(STDERR, 'STOP: '.$why."\n"); exit(1); }
function web_assert($ok,$why) { if (!$ok) { web_fail($why); } }
function web_json($path) { $v=json_decode(file_get_contents($path),true); web_assert(is_array($v),'invalid JSON'); return $v; }
function web_path($root,$relative) {
 web_assert(is_string($relative) && preg_match('~^[a-zA-Z0-9_./-]+$~D',$relative) && strpos($relative,'..')===false && $relative[0]!=='/','unsafe path');
 $path=$root.'/'.$relative; $parent=dirname($path);
 while (strlen($parent)>=strlen($root)) { web_assert(!is_link($parent),'linked parent'); if($parent===$root)break; $parent=dirname($parent); }
 web_assert(!is_link($path),'linked file'); return $path;
}
function web_inventory($root) {
 $found=[];
 foreach(['includes','admin','public'] as $dir) {
  $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));
  foreach($iterator as $file) { web_assert(!$file->isLink(),'linked runtime'); if($file->isFile()) { $relative=substr($file->getPathname(),strlen($root)+1);$found[$relative]=hash_file('sha256',$file->getPathname()); } }
 }
 foreach(['doughboss.php','uninstall.php','readme.txt','THIRD_PARTY_NOTICES.md'] as $relative) { $found[$relative]=hash_file('sha256',web_path($root,$relative)); }
 ksort($found);return $found;
}
function web_compare($actual,$expected,$label) {
 ksort($actual);ksort($expected);web_assert(array_keys($actual)===array_keys($expected),$label.' inventory mismatch');
 foreach($expected as $p=>$hash)web_assert(hash_equals($hash,$actual[$p]),$label.' byte mismatch: '.$p);
}
function web_atomic($target,$bytes) {
 $parent=dirname($target);if(!is_dir($parent))web_assert(mkdir($parent,0755,true),'create media directory');
 // Same host filesystem, but private: interrupted writes never leave public temps.
 $temp=tempnam(WEB_STAGE,'.db-web-');web_assert($temp!==false,'create atomic temp');
 web_assert(file_put_contents($temp,$bytes)===strlen($bytes),'write atomic temp');web_assert(chmod($temp,0644),'set file mode');web_assert(rename($temp,$target),'atomic replace');
 if(function_exists('opcache_invalidate') && substr($target,-4)==='.php')opcache_invalidate($target,true);
}
$mode=$argv[1]??'';web_assert(in_array($mode,['preflight','apply','verify','restore'],true),'mode required');
web_assert(realpath(__DIR__)===WEB_STAGE && realpath(WEB_LIVE)===WEB_LIVE,'unexpected host path');
web_assert(hash_file('sha256',WEB_STAGE.'/presentation-manifest.json')===WEB_MANIFEST_SHA,'wrong manifest bytes');
$manifest=web_json(WEB_STAGE.'/presentation-manifest.json');
web_assert(($manifest['delta_sha256']??'')===WEB_DELTA && count($manifest['baseline'])===232 && count($manifest['candidate'])===380 && count($manifest['changed'])===8 && count($manifest['added'])===148,'wrong manifest contract');
$delta=array_merge($manifest['changed'],$manifest['added']);sort($delta);$deltaText='';foreach($delta as $p){web_path(WEB_LIVE,$p);$deltaText.=$p.' '.$manifest['candidate'][$p]."\n";}web_assert(hash('sha256',$deltaText)===WEB_DELTA,'delta digest');
$zipPath=WEB_STAGE.'/doughboss-2.49-website-backport-r2.zip';web_assert(hash_file('sha256',$zipPath)===WEB_ZIP_SHA,'wrong archive');
web_assert(class_exists('PharData'),'host archive reader unavailable');
$zip=new PharData($zipPath);
$archive=[];foreach(new RecursiveIteratorIterator($zip) as $file){$name=substr($file->getPathname(),strlen('phar://'.$zipPath.'/'));web_assert(strpos($name,'doughboss/')===0,'archive prefix');$p=substr($name,10);web_path(WEB_LIVE,$p);web_assert(!isset($archive[$p]),'duplicate archive entry');$archive[$p]=hash('sha256',$file->getContent());}
web_assert(count($archive)===380,'archive file count');
web_compare($archive,$manifest['candidate'],'archive');
define('SHORTINIT',true);require '/home/doughbos/public_html/wp-load.php';
$settings=get_option('doughboss_settings',[]);
web_assert(is_array($settings)&&array_key_exists('ordering_open',$settings)&&array_key_exists('payments_enabled',$settings)&&!$settings['ordering_open']&&!$settings['payments_enabled'],'ordering and payments must remain OFF');
global $wpdb;
$snippetHashes=[598=>'a24283e928475f6872c53d725b05f0ca7a8297322e43b6069adb3a17be0c0e09',609=>'7439c0238de3e1c3cdea2de32dd862d46bc5f5f86d42c6758631d3f8ce90eb52'];
$snippets=[];foreach($snippetHashes as $id=>$hash){$post=$wpdb->get_row($wpdb->prepare("SELECT post_type,post_content FROM {$wpdb->posts} WHERE ID=%d",$id));web_assert($post&&$post->post_type==='wpcode','snippet identity');$snippets[$id]=$post->post_content;if($mode==='preflight'||$mode==='apply')web_assert(hash('sha256',str_replace("\r\n","\n",trim($post->post_content)))===$hash,'snippet changed since review: '.$id);}
if($mode==='preflight'||$mode==='apply')web_compare(web_inventory(WEB_LIVE),$manifest['baseline'],'live baseline');
if($mode==='apply') {
 web_assert(!file_exists(WEB_BACKUP),'backup already exists; do not repeat apply');web_assert(mkdir(WEB_BACKUP,0700),'create private backup');
 foreach($manifest['baseline'] as $p=>$hash){$out=web_path(WEB_BACKUP,$p);if(!is_dir(dirname($out)))web_assert(mkdir(dirname($out),0700,true),'backup directory');web_assert(copy(web_path(WEB_LIVE,$p),$out),'backup file');}
 web_compare(web_inventory(WEB_BACKUP),$manifest['baseline'],'rollback backup');
 web_assert(file_put_contents(WEB_BACKUP.'/snippets-before.json',json_encode($snippets,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))!==false,'backup snippets');
 // New media first; enqueue version last. No bootstrap, schema or settings write.
 $publish=array_merge($manifest['added'],array_values(array_diff($manifest['changed'],['includes/class-doughboss-assets.php'])),['includes/class-doughboss-assets.php']);
 foreach($publish as $p){web_atomic(web_path(WEB_LIVE,$p),$zip['doughboss/'.$p]->getContent());}
}
if($mode==='restore') {
 web_assert(is_dir(WEB_BACKUP),'rollback backup missing');web_compare(web_inventory(WEB_BACKUP),$manifest['baseline'],'rollback backup');
 // Admit an interrupted apply/restore only if every observed byte is old or new.
 $actual=web_inventory(WEB_LIVE);
 foreach($actual as $p=>$hash) {
  web_assert(isset($manifest['candidate'][$p]),'unlisted live file: '.$p);
  $known=$hash===$manifest['candidate'][$p] || (isset($manifest['baseline'][$p]) && $hash===$manifest['baseline'][$p]);
  web_assert($known,'unrecognised live bytes: '.$p);
 }
 foreach($manifest['baseline'] as $p=>$hash)web_assert(isset($actual[$p]),'missing baseline file: '.$p);
 foreach($manifest['changed'] as $p)web_atomic(web_path(WEB_LIVE,$p),file_get_contents(web_path(WEB_BACKUP,$p)));
 $retired=WEB_BACKUP.'/retired-new-media';web_assert(!is_link($retired),'linked retired folder');if(!is_dir($retired))web_assert(mkdir($retired,0700),'retired media directory');
 foreach($manifest['added'] as $p){
  $live=web_path(WEB_LIVE,$p);$out=web_path($retired,$p);
  if(is_file($out))web_assert(hash_file('sha256',$out)===$manifest['candidate'][$p],'unrecognised retired media');
  if(!is_file($live))continue;
  web_assert(!file_exists($out),'both live and retired media exist; inspect before overwrite');
  if(!is_dir(dirname($out)))web_assert(mkdir(dirname($out),0700,true),'retired media parent');web_assert(rename($live,$out),'retire new media');
 }
 web_compare(web_inventory(WEB_LIVE),$manifest['baseline'],'restored live');
}
if($mode==='apply'||$mode==='verify')web_compare(web_inventory(WEB_LIVE),$manifest['candidate'],'published live');
echo json_encode(['mode'=>$mode,'status'=>'PASS','files'=>$mode==='restore'?232:380,'ordering_open'=>0,'payments_enabled'=>0,'backup'=>$mode==='apply'||$mode==='restore'?WEB_BACKUP:null,'delta'=>WEB_DELTA],JSON_UNESCAPED_SLASHES)."\n";
