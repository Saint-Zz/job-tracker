<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
session_start();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function json_out($data, $status) {
    http_response_code($status ?: 200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_column($pdo, $table, $column, $definition) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute(array($table, $column));
    if ((int)$stmt->fetchColumn() === 0) $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
}

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $required = array('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD');
    $config = array();
    foreach ($required as $key) {
        $value = getenv($key);
        if ($value === false || $value === '') throw new RuntimeException('Missing required database environment configuration.');
        $config[$key] = $value;
    }
    $dsn = 'mysql:host=' . $config['DB_HOST'] . ';port=' . $config['DB_PORT'] . ';dbname=' . $config['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','user') NOT NULL DEFAULT 'user',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_resumes (
        id VARCHAR(36) PRIMARY KEY,name VARCHAR(255) NOT NULL,mime VARCHAR(100) NOT NULL,size_bytes INT UNSIGNED NOT NULL,
        role_pattern VARCHAR(255) NOT NULL DEFAULT '',is_default TINYINT(1) NOT NULL DEFAULT 0,file_data LONGBLOB NOT NULL,
        user_id INT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_applications (
        id VARCHAR(64) PRIMARY KEY,payload MEDIUMTEXT NOT NULL,resume_id VARCHAR(36) NULL,user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_resume_id (resume_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensure_column($pdo, 'job_resumes', 'user_id', 'INT UNSIGNED NULL');
    ensure_column($pdo, 'job_applications', 'user_id', 'INT UNSIGNED NULL');
    return $pdo;
}

function body_json() { $body = json_decode(file_get_contents('php://input'), true); return is_array($body) ? $body : array(); }
function csrf_token() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function check_csrf() { $given = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : ''; if (!$given || !hash_equals(csrf_token(), $given)) json_out(array('error'=>'请求验证失败，请刷新页面后重试'), 403); }
function current_user($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    $stmt=$pdo->prepare('SELECT id,username,role,is_active FROM app_users WHERE id=?'); $stmt->execute(array($_SESSION['user_id'])); $user=$stmt->fetch();
    if (!$user || !(int)$user['is_active']) { session_destroy(); return null; }
    return $user;
}
function require_user($pdo) { $user=current_user($pdo); if (!$user) json_out(array('error'=>'请先登录'),401); return $user; }
function require_admin($pdo) { $user=require_user($pdo); if ($user['role']!=='admin') json_out(array('error'=>'仅管理员可执行此操作'),403); return $user; }
function valid_username($name) { return preg_match('/^[\p{L}\p{N}_.-]{3,50}$/u', $name); }
function new_id() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535),mt_rand(16384,20479),mt_rand(32768,49151),mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535)); }

