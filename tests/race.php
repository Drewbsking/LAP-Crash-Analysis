<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\{Projects, ValidationException};
if (env('APP_ENV')!=='test') throw new RuntimeException('Test worker requires APP_ENV=test.');
$actor=(int)$argv[2];
if($argv[1]==='create') {
    $data=['rcoc_number'=>'race-project','name'=>'Concurrent project','needed_by'=>'2027-01-01','bid_date'=>'2027-02-01','design_exception'=>'0','project_type'=>'Safety','construction_year'=>'2027','scope'=>'Concurrent testing','limits_text'=>'A to B','mdot_number'=>'0001','safety_improvements'=>['None']];
    echo Projects::create($actor,$data,$argv[3]);
} else {
    try{Projects::folder($actor,(int)$argv[3],1,'G:\\A TOC\\Concurrent review');echo 'changed';}
    catch(ValidationException $e){if(!str_contains($e->getMessage(),'changed'))throw $e;echo 'stale';}
}
