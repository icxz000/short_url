<?php
/**
 * 短链接转换网站
 */

// ============================================
// 数据库配置
// ============================================
define('DB_HOST',    'xxxx');
define('DB_USER',    'xxxx');
define('DB_PASS',    'xxxx');
define('DB_NAME',    'xxxx');
define('DB_PORT',    3306);
define('ADMIN_PATH', 'panel');
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123');

// ============================================
// 数据库
// ============================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
            );
        } catch (PDOException $e) {
            die(json_encode(['error' => '数据库连接失败: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

function initDB() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) NOT NULL UNIQUE,
        url TEXT NOT NULL,
        title VARCHAR(255) DEFAULT '',
        clicks INT UNSIGNED DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ============================================
// 工具函数
// ============================================
function getBaseUrl() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function generateCode($len = 6) {
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $code = '';
    for ($i = 0; $i < $len; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}
function codeExists($code) {
    $db = getDB();
    $s = $db->prepare('SELECT 1 FROM links WHERE code=? LIMIT 1');
    $s->execute([$code]);
    return $s->fetch() !== false;
}
function generateUniqueCode($len = 6) {
    $code = generateCode($len);
    $a = 0;
    while (codeExists($code) && $a < 10) { $code = generateCode($len); $a++; }
    if ($a >= 10) { $code = generateCode(8); while (codeExists($code)) $code = generateCode(8); }
    return $code;
}
function isValidUrl($url) {
    // 必须以 http:// 或 https:// 开头
    if (!preg_match('/^https?:\/\//i', $url)) return false;
    // filter_var 基础校验
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    // 提取 host 部分
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    if (!$host) return false;
    // 禁止纯数字、纯字母、含中文等非合法域名字符
    if (preg_match('/[\x{4e00}-\x{9fff}]/u', $host)) return false;
    // 域名必须含至少一个点（即有 TLD），且由合法字符组成
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $host)) return false;
    return true;
}
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 路由
// ============================================
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$scriptDir  = rtrim(dirname($scriptName), '/');
$uri = ($scriptDir && strpos($requestUri, $scriptDir) === 0) ? substr($requestUri, strlen($scriptDir)) : $requestUri;
$uri = '/' . trim($uri, '/');
$action = $_GET['action'] ?? '';

initDB();

// ============================================
// API: 缩短
// ============================================
if ($action === 'shorten' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $url = trim($input['url'] ?? '');
    $title = trim($input['title'] ?? '');

    if (!$url) jsonResponse(['error' => '请输入URL'], 400);
    if (!isValidUrl($url)) jsonResponse(['error' => 'URL格式不正确，请输入以 http:// 或 https:// 开头且包含有效域名的网址（如 https://example.com）'], 400);
    if (strlen($url) > 2048) jsonResponse(['error' => 'URL过长'], 400);

    $db = getDB();
    $s = $db->prepare('SELECT code, clicks, created_at FROM links WHERE url=? LIMIT 1');
    $s->execute([$url]);
    $ex = $s->fetch();
    if ($ex) {
        jsonResponse(['success' => true, 'short_url' => getBaseUrl().'/'.$ex['code'], 'code' => $ex['code'], 'clicks' => (int)$ex['clicks'], 'is_new' => false, 'created_at' => $ex['created_at']]);
    }
    $code = generateUniqueCode();
    $db->prepare('INSERT INTO links (code,url,title) VALUES (?,?,?)')->execute([$code, $url, $title]);
    jsonResponse(['success' => true, 'short_url' => getBaseUrl().'/'.$code, 'code' => $code, 'clicks' => 0, 'is_new' => true]);
}

// ============================================
// 管理员会话
// ============================================
session_start();
function isAdmin() { return isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true; }

// ============================================
// 管理员页面: /{ADMIN_PATH}
// ============================================
if ($uri === '/' . ADMIN_PATH) {
    // 登出
    if ($action === 'logout') { session_destroy(); header('Location: /' . ADMIN_PATH); exit; }

    // 登录处理
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');
        if ($u === ADMIN_USER && $p === ADMIN_PASS) {
            $_SESSION['admin_logged'] = true;
            header('Location: /' . ADMIN_PATH);
            exit;
        }
        $loginError = '用户名或密码错误';
    }

    // 管理员 API
    if (isAdmin()) {
        // 删除链接
        if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                getDB()->prepare('DELETE FROM links WHERE id=?')->execute([$id]);
            }
            header('Location: /' . ADMIN_PATH);
            exit;
        }
        // 清空所有
        if ($action === 'clear') {
            getDB()->exec('DELETE FROM links');
            header('Location: /' . ADMIN_PATH);
            exit;
        }
        // 统计
        if ($action === 'admin-stats') {
            $db = getDB();
            $s = $db->query('SELECT COUNT(*) as total, SUM(clicks) as clicks FROM links');
            $st = $s->fetch();
            $s2 = $db->query('SELECT DATE(created_at) as day, COUNT(*) as cnt FROM links GROUP BY day ORDER BY day DESC LIMIT 7');
            jsonResponse(['total' => (int)($st['total']??0), 'clicks' => (int)($st['clicks']??0), 'daily' => $s2->fetchAll()]);
        }
    }

    // === 管理员页面 HTML ===
    if (!isAdmin() && !isset($loginError)) $loginError = null;
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理面板</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',system-ui,sans-serif;background:#09090b;color:#e4e4e7;min-height:100vh}
    .admin-wrap{max-width:800px;margin:0 auto;padding:40px 20px}

    /* 登录表单 */
    .login-card{
        max-width:380px;margin:80px auto;
        background:rgba(255,255,255,.03);backdrop-filter:blur(20px);
        border:1px solid rgba(255,255,255,.06);border-radius:20px;padding:36px;
    }
    .login-card h2{font-size:20px;font-weight:700;margin-bottom:24px;color:#fff;text-align:center}
    .login-card label{font-size:12px;font-weight:600;color:#71717a;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:6px}
    .login-card input{
        width:100%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
        border-radius:12px;padding:12px 16px;color:#fff;font-size:14px;font-family:inherit;
        outline:none;margin-bottom:16px;transition:all .2s;
    }
    .login-card input:focus{border-color:rgba(99,102,241,.4);box-shadow:0 0 0 3px rgba(99,102,241,.08)}
    .login-card button{
        width:100%;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;
        border:none;padding:14px;border-radius:12px;font-size:14px;font-weight:600;
        cursor:pointer;transition:all .25s;font-family:inherit;
    }
    .login-card button:hover{box-shadow:0 8px 24px rgba(99,102,241,.4)}
    .login-err{text-align:center;color:#f87171;font-size:13px;margin-bottom:16px}

    /* 管理面板 */
    .admin-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px}
    .admin-header h1{font-size:22px;font-weight:700;color:#fff}
    .admin-header a{color:#a5b4fc;font-size:13px;text-decoration:none;font-weight:500}
    .admin-header a:hover{text-decoration:underline}

    .admin-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:32px}
    .a-stat{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:20px;text-align:center}
    .a-stat .v{font-size:28px;font-weight:800;background:linear-gradient(135deg,#fff,#a5b4fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
    .a-stat .l{font-size:11px;color:#52525b;margin-top:4px;letter-spacing:.5px}

    .admin-actions{display:flex;gap:8px;margin-bottom:20px}
    .admin-actions a{
        display:inline-flex;align-items:center;gap:6px;
        background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);
        padding:10px 16px;border-radius:10px;color:#a5b4fc;font-size:13px;
        text-decoration:none;transition:all .2s;font-weight:500;
    }
    .admin-actions a:hover{background:rgba(99,102,241,.08);border-color:rgba(99,102,241,.15)}
    .admin-actions a.danger{color:#f87171}
    .admin-actions a.danger:hover{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.15)}

    .link-table{width:100%;border-collapse:collapse}
    .link-table th{
        text-align:left;padding:12px 16px;font-size:11px;font-weight:600;
        color:#52525b;text-transform:uppercase;letter-spacing:1px;
        border-bottom:1px solid rgba(255,255,255,.06);
    }
    .link-table td{
        padding:14px 16px;font-size:13px;
        border-bottom:1px solid rgba(255,255,255,.03);
        vertical-align:middle;
    }
    .link-table tr:hover td{background:rgba(255,255,255,.01)}
    .link-table .code{color:#a5b4fc;font-weight:600}
    .link-table .url{color:#71717a;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .link-table .clicks{color:#52525b;text-align:center}
    .link-table .time{color:#3f3f46;font-size:12px}
    .link-table .del-btn{
        background:none;border:1px solid rgba(239,68,68,.15);color:#f87171;
        padding:6px 12px;border-radius:8px;font-size:12px;cursor:pointer;
        transition:all .2s;font-family:inherit;
    }
    .link-table .del-btn:hover{background:rgba(239,68,68,.1)}

    .empty{text-align:center;color:#3f3f46;padding:60px 20px;font-size:14px}

    /* ============ 移动端管理面板 ============ */
    @media(max-width:600px){
        @supports(padding: env(safe-area-inset-bottom)){
            .admin-wrap{padding-bottom:calc(20px + env(safe-area-inset-bottom))}
        }
        .admin-wrap{padding:20px 12px}
        .admin-header{flex-direction:column;gap:12px;align-items:flex-start}
        .admin-header h1{font-size:18px}
        .admin-header div{width:100%;justify-content:space-between}
        .admin-stats{grid-template-columns:1fr;gap:8px}
        .a-stat{padding:14px;border-radius:12px}
        .a-stat .v{font-size:22px}
        /* 表格改为卡片布局 */
        .link-table,.link-table thead,.link-table tbody,.link-table tr,.link-table td,.link-table th{display:block}
        .link-table thead{display:none}
        .link-table tr{
            background:rgba(255,255,255,.02);
            border:1px solid rgba(255,255,255,.05);
            border-radius:12px;
            margin-bottom:10px;
            padding:12px;
        }
        .link-table td{
            padding:6px 0;border:none;
            display:flex;align-items:center;justify-content:space-between;
        }
        .link-table td::before{
            content:attr(data-label);
            font-size:11px;font-weight:600;color:#52525b;
            text-transform:uppercase;letter-spacing:.5px;flex-shrink:0;margin-right:12px;
        }
        .link-table .code{font-size:15px}
        .link-table .url{
            max-width:none;white-space:normal;word-break:break-all;font-size:12px;
            text-align:right;flex:1;justify-content:flex-end;
        }
        .link-table .clicks{justify-content:flex-end}
        .link-table .time{font-size:11px}
        .link-table .del-btn{padding:8px 14px;font-size:12px}
        /* 分页 */
        .admin-actions{flex-wrap:wrap}
        .admin-actions a{flex:1;justify-content:center;min-height:44px;font-size:12px}
        .login-card{margin:40px 16px;padding:28px;border-radius:16px}
        .login-card h2{font-size:18px}
        .login-card input{padding:14px;font-size:15px;border-radius:10px}
        .login-card button{padding:14px;font-size:15px;min-height:48px;border-radius:10px}
    }
    @media(max-width:360px){
        .admin-wrap{padding:12px 8px}
        .admin-header h1{font-size:16px}
        .a-stat .v{font-size:20px}
    }
    </style>
    </head>
    <body>
    <?php if (!isAdmin()): ?>
    <!-- 登录 -->
    <div class="login-card">
        <h2>🔒 管理面板</h2>
        <?php if (isset($loginError) && $loginError): ?>
            <div class="login-err"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        <form method="POST" action="/<?= ADMIN_PATH ?>?action=login">
            <label>用户名</label>
            <input type="text" name="username" required autofocus>
            <label>密码</label>
            <input type="password" name="password" required>
            <button type="submit">登录</button>
        </form>
    </div>
    <?php else: ?>
    <!-- 管理面板 -->
    <div class="admin-wrap">
        <div class="admin-header">
            <h1>📊 管理面板</h1>
            <div style="display:flex;gap:16px;align-items:center">
                <a href="/">← 首页</a>
                <a href="/<?= ADMIN_PATH ?>?action=logout">退出登录</a>
            </div>
        </div>

        <?php
        $db = getDB();
        $stats = $db->query('SELECT COUNT(*) as total, SUM(clicks) as clicks FROM links')->fetch();
        $today = $db->query("SELECT COUNT(*) as c FROM links WHERE DATE(created_at)=CURDATE()")->fetch();
        ?>
        <div class="admin-stats">
            <div class="a-stat"><div class="v"><?= (int)($stats['total']??0) ?></div><div class="l">总链接数</div></div>
            <div class="a-stat"><div class="v"><?= (int)($stats['clicks']??0) ?></div><div class="l">总点击量</div></div>
            <div class="a-stat"><div class="v"><?= (int)($today['c']??0) ?></div><div class="l">今日新增</div></div>
        </div>

        <div class="admin-actions">
            <a href="/">🏠 返回首页</a>
            <a href="/<?= ADMIN_PATH ?>?action=clear" class="danger" onclick="return confirm('确定清空所有链接？此操作不可撤销！')">🗑 清空全部</a>
        </div>

        <?php
        $page = max(1, (int)($_GET['p'] ?? 1));
        $perPage = 30;
        $offset = ($page - 1) * $perPage;
        $total = (int)($stats['total']??0);
        $pages = max(1, ceil($total / $perPage));
        $rows = $db->query("SELECT * FROM links ORDER BY created_at DESC LIMIT $perPage OFFSET $offset")->fetchAll();
        ?>

        <?php if (empty($rows)): ?>
            <div class="empty">暂无链接记录</div>
        <?php else: ?>
            <table class="link-table">
                <thead>
                    <tr><th>短码</th><th>目标链接</th><th>点击</th><th>创建时间</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="code" data-label="短码"><?= htmlspecialchars($r['code']) ?></td>
                        <td class="url" data-label="链接" title="<?= htmlspecialchars($r['url']) ?>"><?= htmlspecialchars($r['url']) ?></td>
                        <td class="clicks" data-label="点击"><?= (int)$r['clicks'] ?></td>
                        <td class="time" data-label="时间"><?= htmlspecialchars($r['created_at']) ?></td>
                        <td>
                            <form method="POST" action="/<?= ADMIN_PATH ?>?action=delete" style="display:inline" onsubmit="return confirm('删除此链接？')">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="del-btn">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($pages > 1): ?>
            <div style="display:flex;justify-content:center;gap:6px;margin-top:20px">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <a href="/<?= ADMIN_PATH ?>?p=<?= $i ?>"
                       style="padding:8px 14px;border-radius:8px;font-size:13px;text-decoration:none;
                       <?= $i===$page ? 'background:rgba(99,102,241,.15);color:#a5b4fc;font-weight:600' : 'color:#52525b;background:rgba(255,255,255,.03)' ?>">
                       <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </body></html>
    <?php exit;
}

// ============================================
// 短链接跳转
// ============================================
if ($uri !== '/' && $uri !== '/index.php' && $uri !== '' && strlen(trim($uri, '/')) > 0) {
    $code = trim($uri, '/');
    if (!preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?)$/i', $code)) {
        $s = getDB()->prepare('SELECT url FROM links WHERE code=? LIMIT 1');
        $s->execute([$code]);
        $row = $s->fetch();
        if ($row) {
            getDB()->prepare('UPDATE links SET clicks=clicks+1 WHERE code=?')->execute([$code]);
            header('Location: ' . $row['url'], true, 301);
            exit;
        }
    }
}

// ============================================
// 首页
// ============================================
$bingUrl = 'https://bing.biturl.top/?resolution=1920&format=json&index=0&mkt=zh-CN';
$bingApi = @json_decode(@file_get_contents($bingUrl), true);
$bgImage = ($bingApi && !empty($bingApi['url'])) ? $bingApi['url'] : 'https://images.unsplash.com/photo-1506744038136-46273834b3fb?w=1920';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>短链接</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{font-size:16px;-webkit-font-smoothing:antialiased}
body{
  font-family:'Inter',system-ui,-apple-system,sans-serif;
  color:#e4e4e7;
  min-height:100vh;
  overflow-x:hidden;
  position:relative;
}

/* ============ Bing 壁纸背景 ============ */
.bg-wallpaper{
  position:fixed;inset:0;z-index:0;
  background:url('<?= htmlspecialchars($bgImage) ?>') center/cover no-repeat fixed;
}
.bg-wallpaper::after{
  content:'';position:absolute;inset:0;
  background:linear-gradient(
    180deg,
    rgba(9,9,11,.7) 0%,
    rgba(9,9,11,.5) 40%,
    rgba(9,9,11,.65) 70%,
    rgba(9,9,11,.85) 100%
  );
}

/* ============ Layout ============ */
.container{
  position:relative;z-index:1;
  width:100%;max-width:560px;
  margin:0 auto;
  padding:100px 20px 60px;
}

/* ============ Header ============ */
.brand{text-align:center;margin-bottom:52px}
.brand-icon{
  display:inline-flex;align-items:center;justify-content:center;
  width:60px;height:60px;
  border-radius:18px;
  background:rgba(255,255,255,.1);
  backdrop-filter:blur(20px);
  -webkit-backdrop-filter:blur(20px);
  border:1px solid rgba(255,255,255,.12);
  margin-bottom:22px;
  box-shadow:0 8px 32px rgba(0,0,0,.3);
  transition:all .3s;
}
.brand-icon:hover{transform:scale(1.05);box-shadow:0 12px 40px rgba(0,0,0,.4)}
.brand-icon svg{width:28px;height:28px;color:rgba(255,255,255,.9)}
.brand h1{
  font-size:36px;font-weight:800;
  color:#fff;
  text-shadow:0 2px 20px rgba(0,0,0,.3);
  letter-spacing:-.5px;
}
.brand p{
  margin-top:8px;font-size:15px;
  color:rgba(255,255,255,.45);font-weight:400;
}

/* ============ Glass Input Card ============ */
.input-card{
  background:rgba(255,255,255,.08);
  backdrop-filter:blur(24px);
  -webkit-backdrop-filter:blur(24px);
  border:1px solid rgba(255,255,255,.1);
  border-radius:20px;
  padding:6px;
  display:flex;align-items:center;gap:0;
  transition:all .3s cubic-bezier(.4,0,.2,1);
  box-shadow:0 8px 32px rgba(0,0,0,.2);
}
.input-card:focus-within{
  border-color:rgba(255,255,255,.2);
  box-shadow:0 8px 40px rgba(0,0,0,.3),0 0 0 4px rgba(255,255,255,.03);
  background:rgba(255,255,255,.1);
}
#urlInput{
  flex:1;background:transparent;border:none;outline:none;
  color:#fff;font-size:15px;font-family:inherit;
  padding:16px 20px;font-weight:400;
}
#urlInput::placeholder{color:rgba(255,255,255,.3);transition:color .2s}
#urlInput:focus::placeholder{color:rgba(255,255,255,.15)}
#shortenBtn{
  background:rgba(255,255,255,.12);
  backdrop-filter:blur(10px);
  border:1px solid rgba(255,255,255,.12);
  color:#fff;
  padding:14px 32px;border-radius:16px;
  font-size:14px;font-weight:600;font-family:inherit;
  cursor:pointer;
  transition:all .3s cubic-bezier(.4,0,.2,1);
  white-space:nowrap;letter-spacing:.3px;
}
#shortenBtn:hover{
  background:rgba(255,255,255,.18);
  border-color:rgba(255,255,255,.2);
  transform:translateY(-1px);
  box-shadow:0 8px 24px rgba(0,0,0,.3);
}
#shortenBtn:active{transform:translateY(0) scale(.98)}
#shortenBtn:disabled{
  background:rgba(255,255,255,.04);color:rgba(255,255,255,.2);
  cursor:not-allowed;transform:none;box-shadow:none;
}

/* ============ Error ============ */
.error-msg{
  display:none;margin-top:14px;
  padding:12px 18px;
  background:rgba(239,68,68,.1);
  border:1px solid rgba(239,68,68,.15);
  border-radius:14px;
  color:#fca5a5;font-size:14px;
  backdrop-filter:blur(10px);
  animation:shake .4s;
}
.error-msg.show{display:block}
@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-4px)}40%{transform:translateX(4px)}60%{transform:translateX(-3px)}80%{transform:translateX(3px)}}

