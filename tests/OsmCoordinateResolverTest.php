<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/Contracts.php';
require_once dirname(__DIR__).'/lib/OsmCoordinateResolver.php';
use CommunityMapMaker\Activity\OsmCoordinateResolver;
function osmCheck(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
$dir=sys_get_temp_dir().'/cmm-coordinate-test-'.bin2hex(random_bytes(6));
$calls=[];
$resolver=new OsmCoordinateResolver(['cache_dir'=>$dir],function(string $query,float $timeout) use (&$calls):array {
    $calls[]=$query;
    osmCheck($timeout>0 && $timeout<=12,'Requests have bounded timeouts');
    return ['elements'=>[
        ['type'=>'node','id'=>1,'lat'=>0,'lon'=>0],
        ['type'=>'way','id'=>2,'center'=>['lat'=>34.123456789,'lon'=>135.123456789]],
        ['type'=>'relation','id'=>3,'center'=>['lat'=>35,'lon'=>136]],
        ['type'=>'node','id'=>4,'lat'=>999,'lon'=>135],
        ['type'=>'way','id'=>5],
        ['type'=>'node','id'=>999,'lat'=>10,'lon'=>10],
    ]];
});
$ids=['node/1','way/2','relation/3','node/4','way/5','node/6','node/1','node/1);out;'];
$points=$resolver->resolve($ids);
osmCheck(array_keys($points)===['node/1','way/2','relation/3'],'Only requested, valid coordinates are returned');
osmCheck($points['node/1']===['latitude'=>0.0,'longitude'=>0.0],'Equator/prime meridian are valid, not missing');
osmCheck($points['way/2']['latitude']===34.1234568,'Coordinates rounded to storage precision');
osmCheck(count($calls)===1 && str_contains($calls[0],'node(id:1,4,6);') && str_contains($calls[0],'way(id:2,5);') && str_contains($calls[0],'relation(id:3);'),'Node/way/relation IDs are grouped and deduplicated');
osmCheck(!str_contains($calls[0],'node/1);out;'),'Untrusted IDs cannot become query text');
$cached=new OsmCoordinateResolver(['cache_dir'=>$dir],function():array { throw new RuntimeException('Must use cache'); });
osmCheck($cached->resolve(['node/1','way/2','relation/3'])===$points,'Preview/apply can reuse cached coordinates across requests');
$failed=new OsmCoordinateResolver(['cache_dir'=>''],function():array { throw new RuntimeException('Timeout'); });
osmCheck($failed->resolve(['node/1'])===[],'Network failure is unresolved, never a fabricated coordinate');
foreach ([[],['elements'=>null],['remark'=>'runtime timeout','elements'=>[['type'=>'node','id'=>1,'lat'=>1,'lon'=>2]]]] as $response) {
    $invalid=new OsmCoordinateResolver(['cache_dir'=>''],fn()=> $response);
    osmCheck($invalid->resolve(['node/1'])===[],'Malformed/incomplete responses are unresolved');
}
$batches=0;
$batch=new OsmCoordinateResolver(['cache_dir'=>''],function($query) use (&$batches):array { $batches++;return ['elements'=>[]]; });
$batch->resolve(array_map(fn($id)=>'node/'.$id,range(1,1001)));
osmCheck($batches===3,'Requests contain at most 500 distinct IDs');
foreach (glob($dir.'/*.json') as $file) unlink($file);
rmdir($dir);
echo "OSM resolver: nodes/ways/relations, zero/range validation, precision, cache, batching, invalid IDs and failure handling passed.\n";

// Official API fallback: synthetic responses keep this regression test offline.
$fallbackCalls=[];
$fallback=new OsmCoordinateResolver(['cache_dir'=>''],function(){throw new RuntimeException('HTTP 406');},function(string $path,float $timeout) use (&$fallbackCalls):array {
    $fallbackCalls[]=$path;
    osmCheck($timeout>0 && $timeout<=6,'Fallback has a bounded timeout');
    if (str_starts_with($path,'nodes.json?')) return ['elements'=>[['type'=>'node','id'=>1,'lat'=>34,'lon'=>135],['type'=>'node','id'=>4,'visible'=>false,'lat'=>1,'lon'=>1]]];
    return ['elements'=>[
        ['type'=>'node','id'=>10,'lat'=>30,'lon'=>130],['type'=>'node','id'=>11,'lat'=>34,'lon'=>136],
        ['type'=>'way','id'=>2,'nodes'=>[10,11,10]],
        ['type'=>'relation','id'=>3,'members'=>[['type'=>'way','ref'=>2],['type'=>'node','ref'=>10]]],
        ['type'=>'relation','id'=>6,'members'=>[['type'=>'relation','ref'=>99]]],
        ['type'=>'way','id'=>7,'nodes'=>[10,404]],
    ]];
});
$points=$fallback->resolve(['node/1','way/2','relation/3','node/4','relation/6','way/7','node/1']);
osmCheck($points===['node/1'=>['latitude'=>34.0,'longitude'=>135.0],'way/2'=>['latitude'=>32.0,'longitude'=>133.0],'relation/3'=>['latitude'=>32.0,'longitude'=>133.0]],'Fallback resolves valid nodes and complete way/relation bounds, but excludes deleted or incomplete geometry');
osmCheck(count($fallbackCalls)===5 && $fallbackCalls[0]==='nodes.json?nodes=1,4','Fallback deduplicates and batches nodes');
$noFallback=new OsmCoordinateResolver(['cache_dir'=>'','osm_api_fallback'=>false],fn()=>['elements'=>[]],function(){throw new LogicException('Disabled fallback was called');});
osmCheck($noFallback->resolve(['node/1'])===[],'Fallback can be disabled in server configuration');
$split=new OsmCoordinateResolver(['cache_dir'=>''],fn()=>['elements'=>[]],function($path){
    if (str_contains($path,',')) throw new RuntimeException('Missing node in batch');
    return ['elements'=>str_ends_with($path,'=1')?[['type'=>'node','id'=>1,'lat'=>0,'lon'=>0]]:[]];
});
osmCheck($split->resolve(['node/1','node/404'])===['node/1'=>['latitude'=>0.0,'longitude'=>0.0]],'Missing IDs do not prevent valid IDs from being resolved');
echo "OSM fallback: HTTP failure recovery, batched nodes, complete geometry, deletion, splitting and configuration passed.\n";
