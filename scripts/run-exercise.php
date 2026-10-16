<?php
/** Execute synthetic workflows through GLPI models in the disposable local lab. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('GLPI_DB_NAME') !== 'atlas_helpdesk') {
    throw new RuntimeException('This runner requires the dedicated local lab database.');
}
require '/var/www/glpi/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel(); $kernel->boot();
global $DB, $CFG_GLPI;
if (GLPI_VERSION !== '12.0.0') { throw new RuntimeException('Review runner before using another GLPI version.'); }
if (($CFG_GLPI['use_notifications'] ?? 1) != 0) { throw new RuntimeException('External notifications must be off.'); }
$passwordPath = $argv[1] ?? '/tmp/atlas-admin-password.txt';
$fixturePath = $argv[2] ?? '/tmp/atlas-cases.json';
$reportPath = $argv[3] ?? '/tmp/atlas-helpdesk-results.json';
$limit = isset($argv[4]) ? (int)$argv[4] : 10;
$auth = new Auth();
if (!$auth->login('glpi', trim(file_get_contents($passwordPath)), true, false, 'local')) {
    throw new RuntimeException('Lab authentication failed.');
}
if (!Session::haveRight('ticket', CREATE)) { throw new RuntimeException('Ticket creation right missing.'); }
$admin = (int) Session::getLoginUserID();
$fixtures = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
if (count($fixtures['cases']) !== 10 || $limit < 1 || $limit > 10) { throw new RuntimeException('Unexpected fixture size.'); }
$marker = 'ATLAS disposable synthetic helpdesk exercise';
$report = ['observed_at_utc'=>gmdate('c'), 'glpi_version'=>GLPI_VERSION,
    'execution'=>'Authenticated PHP CLI using GLPI domain models, not browser clicks or the REST API',
    'data_kind'=>'Synthetic incident narratives; actual local GLPI records and state transitions',
    'limitations'=>['No real endpoint repairs','No real requester approval','No elapsed-time SLA compliance claim','Cron escalation not tested'],
    'checks'=>[], 'cases'=>[], 'completed'=>false];
function checkLab(bool $condition, string $name): void {
    global $report;
    $report['checks'][]=['name'=>$name,'passed'=>$condition];
    if (!$condition) { throw new RuntimeException($name); }
}
function insertLab(string $class, array $input): int {
    $item=new $class();
    $id=$item->add($input);
    if (!$id) { throw new RuntimeException('Failed to add '.$class.': '.json_encode($_SESSION['MESSAGE_AFTER_REDIRECT']??[])); }
    return (int)$id;
}
function ensureLab(string $class, string $name, array $input): int {
    global $marker;
    $item=new $class();
    $rows=$item->find(['name'=>$name]);
    if ($rows) {
        $row=reset($rows);
        if (($row['comment']??'') !== $marker) { throw new RuntimeException('Existing unowned '.$class.' preserved.'); }
        return (int)$row['id'];
    }
    return insertLab($class, ['name'=>$name,'entities_id'=>0,'comment'=>$marker]+$input);
}
function stateLab(Ticket $ticket, int $id, string $step, array &$entry, ?int $expected=null): void {
    checkLab($ticket->getFromDB($id), $entry['case'].' reload '.$step);
    $state=(int)$ticket->fields['status'];
    if ($expected!==null) { checkLab($state===$expected, $entry['case'].' status '.$step); }
    $entry['transitions'][]=['step'=>$step,'status'=>$state,'observed_at_utc'=>gmdate('c')];
}
function updateLab(Ticket $ticket, int $id, array $changes): void {
    $_SESSION['glpi_currenttime']=date('Y-m-d H:i:s');
    if (!$ticket->update(['id'=>$id]+$changes)) { throw new RuntimeException('Ticket update failed for '.$id); }
}
try {
    foreach ($DB->request(['SELECT'=>['id','externalid'],'FROM'=>'glpi_tickets']) as $row) {
        checkLab(str_starts_with((string)$row['externalid'],'ATLAS-LAB:'), 'No unrelated ticket '.$row['id']);
    }
    $entity=new Entity(); $entity->getFromDB(0);
    checkLab($entity->update(['id'=>0,'autoclose_delay'=>-1]), 'Disable automatic ticket closure in fresh lab');
    $group=ensureLab('Group','ATLAS-LAB Service Desk',['is_assign'=>1,'is_usergroup'=>1,'is_notify'=>0]);
    $slm=ensureLab('SLM','ATLAS-LAB Service targets',['calendars_id'=>0,'use_ticket_calendar'=>0]);
    // Continuous clock for this small exercise; these are training targets, not contractual SLAs.
    $targets=[1=>[1440,4320],2=>[480,2880],3=>[240,1440],4=>[60,480],5=>[15,240]];
    $slas=[];
    foreach ($targets as $priority=>$minutes) {
        foreach (['tto'=>SLM::TTO,'ttr'=>SLM::TTR] as $kind=>$type) {
            $slas[$priority][$kind]=ensureLab('SLA',"ATLAS-LAB Priority $priority $kind",[
                'type'=>$type,'slms_id'=>$slm,'number_time'=>$minutes[$kind==='tto'?0:1],
                'definition_time'=>'minute','calendars_id'=>0,'use_ticket_calendar'=>0]);
        }
    }
    foreach (array_slice($fixtures['cases'],0,$limit) as $case) {
        $key=$case['case']; $ticket=new Ticket();
        $existing=$ticket->find(['externalid'=>'ATLAS-LAB:'.$key]);
        if ($existing) { throw new RuntimeException('Existing case '.$key.' preserved; review partial run before resuming.'); }
        $requester=ensureLab('User','atlas-'.strtolower($key),['is_active'=>0,'firstname'=>'Synthetic','realname'=>$key,'authtype'=>Auth::DB_GLPI]);
        $category=ensureLab('ITILCategory','ATLAS-LAB '.$case['category'],['is_incident'=>1,'is_request'=>1,'is_helpdeskvisible'=>1]);
        $priority=Ticket::computePriority($case['urgency'],$case['impact']);
        $kb=insertLab('KnowbaseItem',['name'=>'ATLAS-LAB '.$case['knowledge_topic'], 'entities_id'=>0,'users_id'=>$admin,
            'is_faq'=>0,'answer'=>'<h2>Synthetic training guide</h2><p>'.htmlspecialchars($case['scenario']).'</p><h3>Workflow</h3><p>'.htmlspecialchars($case['resolution_plan']).'</p><h3>Evidence and closure</h3><p>Record scope, approval, checks, outcome and requester confirmation. This lab models the process; it does not establish that any real endpoint was repaired.</p>']);
        $id=insertLab('Ticket',['externalid'=>'ATLAS-LAB:'.$key,'name'=>'ATLAS-LAB '.$key.' '.$case['title'],
            'entities_id'=>0,'status'=>Ticket::INCOMING,'type'=>$case['type']==='incident'?1:2,
            'content'=>'<p>SYNTHETIC EXERCISE — no real incident.</p><p>'.htmlspecialchars($case['scenario']).'</p>',
            'urgency'=>$case['urgency'],'impact'=>$case['impact'],'priority'=>$priority,'itilcategories_id'=>$category,
            'slas_id_tto'=>$slas[$priority]['tto'],'slas_id_ttr'=>$slas[$priority]['ttr']]);
        // Disabled users are not retained by the automatic requester-input handling.
        // An explicit model relation records the synthetic requester without enabling login.
        insertLab('Ticket_User',['tickets_id'=>$id,'users_id'=>$requester,'type'=>CommonITILActor::REQUESTER,'use_notification'=>0]);
        $entry=['case'=>$key,'ticket_id'=>$id,'requester_id'=>$requester,'category_id'=>$category,'knowledge_id'=>$kb,
            'priority'=>$priority,'transitions'=>[]];
        stateLab($ticket,$id,'new',$entry,Ticket::INCOMING);
        checkLab(!empty($ticket->fields['time_to_own'])&&!empty($ticket->fields['time_to_resolve']),$key.' SLA due dates calculated');
        $entry['initial_due_dates']=['own'=>$ticket->fields['time_to_own'],'resolve'=>$ticket->fields['time_to_resolve']];
        updateLab($ticket,$id,['_users_id_assign'=>$admin,'_groups_id_assign'=>$group,'status'=>Ticket::ASSIGNED]);
        stateLab($ticket,$id,'assigned',$entry,Ticket::ASSIGNED);
        insertLab('ITILFollowup',['itemtype'=>'Ticket','items_id'=>$id,'is_private'=>0,'users_id'=>$admin,
            'content'=>'<p>Synthetic triage: scope and impact recorded; category and training service targets assigned. '.$case['resolution_plan'].'</p>']);
        insertLab('KnowbaseItem_Item',['itemtype'=>'Ticket','items_id'=>$id,'knowbaseitems_id'=>$kb]);
        if ($key==='HD-08') {
            updateLab($ticket,$id,['status'=>Ticket::WAITING]); stateLab($ticket,$id,'waiting for synthetic information',$entry,Ticket::WAITING);
            insertLab('ITILFollowup',['itemtype'=>'Ticket','items_id'=>$id,'is_private'=>0,'content'=>'Synthetic requester supplied the requested timestamps. No real VPN logs were collected.']);
            updateLab($ticket,$id,['status'=>Ticket::ASSIGNED]); stateLab($ticket,$id,'resumed',$entry,Ticket::ASSIGNED);
        }
        insertLab('ITILSolution',['itemtype'=>'Ticket','items_id'=>$id,'content'=>'<p>SIMULATED RESOLUTION: '.$case['resolution_plan'].'</p><p>The exercise workflow is complete. No real endpoint repair is claimed.</p>']);
        stateLab($ticket,$id,'solved',$entry,Ticket::SOLVED);
        if ($key==='HD-09') {
            updateLab($ticket,$id,['status'=>Ticket::ASSIGNED]); stateLab($ticket,$id,'reopened',$entry,Ticket::ASSIGNED);
            insertLab('ITILFollowup',['itemtype'=>'Ticket','items_id'=>$id,'is_private'=>0,'content'=>'Synthetic recurrence: the initial result was insufficient. A second diagnostic round was recorded.']);
            insertLab('ITILSolution',['itemtype'=>'Ticket','items_id'=>$id,'content'=>'Second simulated resolution: the fictional input-device selection was corrected and the scenario confirmed. No actual audio device was changed.']);
            stateLab($ticket,$id,'solved after reopen',$entry,Ticket::SOLVED);
        }
        insertLab('ITILFollowup',['itemtype'=>'Ticket','items_id'=>$id,'is_private'=>0,
            'content'=>'Simulated requester confirmation recorded by the lab administrator. This is not an independent user approval.']);
        updateLab($ticket,$id,['status'=>Ticket::CLOSED]); stateLab($ticket,$id,'closed by lab administrator',$entry,Ticket::CLOSED);
        $entry['final_status']=(int)$ticket->fields['status'];
        $report['cases'][]=$entry;
        file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    $report['completed']=count($report['cases'])===10;
    $report['finished_at_utc']=gmdate('c');
} catch (Throwable $e) {
    $report['error']=$e->getMessage();
    file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1);
}
file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['completed'=>$report['completed'],'cases'=>count($report['cases']),'checks'=>count($report['checks'])]),PHP_EOL;