/* ============ Result Card ============ */
.result{
  display:none;margin-top:20px;
  background:rgba(255,255,255,.08);
  backdrop-filter:blur(24px);
  -webkit-backdrop-filter:blur(24px);
  border:1px solid rgba(255,255,255,.1);
  border-radius:20px;
  padding:24px;
  box-shadow:0 8px 32px rgba(0,0,0,.2);
  position:relative;overflow:hidden;
}
.result::before{
  content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);
}
.result.show{display:block;animation:slideUp .4s cubic-bezier(.4,0,.2,1)}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.result-label{
  font-size:11px;font-weight:600;
  color:rgba(255,255,255,.4);text-transform:uppercase;
  letter-spacing:1.5px;margin-bottom:10px;
}
.result-url{display:flex;align-items:center;gap:10px}
.result-url a{
  flex:1;color:#fff;font-size:17px;font-weight:600;
  text-decoration:none;word-break:break-all;
  text-shadow:0 1px 8px rgba(0,0,0,.2);
}
.result-url a:hover{opacity:.8}
.copy-btn{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(255,255,255,.1);
  color:#fff;border:1px solid rgba(255,255,255,.12);
  padding:10px 18px;border-radius:12px;
  font-size:13px;font-weight:500;font-family:inherit;
  cursor:pointer;transition:all .25s;white-space:nowrap;
}
.copy-btn svg{width:14px;height:14px}
.copy-btn:hover{background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.2)}
.copy-btn.copied{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.2);color:#4ade80}
.result-original{
  margin-top:16px;padding-top:14px;
  border-top:1px solid rgba(255,255,255,.06);
  font-size:13px;color:rgba(255,255,255,.35);
  overflow:hidden;text-overflow:ellipsis;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.6;
}

