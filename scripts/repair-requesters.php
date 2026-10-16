<?php
// Correct the missing requester links from the first run, without enabling accounts.
if (PHP_SAPI !== 'cli' || getenv('GLPI_DB_NAME') !== 'atlas_helpdesk') { throw new RuntimeException('Wrong scope'); }
require '/var/www/glpi/vendor/autoload.php'; $kernel=new Glpi\Kernel\Kernel(); $kernel->boot();
$auth=new Auth();
if (!$auth->login('glpi',trim(file_get_contents('/tmp/atlas-admin-password.txt')),true,false,'local')) { throw new RuntimeException('Login failed'); }
$report=['observed_at_utc'=>gmdate('c'),'reason'=>'Disabled synthetic users were not linked by the initial Ticket add input','links'=>[]];
foreach ((new Ticket())->find([]) as $row) {
    if (!preg_match('/^ATLAS-LAB:(HD-\d{2})$/',$row['externalid'],$m)) { throw new RuntimeException('Unrelated ticket preserved'); }
    $user=new User();
    if (!$user->getFromDBbyName('atlas-'.strtolower($m[1])) || $user->fields['is_active'] != 0) { throw new RuntimeException('Unexpected requester'); }
    $relation=new Ticket_User();
    $criteria=['tickets_id'=>$row['id'],'users_id'=>$user->fields['id'],'type'=>CommonITILActor::REQUESTER];
    if (!$relation->find($criteria)) {
        if (!$relation->add($criteria+['use_notification'=>0])) { throw new RuntimeException('Requester link failed'); }
    }
    $ticket=new Ticket(); $ticket->getFromDB($row['id']);
    if ($ticket->fields['status'] != Ticket::CLOSED) { throw new RuntimeException('Closed state changed unexpectedly'); }
    $report['links'][]=['ticket_id'=>(int)$row['id'],'requester_id'=>(int)$user->fields['id'],'requester_active'=>false,'final_status'=>(int)$ticket->fields['status']];
}
file_put_contents('/tmp/atlas-requester-correction.json',json_encode($report,JSON_PRETTY_PRINT));
echo 'Verified requester links: '.count($report['links']).PHP_EOL;
