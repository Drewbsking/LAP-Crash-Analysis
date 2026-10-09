<?php
declare(strict_types=1);
use App\{Auth, DB, Mail, Projects};

function seed_demo(): void
{
    $password = 'Demo-Only-2026!';
    $temp = 'Temporary-Demo-2026!';
    $bates = Auth::createUser(['username'=>'bates','full_name'=>'Andrew Bates','email'=>'bates@example.test','role'=>'admin'], $temp);
    Auth::changePassword($bates, $temp, $password);
    $ids = ['bates'=>$bates];
    foreach ([['jeremy','Jeremy Miller','analyst'], ['peters','Andrew Peters','design'], ['mayich','Jacob Mayich','design']] as [$username,$name,$role]) {
        $id = Auth::createUser(['username'=>$username,'full_name'=>$name,'email'=>$username.'@example.test','role'=>$role], $temp, $bates);
        Auth::changePassword($id, $temp, $password);
        $ids[$username] = $id;
    }
    foreach (['Andrew Bates'=>'bates','Jeremy Miller'=>'jeremy','Andrew Peters'=>'peters','Jacob Mayich'=>'mayich'] as $name=>$username) DB::query('UPDATE stakeholders SET email=? WHERE full_name=?', [$username.'@example.test',$name]);
    $day = new DateTimeImmutable(today());
    $samples = [
        ['Maple Road rehabilitation', 'DEMO-58001', 14, '3R', 'Southfield Road to Cranbrook Road', 'entered', 'peters', ['Pavement markings','Guardrail updates']],
        ['Adams Road intersection improvements', 'DEMO-58002', 5, 'Safety', 'Adams Road at Square Lake Road', 'started', 'mayich', ['Turn lanes','Signal upgrades']],
        ['Walton Boulevard resurfacing', 'DEMO-58003', -3, 'Preventive Maintenance', 'Squirrel Road to Adams Road', 'ready_for_review', 'peters', ['Pavement markings','New signage']],
        ['Orion Road shoulder improvements', 'DEMO-58004', 30, '3R', 'Silverbell Road to Clarkston Road', 'started', 'mayich', ['Shoulder widening','Delineation']],
        ['Cooley Lake Road reconstruction', 'DEMO-58005', -10, '4R', 'Cass Lake Road to Hiller Road', 'complete', 'peters', ['Realignment','Removal of fixed objects']],
        ['Baldwin Road safety upgrades', 'DEMO-58006', 22, 'Safety', 'Waldon Road to Gregory Road', 'entered', 'mayich', ['New signage','Pavement markings']],
    ];
    foreach ($samples as $i=>[$name,$number,$days,$type,$limits,$status,$owner,$safety]) {
        $data = ['name'=>$name,'rcoc_number'=>$number,'mdot_number'=>'00'.(220001+$i),'needed_by'=>$day->modify("$days days")->format('Y-m-d'),'bid_date'=>$day->modify('+90 days')->format('Y-m-d'),'construction_year'=>(int)$day->format('Y')+1,'design_exception'=>'0','exception_details'=>'','project_type'=>$type,'type_details'=>'','scope'=>'Fictional demonstration project. Review recent crash patterns within the project limits to inform the proposed roadway improvements.','limits_text'=>$limits,'safety_improvements'=>$safety,'safety_details'=>''];
        $id = Projects::create($ids[$owner], $data, bin2hex(random_bytes(32)));
        if ($status !== 'entered') Projects::folder($ids['jeremy'], $id, 1, 'G:\\A TOC\\LAP Reviews\\' . $number);
        if (in_array($status, ['ready_for_review','complete'], true)) Projects::advance($ids['jeremy'], $id, 2, 'ready_for_review', true);
        if ($status === 'complete') Projects::advance($bates, $id, 3, 'complete', true);
    }
    Mail::deliverPending(100);
}