/* ============ Toast ============ */
.toast{
  position:fixed;bottom:32px;left:50%;transform:translateX(-50%) translateY(80px);
  background:rgba(30,30,35,.9);backdrop-filter:blur(20px);
  border:1px solid rgba(255,255,255,.08);
  color:#e4e4e7;padding:12px 24px;border-radius:14px;
  font-size:14px;font-weight:500;
  display:flex;align-items:center;gap:8px;
  opacity:0;transition:all .4s cubic-bezier(.4,0,.2,1);
  z-index:100;pointer-events:none;
  box-shadow:0 16px 48px rgba(0,0,0,.4);
}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast svg{color:#4ade80;width:18px;height:18px}

/* ============ Spinner ============ */
.spinner{
  display:inline-block;width:16px;height:16px;
  border:2px solid rgba(255,255,255,.2);
  border-top-color:#fff;border-radius:50%;
  animation:spin .6s linear infinite;vertical-align:middle;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ============ Footer ============ */
.footer{
  text-align:center;margin-top:60px;
  font-size:12px;color:rgba(255,255,255,.2);
}
.footer a{color:rgba(255,255,255,.25);text-decoration:none}
.footer a:hover{color:rgba(255,255,255,.4)}

/* ============ Responsive: 首页 ============ */
@media(max-width:480px){
  /* 安全区域适配 (刘海屏/底部横条) */
  @supports(padding: env(safe-area-inset-bottom)){
    .container{padding-left:calc(16px + env(safe-area-inset-left));padding-right:calc(16px + env(safe-area-inset-right));padding-bottom:calc(40px + env(safe-area-inset-bottom))}
    .toast{bottom:calc(32px + env(safe-area-inset-bottom))}
  }
  .container{padding:60px 16px 40px}
  .brand{margin-bottom:36px}
  .brand h1{font-size:28px}
  .brand p{font-size:13px}
  .brand-icon{width:50px;height:50px;border-radius:14px}
  .brand-icon svg{width:24px;height:24px}
  /* 输入卡片纵向排列 */
  .input-card{flex-direction:column;padding:0;border-radius:16px}
  #urlInput{padding:16px;border-bottom:1px solid rgba(255,255,255,.06);width:100%;font-size:16px;/* 防止iOS缩放 */}
  #shortenBtn{border-radius:0 0 16px 16px;width:100%;padding:16px;font-size:15px;min-height:48px;/* 触摸友好 */}
  /* 结果卡片 */
  .result{padding:20px 16px}
  .result-url{flex-direction:column;align-items:stretch;gap:10px}
  .result-url a{font-size:15px}
  .copy-btn{width:100%;justify-content:center;padding:14px;min-height:48px;font-size:14px}
  .result-original{-webkit-line-clamp:3;font-size:12px}
  /* 错误提示 */
  .error-msg{font-size:13px;padding:10px 14px;border-radius:12px}
  /* Toast */
  .toast{padding:10px 20px;font-size:13px;border-radius:12px;bottom:24px}
  .footer{margin-top:40px;font-size:11px}
}

/* 极小屏适配 (SE等) */
@media(max-width:360px){
  .container{padding:48px 12px 32px}
  .brand h1{font-size:24px}
  .brand-icon{width:44px;height:44px;border-radius:12px;margin-bottom:16px}
  .brand-icon svg{width:20px;height:20px}
  #urlInput{padding:14px 12px;font-size:14px}
  .result-url a{font-size:14px}
}

/* 横屏小高度 */
@media(max-height:500px) and (orientation:landscape){
  .container{padding:24px 20px 20px}
  .brand{margin-bottom:20px}
  .brand h1{font-size:22px}
  .brand p{display:none}
  .brand-icon{width:36px;height:36px;border-radius:10px;margin-bottom:10px}
  .brand-icon svg{width:18px;height:18px}
  .footer{margin-top:20px}
}

/* iPad / 平板 */
@media(min-width:481px) and (max-width:768px){
  .container{padding:80px 24px 48px}
  .brand h1{font-size:32px}
}

/* 触摸设备去掉hover效果，增大点击区域 */
@media(hover:none) and (pointer:coarse){
  .brand-icon:active{transform:scale(1.05)}
  #shortenBtn:active{transform:scale(.97);opacity:.85}
  .copy-btn:active{transform:scale(.97)}
  .copy-btn{min-height:48px}
}
</style>
</head>
<body>

<div class="bg-wallpaper"></div>

<div class="container">
  <div class="brand">
    <div class="brand-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
      </svg>
    </div>
    <h1>短链接</h1>
    <p>粘贴链接，即刻缩短</p>
  </div>

  <div class="input-card">
    <input type="text" id="urlInput" placeholder="https://example.com/very-long-url..." autofocus autocomplete="off" spellcheck="false">
    <button id="shortenBtn" onclick="shortenUrl()">缩短</button>
  </div>

  <div class="error-msg" id="errorMsg"></div>

  <div class="result" id="result">
    <div class="result-label">短链接已生成</div>
    <div class="result-url">
      <a id="shortUrl" href="#" target="_blank"></a>
      <button class="copy-btn" id="copyBtn" onclick="copyUrl()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        复制
      </button>
    </div>
    <div class="result-original" id="originalUrl"></div>
  </div>

  <div class="footer">
    Powered by ShortURL · 每日壁纸来自 <a href="https://www.bing.com" target="_blank">Bing</a>
  </div>
</div>

<div class="toast" id="toast">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
  <span id="toastMsg">已复制到剪贴板</span>
</div>

<script>
let toastTimer;
function showToast(msg){
  const t=document.getElementById('toast');
  document.getElementById('toastMsg').textContent=msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer=setTimeout(()=>t.classList.remove('show'),2200);
}

document.getElementById('urlInput').addEventListener('keydown',e=>{if(e.key==='Enter')shortenUrl()});

async function shortenUrl(){
  const inp=document.getElementById('urlInput'),btn=document.getElementById('shortenBtn'),err=document.getElementById('errorMsg'),res=document.getElementById('result');
  const url=inp.value.trim();
  err.classList.remove('show');res.classList.remove('show');
  if(!url){err.textContent='请输入一个链接';err.classList.add('show');inp.focus();return}
  if(!/^https?:\/\/.+/i.test(url)){err.textContent='请输入以 http:// 或 https:// 开头的完整网址';err.classList.add('show');inp.focus();return}
  const hostMatch=url.match(/^https?:\/\/([^\/]+)/i);
  if(!hostMatch||!/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/.test(hostMatch[1])){err.textContent='请输入包含有效域名的网址（如 https://example.com）';err.classList.add('show');inp.focus();return}
  let final=url;
  btn.disabled=true;btn.innerHTML='<span class="spinner"></span>';
  try{
    const r=await fetch('?action=shorten',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url:final})});
    const txt=await r.text();let data;
    try{data=JSON.parse(txt)}catch{throw new Error('服务器返回: '+txt.substring(0,200))}
    if(!r.ok){err.textContent=data.error||'请求失败';err.classList.add('show');return}
    document.getElementById('shortUrl').textContent=data.short_url;
    document.getElementById('shortUrl').href=data.short_url;
    document.getElementById('originalUrl').textContent=data.url||final;
    res.classList.add('show');
    try{await navigator.clipboard.writeText(data.short_url);showToast('已复制到剪贴板')}catch{}
  }catch(e){err.textContent='错误: '+(e.message||'请求失败');err.classList.add('show')}
  finally{btn.disabled=false;btn.textContent='缩短'}
}

async function copyUrl(){
  const url=document.getElementById('shortUrl').textContent,btn=document.getElementById('copyBtn');
  try{await navigator.clipboard.writeText(url)}catch{
    const ta=document.createElement('textarea');ta.value=url;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);
  }
  btn.classList.add('copied');
  btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> 已复制';
  showToast('已复制到剪贴板');
  setTimeout(()=>{
    btn.classList.remove('copied');
    btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> 复制';
  },2000);
}
</script>
</body></html>
