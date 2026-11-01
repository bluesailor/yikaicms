<?php
/** Owned foreground web/FastCGI processes for the disposable component gate. */
declare(strict_types=1);

final class ComponentRoundtripServer
{
    private array $processes = [];
    private array $logs = [];
    private string $runtime = '';
    private string $site = '';
    private string $nonce = '';
    private array $metadata = [];

    public function __construct(private string $kind, private int $port, private string $binary, private string $cgi, private string $repo) {}

    private function launch(array $command, string $cwd, string $log): mixed
    {
        $savedNonce = getenv('YK_COMPONENT_SERVER_NONCE');
        $savedRequests = getenv('PHP_FCGI_MAX_REQUESTS');
        putenv('YK_COMPONENT_SERVER_NONCE=' . $this->nonce);
        putenv('PHP_FCGI_MAX_REQUESTS=2000');
        try {
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $process = proc_open($command, [0=>['file',$null,'r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, $cwd);
        } finally {
            putenv($savedNonce === false ? 'YK_COMPONENT_SERVER_NONCE' : 'YK_COMPONENT_SERVER_NONCE=' . $savedNonce);
            putenv($savedRequests === false ? 'PHP_FCGI_MAX_REQUESTS' : 'PHP_FCGI_MAX_REQUESTS=' . $savedRequests);
        }
        if (!is_resource($process)) throw new RuntimeException('Cannot launch owned test process');
        $this->processes[] = $process;
        $this->metadata['owned_pids'][] = proc_get_status($process)['pid'];
        return $process;
    }

    private function command(array $command, string $cwd): string
    {
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd);
        if (!is_resource($process)) throw new RuntimeException('Cannot execute server validation command');
        fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
        if ($code !== 0) throw new RuntimeException('Server validation failed: ' . substr($out . $error, 0, 1500));
        return trim($out . $error);
    }

    private static function quote(string $path): string
    {
        if (str_contains($path,'"') || preg_match('/[\r\n]/',$path)) throw new RuntimeException('Invalid server path');
        return '"' . str_replace('\\','/',$path) . '"';
    }

    private static function freePort(): int
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0');
        if (!$socket) throw new RuntimeException('No isolated FastCGI port');
        $address=stream_socket_get_name($socket,false); fclose($socket);
        return (int)substr($address,strrpos($address,':')+1);
    }

    private function nginx(int $fcgi): void
    {
        $home=dirname($this->binary);
        foreach (['fastcgi_params','mime.types'] as $name) {
            if (!copy($home.'/conf/'.$name,$this->runtime.'/'.$name)) throw new RuntimeException('Missing nginx protocol/type map');
        }
        // Test the shipped rules, changing only the isolated PHP upstream.
        $snippet=str_replace('127.0.0.1:9000','127.0.0.1:'.$fcgi,(string)file_get_contents($this->repo.'/deploy/nginx-server.conf'));
        file_put_contents($this->runtime.'/site.conf',$snippet);
        $config='worker_processes 1; daemon off; pid nginx.pid; error_log error.log; events { worker_connections 128; } http {'
            .' include mime.types; default_type application/octet-stream; access_log access.log;'
            .' client_body_temp_path client_body; fastcgi_temp_path fastcgi; proxy_temp_path proxy;'
            .' scgi_temp_path scgi; uwsgi_temp_path uwsgi; server { listen 127.0.0.1:'.$this->port.';'
            .' server_name 127.0.0.1; root '.self::quote($this->site).'; include site.conf; }}';
        file_put_contents($this->runtime.'/nginx.conf',$config);
        $this->metadata['binary_version']=$this->command([$this->binary,'-v'],$home);
        $this->command([$this->binary,'-p',$this->runtime.'/','-c','nginx.conf','-t'],$home);
        $this->launch([$this->binary,'-p',$this->runtime.'/','-c','nginx.conf'],$home,$this->runtime.'/process.log');
    }

    private function apache(int $fcgi): void
    {
        $home=dirname(dirname($this->binary));
        $modules=is_dir($home.'/modules')?$home.'/modules':'/usr/lib/apache2/modules';
        $compiled=$this->command([$this->binary,'-l'],dirname($this->binary));
        $config='ServerRoot '.self::quote($this->runtime)."\nPidFile apache.pid\nListen 127.0.0.1:".$this->port."\nServerName 127.0.0.1\n";
        $names=PHP_OS_FAMILY==='Windows'?['mpm_winnt']:['mpm_event','unixd'];
        $names=array_merge($names,['log_config','authz_core','authz_host','access_compat','env','setenvif','mime','dir','alias','rewrite','headers','proxy','proxy_fcgi']);
        foreach ($names as $name) {
            if (str_contains($compiled,'mod_'.$name.'.c') || str_contains($compiled,$name.'.c')) continue;
            $file=$modules.'/mod_'.$name.'.so';
            if (!is_file($file)) throw new RuntimeException('Missing Apache module: '.$name);
            $config.='LoadModule '.$name.'_module '.self::quote($file)."\n";
        }
        if (PHP_OS_FAMILY!=='Windows') {
            if (!function_exists('posix_geteuid') || posix_geteuid()===0) throw new RuntimeException('Run the isolated Apache gate as a non-root user');
            $config.='User "#'.posix_geteuid().'"'."\n".'Group "#'.posix_getegid().'"'."\n";
        }
        $mime=is_file($home.'/conf/mime.types')?$home.'/conf/mime.types':'/etc/mime.types';
        if (!copy($mime,$this->runtime.'/mime.types')) throw new RuntimeException('Missing Apache MIME map');
        $config.='TypesConfig '.self::quote($this->runtime.'/mime.types')."\nDocumentRoot ".self::quote($this->site)."\nDirectoryIndex index.php\n"
            .'<Directory '.self::quote($this->site).">\n AllowOverride All\n Options FollowSymLinks\n Require all granted\n</Directory>\n"
            ."ProxyFCGIBackendType GENERIC\n<FilesMatch \\.php$>\n SetHandler \"proxy:fcgi://127.0.0.1:".$fcgi."/\"\n</FilesMatch>\n"
            .(PHP_OS_FAMILY==='Windows'?"ProxyFCGISetEnvIf \"reqenv('SCRIPT_FILENAME') =~ m#^/([A-Za-z]:/.*)$#\" SCRIPT_FILENAME \"$1\"\n":'')
            .'ErrorLog '.self::quote($this->runtime.'/error.log')."\nLogFormat \"%h %t \\\"%r\\\" %>s\" gate\nCustomLog ".self::quote($this->runtime.'/access.log')." gate\n";
        file_put_contents($this->runtime.'/apache.conf',$config);
        $this->metadata['binary_version']=$this->command([$this->binary,'-v'],dirname($this->binary));
        $this->command([$this->binary,'-f',$this->runtime.'/apache.conf','-t'],dirname($this->binary));
        $this->launch([$this->binary,'-f',$this->runtime.'/apache.conf','-X'],dirname($this->binary),$this->runtime.'/process.log');
    }

    public function start(string $site): array
    {
        $this->site=str_replace('\\','/',$site);
        $this->runtime=dirname($this->site).'/web-'.basename($site);
        mkdir($this->runtime,0700,true); mkdir($this->runtime.'/sessions',0700,true);
        $this->logs=[$this->runtime.'/error.log',$this->runtime.'/process.log',$this->runtime.'/php.log'];
        $this->nonce=bin2hex(random_bytes(16));
        file_put_contents($site.'/roundtrip-health.php','<?php header("Content-Type: application/json"); echo json_encode(["nonce"=>getenv("YK_COMPONENT_SERVER_NONCE"),"root"=>__DIR__,"sapi"=>PHP_SAPI,"php"=>PHP_VERSION,"software"=>$_SERVER["SERVER_SOFTWARE"]??""]);');
        if ($this->kind==='builtin') {
            $this->launch([PHP_BINARY,'-S','127.0.0.1:'.$this->port,'-t',$site],$site,$this->runtime.'/process.log');
        } else {
            if (!is_file($this->binary) || !is_file($this->cgi)) throw new RuntimeException('Supply existing server and PHP CGI binaries');
            $fcgi=self::freePort();
            $this->metadata['fastcgi_port']=$fcgi;
            $this->launch([$this->cgi,'-b','127.0.0.1:'.$fcgi,'-d','cgi.force_redirect=0','-d','session.save_path='.$this->runtime.'/sessions','-d','log_errors=1','-d','error_log='.$this->runtime.'/php.log'],dirname($this->cgi),$this->runtime.'/php.log');
            if ($this->kind==='nginx') $this->nginx($fcgi); else $this->apache($fcgi);
        }
        for ($i=0;$i<120;$i++) {
            foreach ($this->processes as $process) if (!proc_get_status($process)['running']) throw new RuntimeException('Owned service exited before readiness: '.substr($this->logText(),-1800));
            $ch=curl_init('http://127.0.0.1:'.$this->port.'/roundtrip-health.php');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>1,CURLOPT_PROXY=>'']);
            $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            $health=is_string($raw)?json_decode($raw,true):null;
            if ($status===200 && is_array($health) && ($health['nonce']??null)===$this->nonce
                && str_replace('\\','/',$health['root']??'')===$this->site
                && ($health['sapi']??'')===($this->kind==='builtin'?'cli-server':'cgi-fcgi') && ($health['php']??'')===PHP_VERSION) {
                unset($health['nonce']);
                $this->metadata+=['kind'=>$this->kind,'health'=>$health,'runtime'=>$this->runtime];
                $config=$this->runtime.'/'.($this->kind==='nginx'?'nginx.conf':'apache.conf');
                if (is_file($config)) $this->metadata['config_sha256']=hash_file('sha256',$config);
                return $this->metadata;
            }
            usleep(100000);
        }
        throw new RuntimeException('Owned Web/PHP readiness timeout: HTTP '.$status.' '.substr((string)$raw,0,300).' '.substr($this->logText(),-1800));
    }

    public function logText(): string
    {
        $text='';foreach ($this->logs as $file) if (is_file($file)) $text.=(string)file_get_contents($file)."\n";
        return $text;
    }

    public function stop(): void
    {
        if ($this->kind==='nginx' && is_file($this->runtime.'/nginx.pid')) {
            try { $this->command([$this->binary,'-p',$this->runtime.'/','-c','nginx.conf','-s','quit'],dirname($this->binary)); } catch (Throwable) {}
            $web=$this->processes[array_key_last($this->processes)]??null;
            for ($i=0;$i<30;$i++) { if(!is_resource($web) || !proc_get_status($web)['running'])break;usleep(100000); }
        }
        foreach (array_reverse($this->processes) as $process) {
            if (is_resource($process)) { if (proc_get_status($process)['running'])proc_terminate($process);proc_close($process); }
        }
        $this->processes=[];
        if ($this->site!=='')file_put_contents($this->site.'/server.log',$this->logText());
    }
}
