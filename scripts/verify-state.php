<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('GLPI_DB_NAME') !== 'atlas_helpdesk') { throw new RuntimeException('Wrong lab scope'); }
require '/var/www/glpi/vendor/autoload.php'; $kernel=new Glpi\Kernel\Kernel(); $kernel->boot();
global $DB, $CFG_GLPI;
$out=['observed_at_utc'=>gmdate('c'),'version'=>GLPI_VERSION,'checks'=>[],'tickets'=>[]];
function verify(bool $ok,string $name): void { global $out; $out['checks'][]=['name'=>$name,'passed'=>$ok]; }
function rows(string $table, array $where=[],array $select=['*']): array {
    global $DB; return array_values(iterator_to_array($DB->request(['SELECT'=>$select,'FROM'=>$table,'WHERE'=>$where])));
}
$tickets=rows('glpi_tickets',[],['id','externalid','name','status','priority','type','itilcategories_id','slas_id_tto','slas_id_ttr','time_to_own','time_to_resolve','date','solvedate','closedate']);
verify(count($tickets)===10,'Exactly ten tickets');
foreach ($tickets as $ticket) {
    $id=(int)$ticket['id']; $key=$ticket['externalid'];
    verify(str_starts_with($key,'ATLAS-LAB:'),$key.' owned marker');
    verify((int)$ticket['status']===6,$key.' closed');
    $requesters=rows('glpi_tickets_users',['tickets_id'=>$id,'type'=>1],['users_id','use_notification']);
    $assignees=rows('glpi_tickets_users',['tickets_id'=>$id,'type'=>2],['users_id']);
    $groups=rows('glpi_groups_tickets',['tickets_id'=>$id,'type'=>2],['groups_id']);
    $knowledge=rows('glpi_knowbaseitems_items',['itemtype'=>'Ticket','items_id'=>$id],['knowbaseitems_id']);
    $solutions=rows('glpi_itilsolutions',['itemtype'=>'Ticket','items_id'=>$id],['id','status','content']);
    $followups=rows('glpi_itilfollowups',['itemtype'=>'Ticket','items_id'=>$id],['id','content']);
    $history=rows('glpi_logs',['itemtype'=>'Ticket','items_id'=>$id,'id_search_option'=>12],['date_mod','old_value','new_value']);
    verify(count($requesters)===1,$key.' one requester');
    verify(count($assignees)===1 && (int)$assignees[0]['users_id']===2,$key.' lab administrator assigned');
    verify(count($groups)===1,$key.' assignment group');
    verify(count($knowledge)===1,$key.' knowledge link');
    verify(count($solutions)===($key==='ATLAS-LAB:HD-09'?2:1),$key.' solutions retained');
    verify(count($followups)>=2,$key.' triage and confirmation notes');
    verify(!empty($ticket['time_to_own'])&&!empty($ticket['time_to_resolve']),$key.' both SLA targets calculated');
    if (count($requesters)===1) {
        $user=rows('glpi_users',['id'=>$requesters[0]['users_id']],['name','is_active']);
        verify(count($user)===1 && $user[0]['name']==='atlas-'.strtolower(substr($key,10)) && (int)$user[0]['is_active']===0,$key.' disabled synthetic requester');
    }
    if ($key==='ATLAS-LAB:HD-08') {
        verify(count(array_filter($history,fn($h)=>$h['old_value']=='2'&&$h['new_value']=='4'))===1,$key.' pending transition in stored audit');
        verify(count(array_filter($history,fn($h)=>$h['old_value']=='4'&&$h['new_value']=='2'))===1,$key.' resumed transition in stored audit');
    }
    if ($key==='ATLAS-LAB:HD-09') {
        verify(count(array_filter($history,fn($h)=>$h['old_value']=='5'&&$h['new_value']=='2'))===1,$key.' reopened transition in stored audit');
    }
    $ticket['requesters']=$requesters; $ticket['assigned_users']=$assignees; $ticket['assigned_groups']=$groups;
    $ticket['knowledge']=$knowledge; $ticket['solution_count']=count($solutions); $ticket['followup_count']=count($followups); $ticket['status_history']=$history;
    $out['tickets'][]=$ticket;
}
$out['slas']=rows('glpi_slas',[],['id','name','type','number_time','definition_time','calendars_id']);
$out['knowledge']=rows('glpi_knowbaseitems',['name'=>['LIKE','ATLAS-LAB %']],['id','name','is_faq']);
$out['timestamp_context']=['php_timezone'=>date_default_timezone_get(),'php_now'=>date('c'),'utc_now'=>gmdate('c')];
$out['notifications_enabled']=(bool)($CFG_GLPI['use_notifications']??1);
verify(count($out['slas'])===10,'Five priority levels with two targets each');
verify(count($out['knowledge'])===10,'Ten knowledge articles');
verify(!$out['notifications_enabled'],'Notifications disabled');
$out['passed']=count(array_filter($out['checks'],fn($c)=>$c['passed']));
$out['failed']=count($out['checks'])-$out['passed'];
file_put_contents('/tmp/atlas-verified-state.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['tickets'=>count($tickets),'passed'=>$out['passed'],'failed'=>$out['failed']]),PHP_EOL;
exit($out['failed']===0?0:1);
