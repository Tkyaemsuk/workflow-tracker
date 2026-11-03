<?php
// Run with PHP CLI via cron once daily. Do not expose it through the web server.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
require dirname(__DIR__).'/config/bootstrap.php';
$today=date('Y-m-d');
$soon=date('Y-m-d',strtotime('+3 days'));
$q=db()->prepare("SELECT id,task_name,due_date,assigned_to,status FROM works WHERE assigned_to IS NOT NULL AND status <> 'เสร็จสิ้น' AND due_date <= ?");
$q->execute([$soon]);$counts=['due_soon'=>0,'overdue'=>0];
foreach($q->fetchAll() as $task) {
    $overdue=$task['due_date'] < $today;
    $type=$overdue?'overdue':'due_soon';
    $day=$overdue?$today:$task['due_date'];
    $key='reminder:'.$type.':'.$task['id'].':'.$day;
    $msg=$overdue?'งานเกินกำหนดส่ง: ':'งานใกล้ครบกำหนดส่ง: ';
    $msg.=$task['task_name'].' ('.$task['due_date'].')';
    $stmt=db()->prepare('INSERT IGNORE INTO task_notifications(user_id,work_id,type,message,event_key) VALUES(?,?,?,?,?)');
    $stmt->execute([(int)$task['assigned_to'],(int)$task['id'],$type,$msg,$key]);
    $counts[$type]+=$stmt->rowCount();
}
echo json_encode(['ok'=>true,'date'=>$today,'created'=>$counts],JSON_UNESCAPED_UNICODE).PHP_EOL;
