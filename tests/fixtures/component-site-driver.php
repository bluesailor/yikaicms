<?php
/** Copied into a disposable install by the CLI round-trip gate; never a web endpoint. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IK_CLI', true);
if(($argv[1]??'')==='identity') {
    define('ROOT_PATH',__DIR__);require __DIR__.'/config/config.php';
    echo json_encode(['driver'=>DB_DRIVER,'name'=>defined('DB_NAME')?DB_NAME:null,'prefix'=>DB_PREFIX,
        'host'=>defined('DB_HOST')?DB_HOST:null,'port'=>defined('DB_PORT')?DB_PORT:null,'path'=>defined('DB_PATH')?DB_PATH:'']);exit;
}
define('SITE_LANG', $argv[2] ?? 'zh-CN');
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/SiteTemplateService.php';
require_once __DIR__ . '/includes/builder/bootstrap.php';

function cr_json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function cr_doc(array $elements, array $settings = []): string {
    return cr_json(['schema'=>1,'settings'=>$settings,'sections'=>[['id'=>'rt_section','settings'=>[],
        'columns'=>[['id'=>'rt_column','settings'=>[],'elements'=>$elements]]]]]);
}
function cr_instance(string $uuid, int $pin = 0, array $extra = []): array {
    return ['id'=>'rt_instance','type'=>'component','data'=>['component'=>$uuid,'pin'=>$pin]+$extra];
}
function cr_master(int $version): string {
    return cr_doc([['id'=>'rt_root','type'=>'container','data'=>['children'=>[
        ['id'=>'rt_title','type'=>'heading','data'=>['text'=>'DEFAULT_V'.$version,'level'=>'h2','_classes'=>['gc_aabbccddeeff']]],
        ['id'=>'rt_marker','type'=>'text','data'=>['html'=>'<p>VERSION_'.$version.'_ONLY</p>']],
        ['id'=>'rt_button','type'=>'button','data'=>['text'=>'LINK_V'.$version,'url'=>'/page.php?id=601']],
        ['id'=>'rt_image','type'=>'image','data'=>['src'=>'/uploads/rt-v'.$version.'.svg','alt'=>'ALT_V'.$version]],
    ]]]], ['component'=>['props'=>[
        ['key'=>'title','type'=>'text','label'=>'Title','default'=>'DEFAULT_V'.$version,'localizable'=>true,
            'default_i18n'=>['en'=>'DEFAULT_EN_V'.$version],'targets'=>[['node'=>'rt_title','field'=>'text']]],
        ['key'=>'link','type'=>'url','label'=>'Link','default'=>'/page.php?id=601','targets'=>[['node'=>'rt_button','field'=>'url']]],
    ],'variants'=>[['key'=>'featured','label'=>'Featured','values'=>['title'=>'FEATURED_V'.$version]]]]]);
}
function cr_outer(string $inner, int $version): string {
    return cr_doc([['id'=>'rt_outer','type'=>'container','data'=>['children'=>[
        ['id'=>'rt_outer_marker','type'=>'heading','data'=>['text'=>'OUTER_V'.$version,'level'=>'h2']],
        ['id'=>'rt_nested','type'=>'component','data'=>['component'=>$inner,'pin'=>$version,'variant'=>'featured']],
    ]]]]);
}
function cr_publish(string $name, string $uuid, string $json): int {
    $processed = BloxComponents::processMaster($json, 'tpl0');
    $id = bloxTemplateModel()->createDraft('component',$name,$processed['json'],metadata:['component'=>['uuid'=>$uuid]]);
    bloxTemplateModel()->publishDraft($id);
    BloxComponents::resetForTests();
    return $id;
}

$mode=$argv[1]??'';
if ($mode==='seed') {
    // A fixed fixture, independent of installer demo/default content and its visibility filters.
    foreach(array_reverse(SiteTemplateData::TABLES) as $table)db()->execute('DELETE FROM '.DB_PREFIX.$table);
    db()->execute('UPDATE '.DB_PREFIX.'plugins SET status=0');
    $inner='cmp_1234567890abcdef'; $outer='cmp_abcdef1234567890'; $shallow='cmp_1111222233334444';
    settingModel()->saveBatch(['site_name'=>'Component roundtrip','enabled_languages'=>'["zh-CN","en"]','site_lang'=>'zh-CN',
        'static_html_enabled'=>'0','html_cache_enabled'=>'0','smtp_pass'=>'RT_PRIVATE_SECRET']);
    db()->insert('blox_global_classes',['class_id'=>'gc_aabbccddeeff','name'=>'roundtrip-title','settings'=>'{"text_color":"#c2410c","font_size_px":31}','status'=>'active','created_at'=>1,'updated_at'=>1]);
    foreach([1,2] as $v) file_put_contents(__DIR__.'/uploads/rt-v'.$v.'.svg','<svg xmlns="http://www.w3.org/2000/svg" width="12" height="8"><text x="0" y="7">V'.$v.'</text></svg>');
    // Old-media references deliberately exist only in v1, not in the latest component.
    $card=cr_publish('Roundtrip card',$inner,cr_master(1));
    $group=cr_publish('Roundtrip outer',$outer,cr_outer($inner,1));
    $shallowJson=json_decode(cr_outer($inner,1),true);
    $shallowJson['sections'][0]['columns'][0]['elements'][0]['data']['children'][1]['data']['pin']=0;
    $shallowId=cr_publish('Roundtrip shallow',$shallow,cr_json($shallowJson));
    foreach([[$card,cr_master(2)],[$group,cr_outer($inner,2)]] as [$id,$json]) {
        bloxTemplateModel()->updateDraft($id,BloxComponents::processMaster($json,'tpl'.$id)['json'],[]);
        bloxTemplateModel()->publishDraft($id); BloxComponents::resetForTests();
    }
    bloxTemplateModel()->updateDraft($shallowId,BloxComponents::processMaster(cr_outer($inner,2),'tpl'.$shallowId)['json'],[]);
    bloxTemplateModel()->publishDraft($shallowId); BloxComponents::resetForTests();
    $cases=[
        'pin'=>cr_instance($inner,1,['variant'=>'featured']),
        'latest'=>cr_instance($inner,0,['variant'=>'featured']),
        'nested-pin'=>cr_instance($outer,1),
        'nested-latest'=>cr_instance($outer),
        'override'=>cr_instance($inner,1,['variant'=>'featured','props'=>['title'=>'OVERRIDE_ZH_中文🧪','link'=>'/page.php?id=602'],
            'props_i18n'=>['en'=>['title'=>'OVERRIDE_EN']]]),
        'default'=>cr_instance($inner,1),
        'shallow'=>cr_instance($shallow,1),
    ];
    foreach(['zh-CN','en'] as $language) foreach(array_keys($cases) as $n=>$name) {
        $id=601+$n+($language==='en'?100:0); $json=cr_doc([$cases[$name]]);
        db()->insert('channels',['id'=>$id,'name'=>'Roundtrip '.$name,'slug'=>'rt-'.$name.($language==='en'?'-en':''),'type'=>'page','lang'=>$language,'status'=>1]);
        db()->insert('contents',['channel_id'=>$id,'title'=>'Roundtrip '.$name,'type'=>'page','content_type'=>'blocks','blocks_data'=>$json,'lang'=>$language,'status'=>1]);
        db()->insert('blox_page_drafts',['page_id'=>$id,'draft_data'=>$json,'published_data'=>$json,'updated_at'=>time()]);
    }
    $home=cr_doc([$cases['nested-pin']]);
    settingModel()->saveBatch(['home_blox_active'=>'1','home_blox_published'=>$home,'home_blox_data'=>$home]);
    // Library/template documents are portable stores too, even when not selected as a public theme area.
    db()->insert('blocks_library',['name'=>'Roundtrip block','data'=>cr_json(json_decode($home,true)['sections'][0]),'created_at'=>1,'updated_at'=>1]);
    $tpl=bloxTemplateModel()->createDraft('section','Roundtrip section',$home); bloxTemplateModel()->publishDraft($tpl);
    BloxComponents::reindexSite();
    // Unpublished text must never become part of an exported frozen master.
    bloxTemplateModel()->updateDraft($card,BloxComponents::processMaster(cr_master(99),'tpl'.$card)['json'],[]);
    echo cr_json(['seeded'=>true]); exit;
}
if ($mode==='export') {
    (new SiteTemplateService(__DIR__))->export($argv[3]); echo cr_json(['exported'=>true]); exit;
}
if ($mode==='import') {
    $service=new SiteTemplateService(__DIR__);
    if(!$service->canApply())throw new RuntimeException('Target must be a fresh install');
    $plan=$service->prepare($argv[3],1);
    for($i=0;$i<30;$i++){ $stage=$service->stage($plan['token'],1); if($stage['complete'])break; }
    if(empty($stage['complete']))throw new RuntimeException('Bounded staging did not complete');
    $service->apply($plan['token'],1,['site_name'=>'Component roundtrip'],true,true);
    echo cr_json(['imported'=>true]); exit;
}
if ($mode==='existing-frozen') {
    $package=SiteTemplateArchive::read($argv[3]);
    foreach($package['manifest']['data']['tables']['blox_templates'] as $row) {
        if($row['type']!=='component')continue;
        $uuid=json_decode($row['metadata'],true)['component']['uuid'];
        if(in_array($uuid,['cmp_1234567890abcdef','cmp_abcdef1234567890','cmp_1111222233334444'],true))continue;
        db()->insert('blox_templates',$row);
    }
    echo cr_json(['inserted'=>true]);exit;
}
if ($mode==='collision') {
    $uuid=BloxComponents::frozenUuid('cmp_1234567890abcdef',1);
    $rows=db()->fetchAll('SELECT id,metadata,published_data FROM '.DB_PREFIX.'blox_templates WHERE type=?',['component']);
    foreach($rows as $row)if((json_decode($row['metadata'],true)['component']['uuid']??'')===$uuid)$original=$row;
    if(!isset($original))throw new RuntimeException('Collision control is missing');
    $meta=json_decode($original['metadata'],true);$archived=$versioned=$meta;
    $archived['component']['archived']=true;$versioned['component']['version']=2;
    $cases=['content'=>['published_data'=>cr_master(77)],'archived'=>['metadata'=>cr_json($archived)],'version'=>['metadata'=>cr_json($versioned)]];
    $blocked=[];
    foreach($cases as $name=>$values) {
        db()->update('blox_templates',$values,'id=?',[$original['id']]);
        try {(new SiteTemplateService(__DIR__))->export($argv[3]);$blocked[$name]=false;}
        catch(RuntimeException $e){$blocked[$name]=$e->getMessage()==='st_invalid';}
        finally{db()->update('blox_templates',['metadata'=>$original['metadata'],'published_data'=>$original['published_data']],'id=?',[$original['id']]);BloxComponents::resetForTests();}
    }
    echo cr_json(['cases'=>$blocked]);
    exit;
}
if($mode!=='inspect')throw new RuntimeException('Unknown mode');
$pages=[];
foreach(db()->fetchAll('SELECT ch.id, ch.lang, ch.slug, c.blocks_data FROM '.DB_PREFIX.'channels ch JOIN '.DB_PREFIX.'contents c ON c.channel_id=ch.id WHERE ch.id BETWEEN 601 AND 707 ORDER BY ch.id') as $row) {
    if($row['lang']!==SITE_LANG)continue;
    $pages[(string)$row['id']]=['doc'=>json_decode($row['blocks_data'],true),'html'=>BlockRenderer::render($row['blocks_data']),
        'pretty_url'=>($row['lang']==='en'?'/en/':'/').$row['slug'].'.html'];
}
$components=[];
foreach(db()->fetchAll('SELECT * FROM '.DB_PREFIX.'blox_templates WHERE type=? ORDER BY id',['component']) as $row) {
    $meta=json_decode($row['metadata'],true)['component'];
    $components[$meta['uuid']]=['version'=>$meta['version'],'doc'=>json_decode($row['published_data'],true)];
}
$pending=[]; foreach(Migrator::loadAll() as $migration)if(!Migrator::isApplied($migration))$pending[]=$migration['id'];
$snapshots=[];
foreach(db()->fetchAll('SELECT component_uuid,version,snapshot FROM '.DB_PREFIX.'blox_component_revisions ORDER BY id') as $row)
    $snapshots[$row['component_uuid'].'@'.$row['version']]=json_decode($row['snapshot'],true);
echo cr_json(['pages'=>$pages,'components'=>$components,'snapshots'=>$snapshots,'home'=>json_decode((string)config('home_blox_published'),true),
    'libraries'=>db()->fetchAll('SELECT data FROM '.DB_PREFIX.'blocks_library'),
    'sections'=>db()->fetchAll('SELECT published_data FROM '.DB_PREFIX.'blox_templates WHERE type=?',['section']),
    'refs'=>db()->fetchAll('SELECT component_uuid,doc_key,ref_count FROM '.DB_PREFIX.'blox_component_refs ORDER BY component_uuid,doc_key'),
    'classes'=>db()->fetchAll('SELECT class_id,name,settings FROM '.DB_PREFIX.'blox_global_classes'),
    'pending'=>$pending,'missing_pins'=>BloxComponents::missingPins()]);
