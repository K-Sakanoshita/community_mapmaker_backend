<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';
use CommunityMapMaker\Activity\{ActivityApi,ActivityBulkDeletion,ActivityBulkDeletionConflict,ActivityValidationException};
use CommunityMapMaker\Auth\Http;
$container=require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityBulkDeletion.php';
$method=Http::requireMethods(['GET','POST']);
ActivityApi::run(function() use($container,$method):array {
    $actor=ActivityApi::requireAuthentication($container);
    if ($method==='POST' && ($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '')!=='1') return [403,['code'=>'console_header_required']];
    $input=$method==='POST'?Http::jsonInput(4096):[];
    $app=is_string($_GET['app'] ?? $input['app'] ?? null)?trim($_GET['app'] ?? $input['app']):'';
    if ($app==='') throw new ActivityValidationException(['app'=>'App key is required.']);
    $container['project_access']->assertCanManage($actor,$app);
    $project=$container['project_service']->find($app);
    try {
        return [200,(new ActivityBulkDeletion($container['database']->pdo(),$container['audit_logs']))->execute((int)$actor['id'],$app,$method,$input,$project)];
    } catch (ActivityBulkDeletionConflict $error) { return [409,['code'=>'activity_delete_conflict']]; }
});
