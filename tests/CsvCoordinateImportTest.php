<?php
declare(strict_types=1);
require __DIR__.'/ActivityServiceTest.php';
use CommunityMapMaker\Activity\{ActivityService,OsmCoordinateResolverInterface};
$resolver=new class implements OsmCoordinateResolverInterface {
    public array $calls=[];
    public function resolve(array $ids):array {
        $this->calls[]=$ids;
        return [
            'node/1'=>['latitude'=>34.1,'longitude'=>135.1],
            'way/2'=>['latitude'=>34.2,'longitude'=>135.2],
            'relation/3'=>['latitude'=>34.3,'longitude'=>135.3],
        ];
    }
};
$repository=new MemoryActivities();
$lookupService=new ActivityService($repository,$schema,null,$resolver);
$rows=[
 ['id'=>'lookup/1','osmid'=>'node/1'],
 ['id'=>'lookup/2','osmid'=>'way/2','latitude'=>null,'longitude'=>null],
 ['id'=>'lookup/3','osmid'=>'relation/3'],
 ['id'=>'lookup/4','osmid'=>'node/1','latitude'=>0,'longitude'=>0],
 ['id'=>'lookup/5','osmid'=>'node/404'],
 ['id'=>'lookup/6','osmid'=>'node/1'],
];
$preview=$lookupService->import('playgrounds',$rows,true,null,1,true);
activityAssert($repository->rows===[],'Preview may fetch but never writes activities');
activityAssert($preview['coordinate_lookup']['filled']===4,'Missing node/way/relation coordinates are filled');
activityAssert($preview['coordinate_lookup']['unresolved']===[['row'=>4,'osmid'=>'node/404']],'Unresolved rows retain source index and ID');
activityAssert($resolver->calls[0]===['node/1','way/2','relation/3','node/404'],'Lookups are deduplicated and supplied coordinates skipped');
$lookupService->import('playgrounds',$rows,false,null,1,true);
activityAssert($lookupService->find('playgrounds','lookup/2')['latitude']===34.2,'Resolved coordinates persist');
activityAssert($lookupService->find('playgrounds','lookup/4')['latitude']===0.0,'Existing CSV coordinates are never overwritten');
activityAssert($lookupService->find('playgrounds','lookup/5')['latitude']===null,'Missing OSM feature imports without invented coordinates');
$retained=$lookupService->import('playgrounds',[['id'=>'lookup/1','osmid'=>'node/1','latitude'=>null,'longitude'=>null]],false,null,1,true);
activityAssert($retained['coordinate_lookup']['preserved']===1 && $lookupService->find('playgrounds','lookup/1')['latitude']===34.1,'Saved coordinates are retained when CSV is empty and OSM ID unchanged');
$changed=$lookupService->import('playgrounds',[['id'=>'lookup/1','osmid'=>'node/404']],false,null,1,true);
activityAssert($changed['coordinate_lookup']['unresolved'][0]['row']===0 && $lookupService->find('playgrounds','lookup/1')['latitude']===null,'An OSM ID change does not keep a stale location');
$count=count($resolver->calls);
$lookupService->import('playgrounds',[['id'=>'default-no-lookup','osmid'=>'node/1']],false);
activityAssert(count($resolver->calls)===$count && $lookupService->find('playgrounds','default-no-lookup')['latitude']===null,'Other import callers retain their existing behavior');
activityThrows(fn()=> $lookupService->import('playgrounds',[['id'=>'invalid','osmid'=>'invalid']],true,null,1,true),CommunityMapMaker\Activity\ActivityValidationException::class,'Malformed input fails before network lookup');
activityThrows(fn()=> $lookupService->import('playgrounds',[['id'=>'half','osmid'=>'node/1','latitude'=>34,'longitude'=>null]],true,null,1,true),CommunityMapMaker\Activity\ActivityValidationException::class,'Half-empty coordinates must be corrected, not overwritten');
activityAssert(count($resolver->calls)===$count,'Invalid input never triggers external requests');
echo "CSV coordinate import: preview/apply, preserved coordinates, unresolved reporting, stale location clearing, deduplication and opt-in scope passed.\n";

require_once dirname(__DIR__).'/lib/OsmCoordinateResolver.php';
$fallbackCalls=0;
$fallbackResolver=new CommunityMapMaker\Activity\OsmCoordinateResolver(['cache_dir'=>''],function(){throw new RuntimeException('HTTP 406');},function() use (&$fallbackCalls){
    $fallbackCalls++;return ['elements'=>[['type'=>'node','id'=>9817343475,'lat'=>34.7502168,'lon'=>135.5340408]]];
});
$fallbackRepository=new MemoryActivities();
$fallbackService=new ActivityService($fallbackRepository,$schema,null,$fallbackResolver);
$rows=array_map(fn($i)=>['id'=>'fallback/'.$i,'osmid'=>'node/9817343475'],range(1,35));
$preview=$fallbackService->import('playgrounds',$rows,true,null,1,true);
activityAssert($preview['coordinate_lookup']['filled']===35 && $preview['coordinate_lookup']['unresolved']===[] && $fallbackCalls===1,'Overpass failure falls back once for 35 repeated OSM IDs');
activityAssert($fallbackRepository->rows===[],'Fallback during CSV preview never writes posts');
$fallbackService->import('playgrounds',$rows,false,null,1,true);
activityAssert(count($fallbackRepository->rows)===35 && $fallbackService->find('playgrounds','fallback/35')['latitude']===34.7502168,'CSV apply persists coordinates recovered via fallback');
echo "CSV coordinate fallback: 35 rows, deduplicated lookup, read-only preview and coordinate persistence passed.\n";
