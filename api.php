<?php
// 开启错误显示以便调试
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 配置部分
$db_host = 'sql207.infinityfree.com';
$db_name = 'if0_41055112_02';
$db_user = 'if0_41055112';
$db_pass = 'Z6jSmDMGxwysZIY'; // 请修改为您的数据库密码

// === 模型服务器配置 ===
// 如果模型运行在另一台服务器上，请将 localhost 替换为该服务器的公网IP或域名
// 例如: 'http://192.168.1.100:8080/v1/chat/completions'
$python_api_url = 'http://direct.virtaicloud.com:42885/v1/chat/completions';

// 允许跨域 (如果前端和后端不在同源)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// 处理预检请求 (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 处理数据库连接
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['detail' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['act'] ?? '';

// 获取POST数据
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

if (json_last_error() !== JSON_ERROR_NONE && !empty($raw_input)) {
    http_response_code(400);
    echo json_encode(['detail' => 'Invalid JSON input']);
    exit;
}

switch ($action) {
    case 'login':
        handleLogin($pdo, $input);
        break;
    case 'register':
        handleRegister($pdo, $input);
        break;
    case 'history':
        handleGetHistory($pdo);
        break;
    case 'session':
        handleGetSession($pdo);
        break;
    case 'chat':
        handleChat($pdo, $input, $python_api_url);
        break;
    default:
        http_response_code(404);
        echo json_encode(['detail' => 'Endpoint not found']);
}

function handleLogin($pdo, $data) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$data['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($data['password'], $user['password_hash'])) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(401);
        echo json_encode(['detail' => 'Invalid credentials']);
    }
}

function handleRegister($pdo, $data) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$data['username']]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['detail' => 'User already exists']);
        return;
    }

    $hash = password_hash($data['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    if ($stmt->execute([$data['username'], $hash])) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(500);
        echo json_encode(['detail' => 'Registration failed']);
    }
}

function handleGetHistory($pdo) {
    $user = $_GET['user'] ?? '';
    if (!$user) return;

    // 修复：使用 created_at 排序，并只返回 session_id (用户前端逻辑是将 sid 视为时间戳，但我们的表结构 sid 是字符串)
    // 用户的 JS 代码: new Date(parseInt(sid))，说明 sid 必须是时间戳数字字符串。
    // 在 startNewChat 中，currentSessionId = Date.now().toString()。
    // 所以我们的 session_id 应该存储这个时间戳。
    
    $stmt = $pdo->prepare("SELECT session_id FROM chat_sessions WHERE username = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user]);
    $sessions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($sessions);
}

function handleGetSession($pdo) {
    $sid = $_GET['sid'] ?? '';
    if (!$sid) return;

    $stmt = $pdo->prepare("SELECT role, content, thought, search_info FROM messages WHERE session_id = ? ORDER BY id ASC");
    $stmt->execute([$sid]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($msgs);
}

function handleChat($pdo, $data, $api_url) {
    if (!$data) {
        http_response_code(400);
        echo json_encode([
            'detail' => 'Missing input data. Request Method: ' . $_SERVER['REQUEST_METHOD'] . 
            '. Hint: If Method is GET, you might be losing POST data due to HTTP->HTTPS redirection.'
        ]);
        return;
    }

    $user = $data['username'] ?? 'guest';
    $sid = $data['session_id'] ?? null;
    $msg = $data['message'] ?? '';
    
    if (!$sid || !$msg) {
        http_response_code(400);
        echo json_encode(['detail' => 'Missing required fields (session_id or message)']);
        return;
    }

    $use_search = $data['use_search'] ?? false;
    // $use_think = $data['use_deep_think'] ?? false; // 暂时不用

    // 1. 确保 session 存在
    $stmt = $pdo->prepare("INSERT IGNORE INTO chat_sessions (session_id, username) VALUES (?, ?)");
    $stmt->execute([$sid, $user]);

    // 2. 保存用户消息
    $stmt = $pdo->prepare("INSERT INTO messages (session_id, role, content) VALUES (?, 'user', ?)");
    $stmt->execute([$sid, $msg]);

    // 3. 构建 Python API 请求
    // 获取上下文历史 (可选，这里简化为只发送当前消息，或者最近几条)
    $stmt = $pdo->prepare("SELECT role, content FROM messages WHERE session_id = ? ORDER BY id ASC");
    $stmt->execute([$sid]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 转换为 OpenAI 格式
    $messages = [];
    // 添加系统提示
    $messages[] = ['role' => 'system', 'content' => 'You are WREP AI, a helpful assistant.'];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }

    $payload = [
        'model' => 'local-qwen3',
        'messages' => $messages,
        'temperature' => 0.7,
        'stream' => false // PHP 后端暂不支持流式转发
    ];

    // 4. 调用 Python API
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        echo json_encode(['detail' => 'Curl error: ' . $error]);
        return;
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        http_response_code(500);
        echo json_encode(['detail' => 'AI Service Unavailable (HTTP ' . $http_code . '): ' . $response]);
        return;
    }

    $ai_data = json_decode($response, true);
    $answer = $ai_data['choices'][0]['message']['content'] ?? 'Error processing response';
    
    // 模拟 thought 和 search_info (因为 Python API 目前只返回 content)
    // 如果需要真实数据，需要在 Python API 中扩展返回字段
    $thought = null;
    $search_info = null;

    // 5. 保存 AI 响应
    $stmt = $pdo->prepare("INSERT INTO messages (session_id, role, content, thought, search_info) VALUES (?, 'assistant', ?, ?, ?)");
    $stmt->execute([$sid, $answer, $thought, $search_info]);

    // 6. 返回给前端
    echo json_encode([
        'answer' => $answer,
        'thought' => $thought,
        'search_info' => $search_info,
        'debug_input' => $data // 调试回显，确认后端收到了什么
    ]);
}
?>
