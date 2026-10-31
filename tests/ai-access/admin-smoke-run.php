<?php
/** Bounded admin smoke runner; owns its loopback server and always stops it. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
if (!is_file($root . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Synthetic smoke installation required');
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false); fclose($socket);
$log = tempnam(sys_get_temp_dir(), 's4-admin-');
$server = proc_open([PHP_BINARY, '-S', $address, '-t', $root], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
$exit = 2;
try {
    for($i=0;$i<100;$i++) {
        $s=@stream_socket_client('tcp://'.$address,$n,$e,.1);
        if($s){fclose($s);break;} usleep(100000);
    }
    $child=proc_open([PHP_BINARY,$root.'/tests/smoke/admin_pages.php'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$childPipes,$root,['SMOKE_BASE'=>'http://'.$address]+getenv());
    fclose($childPipes[0]); $exit=proc_close($child);
} finally {
    fclose($pipes[0]); proc_terminate($server); proc_close($server);
    if(is_file($log))unlink($log);
}
exit($exit);
