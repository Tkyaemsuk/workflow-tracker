<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
header('X-Content-Type-Options: nosniff');
try {
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    if ($action === 'login') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['ok'=>false,'message'=>'Method not allowed'],405);
        verifyCsrf();
        $identity = postStr('identity',150); $pass = (string)($_POST['password'] ?? '');
        $q = db()->prepare('SELECT id,password,is_active FROM users WHERE username=? OR email=? LIMIT 1');
        $q->execute([$identity,$identity]); $row = $q->fetch();
        if (!$row || !(int)$row['is_active'] || !password_verify($pass,$row['password'])) {
            jsonResponse(['ok'=>false,'message'=>'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],422);
        }
        session_regenerate_id(true); $_SESSION['uid']=(int)$row['id']; csrf();
        audit((int)$row['id'],'login_success','เข้าสู่ระบบ WorkFlow Tracker');
        jsonResponse(['ok'=>true,'message'=>'เข้าสู่ระบบสำเร็จ']);
    }
    if ($action === 'csrf') jsonResponse(['ok'=>true,'csrf'=>csrf(),'logged_in'=>(bool)user()]);
    $u = requireUser(); $uid = (int)$u['id'];
    if ($action === 'me') jsonResponse(['ok'=>true,'user'=>$u,'csrf'=>csrf()]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') verifyCsrf();
    if ($action === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['ok'=>false,'message'=>'Method not allowed'],405);
        audit($uid,'logout','ออกจากระบบ WorkFlow Tracker');
        $_SESSION=[]; session_destroy(); jsonResponse(['ok'=>true]);
    }
    if ($action === 'users') {
        $q=db()->query('SELECT id,name,role,department_id,manager_id FROM users WHERE is_active=1 ORDER BY name');
        $rows=array_values(array_filter($q->fetchAll(),fn($v)=>canAssign($u,(int)$v['id'])));
        jsonResponse(['ok'=>true,'users'=>$rows]);
    }
    if ($action === 'projects') {
        if (isGlobal($u)) $q=db()->query('SELECT id,project_name FROM projects ORDER BY id DESC');
        else { $q=db()->prepare('SELECT DISTINCT p.id,p.project_name FROM projects p LEFT JOIN works w ON w.project_id=p.id WHERE p.created_by=? OR w.assigned_to=? OR w.created_by=? ORDER BY p.id DESC'); $q->execute([$uid,$uid,$uid]); }
        jsonResponse(['ok'=>true,'projects'=>$q->fetchAll()]);
    }
    if ($action === 'tasks' || $action === 'dashboard') {
        $sql='SELECT w.*,p.project_name,ua.name AS assignee_name,uc.name AS creator_name, d.dept_name FROM works w LEFT JOIN projects p ON p.id=w.project_id LEFT JOIN users ua ON ua.id=w.assigned_to LEFT JOIN users uc ON uc.id=w.created_by LEFT JOIN departments d ON d.id=w.department_id ORDER BY w.due_date ASC,w.id DESC';
        $rows=array_values(array_filter(db()->query($sql)->fetchAll(),fn($r)=>canSeeTask($u,$r)));
        if ($action === 'tasks') jsonResponse(['ok'=>true,'tasks'=>$rows]);
        $stats=['total'=>count($rows),'todo'=>0,'doing'=>0,'review'=>0,'done'=>0,'overdue'=>0,'soon'=>0];
        $today=date('Y-m-d');$soon=date('Y-m-d',strtotime('+3 days'));
        foreach($rows as $t) {
            $key=['ได้รับมอบหมาย'=>'todo','กำลังทำ'=>'doing','ตรวจงาน'=>'review','เสร็จสิ้น'=>'done'][$t['status']] ?? null;
            if($key) $stats[$key]++;
            if($t['status']!=='เสร็จสิ้น') { if($t['due_date']<$today)$stats['overdue']++; elseif($t['due_date']<=$soon)$stats['soon']++; }
        }
        jsonResponse(['ok'=>true,'stats'=>$stats,'tasks'=>array_slice($rows,0,8)]);
    }
    if ($action === 'create_task') {
        $name=postStr('task_name');$desc=postStr('description',10000);$due=postStr('due_date',10);
        $priority=postStr('priority',6);$assigned=postInt('assigned_to') ?: $uid;$project=postInt('project_id') ?: null;
        if (!$name || !dateValid($due) || !in_array($priority,['low','medium','high'],true)) jsonResponse(['ok'=>false,'message'=>'ตรวจสอบชื่องาน วันที่ และความสำคัญ'],422);
        if (!canAssign($u,$assigned)) jsonResponse(['ok'=>false,'message'=>'ไม่มีสิทธิ์มอบหมายงานให้ผู้ใช้นี้'],403);
        if ($project) { $q=db()->prepare('SELECT created_by FROM projects WHERE id=?');$q->execute([$project]); $p=$q->fetch(); if(!$p || (!isGlobal($u) && (int)$p['created_by']!==$uid)) jsonResponse(['ok'=>false,'message'=>'ไม่มีสิทธิ์ใช้โครงการนี้'],403); }
        $q=db()->prepare('INSERT INTO works(project_id,task_name,description,due_date,status,priority,assigned_to,department_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
        $q->execute([$project,$name,$desc,$due,'ได้รับมอบหมาย',$priority,$assigned,$u['department_id'],$uid]);
        $id=(int)db()->lastInsertId();
        $h=db()->prepare('INSERT INTO status_history(ref_type,ref_id,new_status,changed_by,note) VALUES(?,?,?,?,?)');$h->execute(['work',$id,'ได้รับมอบหมาย',$uid,'สร้างงานใหม่']);
        notifyUser($assigned,'assigned',$id,'คุณได้รับมอบหมายงาน: '.$name,'assigned:'.$id.':'.$assigned);
        audit($uid,'task_create','เพิ่มงาน: '.$name);
        jsonResponse(['ok'=>true,'message'=>'สร้างงานสำเร็จ','id'=>$id]);
    }
    if ($action === 'update_status') {
        $id=postInt('id');$next=postStr('status',30);$t=taskById($id);
        if(!$t || !canSeeTask($u,$t))jsonResponse(['ok'=>false,'message'=>'ไม่พบงานหรือไม่มีสิทธิ์'],403);
        $allowed=['ได้รับมอบหมาย'=>['กำลังทำ'],'กำลังทำ'=>['ตรวจงาน'],'ตรวจงาน'=>['เสร็จสิ้น','กำลังทำ'],'เสร็จสิ้น'=>['กำลังทำ']];
        if (!in_array($next,$allowed[$t['status']] ?? [],true)) jsonResponse(['ok'=>false,'message'=>'ไม่สามารถเปลี่ยนสถานะในลำดับนี้'],422);
        $reviewDecision=$t['status']==='ตรวจงาน' || $t['status']==='เสร็จสิ้น';
        if ($reviewDecision && !(isLeader($u) && ($uid===(int)$t['created_by'] || isGlobal($u) || canAssign($u,(int)$t['assigned_to'])))) jsonResponse(['ok'=>false,'message'=>'เฉพาะหัวหน้าหรือผู้มอบหมายเท่านั้นที่ตรวจรับ/ส่งกลับงานได้'],403);
        if (!$reviewDecision && $uid!==(int)$t['assigned_to'] && $uid!==(int)$t['created_by'] && !isGlobal($u)) jsonResponse(['ok'=>false,'message'=>'ไม่มีสิทธิ์เปลี่ยนสถานะ'],403);
        db()->beginTransaction();
        $q=db()->prepare('UPDATE works SET status=? WHERE id=? AND status=?');$q->execute([$next,$id,$t['status']]);
        if($q->rowCount()!==1){db()->rollBack();jsonResponse(['ok'=>false,'message'=>'ข้อมูลถูกเปลี่ยนโดยผู้อื่น กรุณารีเฟรช'],409);}
        $h=db()->prepare('INSERT INTO status_history(ref_type,ref_id,old_status,new_status,changed_by,note) VALUES(?,?,?,?,?,?)');
        $h->execute(['work',$id,$t['status'],$next,$uid,postStr('note',1000)]);
        db()->commit();
        $recipient=$next==='ตรวจงาน'?(int)$t['created_by']:(int)$t['assigned_to'];
        notifyUser($recipient,'status',$id,'งาน "'.$t['task_name'].'" เปลี่ยนเป็น '.$next,'status:'.$id.':'.time().':'.$recipient);
        audit($uid,'task_status','เปลี่ยนสถานะงาน #'.$id.' เป็น '.$next);
        jsonResponse(['ok'=>true,'message'=>'อัปเดตสถานะเรียบร้อย']);
    }
    if ($action === 'comments') {
        $id=(int)($_GET['id']??0);$t=taskById($id);
        if(!$t || !canSeeTask($u,$t))jsonResponse(['ok'=>false,'message'=>'ไม่มีสิทธิ์'],403);
        $q=db()->prepare('SELECT c.id,c.body,c.created_at,u.name FROM task_comments c JOIN users u ON u.id=c.user_id WHERE c.work_id=? ORDER BY c.id ASC');
        $q->execute([$id]);jsonResponse(['ok'=>true,'comments'=>$q->fetchAll()]);
    }
    if ($action === 'add_comment') {
        $id=postInt('id');$body=postStr('body',5000);$t=taskById($id);
        if(!$t || !canSeeTask($u,$t))jsonResponse(['ok'=>false,'message'=>'ไม่มีสิทธิ์'],403);
        if(!$body)jsonResponse(['ok'=>false,'message'=>'กรุณาพิมพ์ข้อความ'],422);
        $q=db()->prepare('INSERT INTO task_comments(work_id,user_id,body) VALUES(?,?,?)');$q->execute([$id,$uid,$body]);
        foreach(array_unique([(int)$t['assigned_to'],(int)$t['created_by']]) as $other) {
            if($other>0 && $other!==$uid)notifyUser($other,'comment',$id,'มีความคิดเห็นใหม่ในงาน: '.$t['task_name'],'comment:'.db()->lastInsertId().':'.$other);
        }
        jsonResponse(['ok'=>true,'message'=>'ส่งความคิดเห็นแล้ว']);
    }
    if ($action === 'notifications') {
        $q=db()->prepare('SELECT id,work_id,type,message,is_read,created_at FROM task_notifications WHERE user_id=? ORDER BY id DESC LIMIT 30');
        $q->execute([$uid]);$rows=$q->fetchAll();
        jsonResponse(['ok'=>true,'notifications'=>$rows,'unread'=>count(array_filter($rows,fn($x)=>(int)$x['is_read']===0))]);
    }
    if ($action === 'read_notifications') {
        $q=db()->prepare('UPDATE task_notifications SET is_read=1 WHERE user_id=? AND is_read=0');$q->execute([$uid]);
        jsonResponse(['ok'=>true]);
    }
    jsonResponse(['ok'=>false,'message'=>'ไม่พบ API action'],404);
} catch (PDOException $e) {
    error_log('DB exception: '.$e->getMessage());
    jsonResponse(['ok'=>false,'message'=>'เกิดข้อผิดพลาดกับฐานข้อมูล กรุณาตรวจสอบการติดตั้ง'],500);
} catch (Throwable $e) {
    error_log('App exception: '.$e->getMessage());
    jsonResponse(['ok'=>false,'message'=>'เกิดข้อผิดพลาดภายในระบบ'],500);
}
