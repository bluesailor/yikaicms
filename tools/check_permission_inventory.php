<?php
/** Discover entry files and literal action candidates; fail on inventory drift. */
declare(strict_types=1);
$root = dirname(__DIR__);
$files = array_merge(glob($root.'/*.php') ?: [], glob($root.'/admin/*.php') ?: []);
if(is_file($root.'/mcp/index.php'))$files[]=$root.'/mcp/index.php';
if(is_dir($root.'/api')) {
    $api=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/api',FilesystemIterator::SKIP_DOTS));
    foreach($api as $file)if($file->isFile() && $file->getExtension()==='php')$files[]=$file->getPathname();
}
$plugins = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/plugins', FilesystemIterator::SKIP_DOTS));
foreach ($plugins as $file) {
    if ($file->isFile() && preg_match('/(?:^api\.php$|_api\.php$)/', $file->getFilename())) $files[] = $file->getPathname();
}
$entries=[];
foreach ($files as $file) {
    $path = str_replace('\\','/',substr($file,strlen($root)+1));
    $source=(string)file_get_contents($file);
    preg_match_all('/\$action\s*={2,3}\s*[\x27"]([a-zA-Z0-9_-]+)[\x27"]|case\s+[\x27"]([a-zA-Z0-9_-]+)[\x27"]\s*:/', $source, $matches, PREG_SET_ORDER);
    $actions=[];
    foreach($matches as $m) $actions[]=$m[1] !== '' ? $m[1] : ($m[2] ?? '');
    $actions=array_values(array_unique($actions)); sort($actions);
    $entries[$path]=['action_candidates'=>$actions];
}
ksort($entries);
$data=['version'=>1,'note'=>'Discovery only; action candidates are heuristic, not proof of authorization coverage. Runtime coverage is documented in the acceptance report.','entries'=>$entries];
$path=$root.'/tests/fixtures/security-entry-inventory.json';
$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
if(in_array('--update',$argv,true)) {file_put_contents($path,$json);echo count($entries)." entries inventoried\n";exit;}
if(!is_file($path) || file_get_contents($path)!==$json) {fwrite(STDERR,"Entry inventory changed. Review new files/actions and update the inventory explicitly.\n");exit(1);}
echo count($entries)." entry files match inventory; dynamic dispatch still requires manual review\n";