try {
    $pdo=db(); $action=isset($_GET['action'])?$_GET['action']:''; $method=$_SERVER['REQUEST_METHOD'];

    if ($action==='auth-status' && $method==='GET') {
        $count=(int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn(); $user=current_user($pdo);
        json_out(array('setup_required'=>$count===0,'user'=>$user,'csrf'=>$user?csrf_token():null),200);
    }
    if ($action==='setup-admin' && $method==='POST') {
        if ((int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn()!==0) json_out(array('error'=>'管理员已经创建'),409);
        $body=body_json(); $username=trim(isset($body['username'])?$body['username']:''); $password=isset($body['password'])?$body['password']:'';
        if (!valid_username($username)) json_out(array('error'=>'用户名需为 3–50 个汉字、字母、数字或 _.-'),400);
        if (strlen($password)<8) json_out(array('error'=>'密码至少需要 8 个字符'),400);
        $pdo->beginTransaction(); $stmt=$pdo->prepare("INSERT INTO app_users(username,password_hash,role) VALUES (?,?,'admin')"); $stmt->execute(array($username,password_hash($password,PASSWORD_DEFAULT))); $id=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE job_resumes SET user_id=? WHERE user_id IS NULL')->execute(array($id)); $pdo->prepare('UPDATE job_applications SET user_id=? WHERE user_id IS NULL')->execute(array($id)); $pdo->commit();
        session_regenerate_id(true); $_SESSION['user_id']=$id; json_out(array('ok'=>true),201);
    }
    if ($action==='login' && $method==='POST') {
        $body=body_json(); $username=trim(isset($body['username'])?$body['username']:''); $password=isset($body['password'])?$body['password']:'';
        $stmt=$pdo->prepare('SELECT id,username,password_hash,role,is_active FROM app_users WHERE username=?'); $stmt->execute(array($username)); $user=$stmt->fetch();
        if (!$user || !(int)$user['is_active'] || !password_verify($password,$user['password_hash'])) json_out(array('error'=>'用户名或密码错误'),401);
        session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; unset($_SESSION['csrf']); json_out(array('ok'=>true),200);
    }
    if ($action==='logout' && $method==='POST') { $_SESSION=array(); session_destroy(); json_out(array('ok'=>true),200); }

    $user=require_user($pdo); $userId=(int)$user['id'];

    if ($action==='users' && $method==='GET') { require_admin($pdo); $rows=$pdo->query('SELECT id,username,role,is_active,created_at FROM app_users ORDER BY created_at')->fetchAll(); json_out(array('users'=>$rows),200); }
    if ($action==='create-user' && $method==='POST') {
        require_admin($pdo); check_csrf(); $body=body_json(); $username=trim(isset($body['username'])?$body['username']:''); $password=isset($body['password'])?$body['password']:'';
        if (!valid_username($username)||strlen($password)<8) json_out(array('error'=>'用户名至少 3 个字符，密码至少 8 个字符'),400);
        try { $stmt=$pdo->prepare("INSERT INTO app_users(username,password_hash,role) VALUES (?,?,'user')"); $stmt->execute(array($username,password_hash($password,PASSWORD_DEFAULT))); } catch (Exception $e) { json_out(array('error'=>'用户名已经存在'),409); }
        json_out(array('ok'=>true),201);
    }
    if ($action==='reset-password' && $method==='POST') { require_admin($pdo); check_csrf(); $body=body_json(); $password=isset($body['password'])?$body['password']:''; if(strlen($password)<8)json_out(array('error'=>'密码至少需要 8 个字符'),400); $pdo->prepare('UPDATE app_users SET password_hash=? WHERE id=?')->execute(array(password_hash($password,PASSWORD_DEFAULT),(int)$body['id'])); json_out(array('ok'=>true),200); }
    if ($action==='toggle-user' && $method==='POST') { require_admin($pdo); check_csrf(); $body=body_json(); $id=(int)$body['id']; if($id===$userId)json_out(array('error'=>'不能停用当前管理员账号'),400); $pdo->prepare('UPDATE app_users SET is_active=? WHERE id=?')->execute(array(!empty($body['is_active'])?1:0,$id)); json_out(array('ok'=>true),200); }

    if ($action==='resumes' && $method==='GET') { $stmt=$pdo->prepare('SELECT id,name,mime,size_bytes,role_pattern,is_default,created_at FROM job_resumes WHERE user_id=? ORDER BY created_at DESC'); $stmt->execute(array($userId)); json_out(array('resumes'=>$stmt->fetchAll()),200); }
    if ($action==='upload' && $method==='POST') {
        check_csrf(); if(!isset($_FILES['resume'])||$_FILES['resume']['error']!==UPLOAD_ERR_OK)json_out(array('error'=>'文件上传失败'),400); $file=$_FILES['resume']; if($file['size']>10*1024*1024)json_out(array('error'=>'文件不能超过 10MB'),400);
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)); $allowed=array('pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'); if(!isset($allowed[$ext]))json_out(array('error'=>'仅支持 PDF、DOC、DOCX'),400);
        $id=new_id(); $pattern=trim(isset($_POST['role_pattern'])?$_POST['role_pattern']:''); $isDefault=!empty($_POST['is_default'])?1:0; if($isDefault)$pdo->prepare('UPDATE job_resumes SET is_default=0 WHERE user_id=?')->execute(array($userId));
        $stmt=$pdo->prepare('INSERT INTO job_resumes(id,name,mime,size_bytes,role_pattern,is_default,file_data,user_id) VALUES (?,?,?,?,?,?,?,?)'); $stmt->bindValue(1,$id);$stmt->bindValue(2,basename($file['name']));$stmt->bindValue(3,$allowed[$ext]);$stmt->bindValue(4,$file['size'],PDO::PARAM_INT);$stmt->bindValue(5,$pattern);$stmt->bindValue(6,$isDefault,PDO::PARAM_INT);$stmt->bindValue(7,file_get_contents($file['tmp_name']),PDO::PARAM_LOB);$stmt->bindValue(8,$userId,PDO::PARAM_INT);$stmt->execute(); json_out(array('ok'=>true,'id'=>$id),201);
    }
    if ($action==='download' && $method==='GET') { $stmt=$pdo->prepare('SELECT name,mime,file_data FROM job_resumes WHERE id=? AND user_id=?');$stmt->execute(array(isset($_GET['id'])?$_GET['id']:'',$userId));$row=$stmt->fetch();if(!$row)json_out(array('error'=>'简历不存在'),404);header('Content-Type: '.$row['mime']);header('Content-Disposition: inline; filename*=UTF-8\'\''.rawurlencode($row['name']));echo $row['file_data'];exit; }
    if ($action==='delete-resume' && $method==='POST') { check_csrf();$body=body_json();$id=isset($body['id'])?$body['id']:'';$pdo->prepare('UPDATE job_applications SET resume_id=NULL WHERE resume_id=? AND user_id=?')->execute(array($id,$userId));$pdo->prepare('DELETE FROM job_resumes WHERE id=? AND user_id=?')->execute(array($id,$userId));json_out(array('ok'=>true),200); }

    if ($action==='jobs' && $method==='GET') { $stmt=$pdo->prepare('SELECT id,payload,resume_id FROM job_applications WHERE user_id=? ORDER BY created_at DESC');$stmt->execute(array($userId));$jobs=array();foreach($stmt->fetchAll() as $row){$item=json_decode($row['payload'],true);$item['id']=$row['id'];$item['resumeId']=$row['resume_id'];$jobs[]=$item;}json_out(array('jobs'=>$jobs),200); }
    if ($action==='save-job' && $method==='POST') { check_csrf();$body=body_json();if(empty($body['id']))json_out(array('error'=>'投递数据无效'),400);$id=$body['id'];$resumeId=!empty($body['resumeId'])?$body['resumeId']:null;if($resumeId){$check=$pdo->prepare('SELECT COUNT(*) FROM job_resumes WHERE id=? AND user_id=?');$check->execute(array($resumeId,$userId));if(!(int)$check->fetchColumn())$resumeId=null;}unset($body['id'],$body['resumeId']);$stmt=$pdo->prepare('INSERT INTO job_applications(id,payload,resume_id,user_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE payload=IF(user_id=VALUES(user_id),VALUES(payload),payload),resume_id=IF(user_id=VALUES(user_id),VALUES(resume_id),resume_id)');$stmt->execute(array($id,json_encode($body,JSON_UNESCAPED_UNICODE),$resumeId,$userId));json_out(array('ok'=>true),200); }
    if ($action==='import-jobs' && $method==='POST') {
        check_csrf();$body=body_json();$incoming=isset($body['jobs'])&&is_array($body['jobs'])?$body['jobs']:array();if(count($incoming)>1000)json_out(array('error'=>'单次最多导入 1000 条记录'),400);
        $stmt=$pdo->prepare('SELECT payload FROM job_applications WHERE user_id=?');$stmt->execute(array($userId));$keys=array();foreach($stmt->fetchAll() as $row){$item=json_decode($row['payload'],true);$keys[trim(isset($item['公司名称'])?$item['公司名称']:'').'|'.trim(isset($item['职位名称'])?$item['职位名称']:'').'|'.trim(isset($item['投递日期'])?$item['投递日期']:'')]=true;}
        $insert=$pdo->prepare('INSERT INTO job_applications(id,payload,resume_id,user_id) VALUES (?,?,?,?)');$imported=0;$skipped=0;$invalid=0;$pdo->beginTransaction();foreach($incoming as $item){$company=trim(isset($item['公司名称'])?$item['公司名称']:'');$role=trim(isset($item['职位名称'])?$item['职位名称']:'');$date=trim(isset($item['投递日期'])?$item['投递日期']:'');if($role===''){$invalid++;continue;}$key=$company.'|'.$role.'|'.$date;if(isset($keys[$key])){$skipped++;continue;}$id=isset($item['id'])?$item['id']:uniqid('job-',true);$resumeId=!empty($item['resumeId'])?$item['resumeId']:null;if($resumeId){$check=$pdo->prepare('SELECT COUNT(*) FROM job_resumes WHERE id=? AND user_id=?');$check->execute(array($resumeId,$userId));if(!(int)$check->fetchColumn())$resumeId=null;}unset($item['id'],$item['resumeId']);$insert->execute(array($id,json_encode($item,JSON_UNESCAPED_UNICODE),$resumeId,$userId));$keys[$key]=true;$imported++;}$pdo->commit();json_out(array('ok'=>true,'imported'=>$imported,'skipped'=>$skipped,'invalid'=>$invalid),200);
    }
    json_out(array('error'=>'接口不存在'),404);
} catch (Exception $e) { if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();json_out(array('error'=>'服务器暂时不可用','detail'=>$e->getMessage()),500); }
