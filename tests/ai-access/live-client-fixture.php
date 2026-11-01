<?php
/** Seed only the disposable smoke installation for real client acceptance. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
if (!is_file($root . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Synthetic smoke installation required');
require_once $root . '/includes/ai-access/AiAccessBootstrapConfig.php';
AiAccessBootstrapConfig::beginPreparation($root);
define('ROOT_PATH', $root);
require $root . '/includes/init.php';
require_once $root . '/includes/ai-access/AiAccessTokenStore.php';
$dir = $argv[1] ?? '';
if (!is_dir($dir)) throw new RuntimeException('Token directory required');
settingModel()->set('ai_access_enabled', '1');
$store = new AiAccessTokenStore(db());
foreach (['codex'=>2,'claude'=>3] as $client=>$page) {
    $elements = [
        ['id'=>'live-heading','type'=>'heading','data'=>['text'=>'Original '.$client.' heading','level'=>'h2']],
        ['id'=>'live-body','type'=>'text','data'=>['html'=>'<p>Original '.$client.' body <a href="/page.php?id='.$page.'">Local link</a></p>']],
        ['id'=>'live-image','type'=>'image','data'=>['src'=>'/assets/icons/flags/us.svg','alt'=>'Original '.$client.' alt']],
    ];
    $doc = json_encode(['schema'=>1,'settings'=>[], 'sections'=>[['id'=>'live-section','settings'=>[], 'columns'=>[['id'=>'live-column','settings'=>[], 'elements'=>$elements]]]]], JSON_UNESCAPED_SLASHES);
    db()->execute('DELETE FROM yikai_contents WHERE channel_id=?',[$page]);
    db()->insert('contents',['channel_id'=>$page,'title'=>'Live client fixture','lang'=>'zh-CN','status'=>1,'content'=>'','content_type'=>'blocks','blocks_data'=>$doc,'updated_at'=>time()]);
    db()->execute('DELETE FROM yikai_blox_page_drafts WHERE page_id=?',[$page]);
    db()->insert('blox_page_drafts',['page_id'=>$page,'draft_data'=>$doc,'updated_at'=>time()]);
    $token = $store->issue(1,'Live '.$client,['page_read','page_content_propose','proposal_status'],1,time());
    file_put_contents($dir.'/'.$client.'.token',$token['secret']);
}
AiAccessBootstrapConfig::prepare($root);
echo "Synthetic pages and client tokens prepared\n";
