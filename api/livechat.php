<?php
// ============================================================
// BraidedbyAGB — Live Chat API
// FILE: /api/livechat.php
//
// Public endpoints  (no auth):  start · send · poll
// Admin endpoints (Bearer JWT): register_token · sessions
//                                messages · reply · close
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/fcm.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$db     = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = ($method === 'POST')
        ? (json_decode(file_get_contents('php://input'), true) ?? [])
        : [];

// ── Ensure tables exist (fast no-ops after first run) ─────
$db->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    uuid           VARCHAR(36) NOT NULL UNIQUE,
    customer_name  VARCHAR(100)  DEFAULT NULL,
    customer_email VARCHAR(150)  DEFAULT NULL,
    status         ENUM('active','closed') DEFAULT 'active',
    unread_admin   INT DEFAULT 0,
    last_msg       TEXT DEFAULT NULL,
    last_msg_at    DATETIME DEFAULT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (status),
    INDEX (last_msg_at)
)");
$db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    sender     ENUM('customer','admin') NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (session_id),
    INDEX (created_at)
)");
$db->exec("CREATE TABLE IF NOT EXISTS fcm_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    token      VARCHAR(512) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ── Admin auth (Bearer token, same logic as api/admin.php) ─
function chatRequireAuth(): void {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorised']);
        exit;
    }
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT admin_id FROM admin_tokens WHERE token=? AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([trim($m[1])]);
    if (!$stmt->fetch()) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }
}

// ── Routing ───────────────────────────────────────────────
switch ($action) {

    // ════════════════════════════════════════════════════════
    // PUBLIC — Start a new chat session
    // POST /api/livechat.php?action=start
    // Body: { name: "Alice", email: "a@b.com" (optional) }
    // ════════════════════════════════════════════════════════
    case 'start':
        if ($method !== 'POST') { http_response_code(405); exit; }

        $name  = mb_substr(trim($body['name'] ?? ''), 0, 100);
        $email = filter_var(trim($body['email'] ?? ''), FILTER_VALIDATE_EMAIL)
               ? trim($body['email']) : null;

        if (!$name) {
            echo json_encode(['error' => 'Name is required']); exit;
        }

        // Generate UUID v4
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $stmt = $db->prepare(
            "INSERT INTO chat_sessions (uuid, customer_name, customer_email) VALUES (?,?,?)"
        );
        $stmt->execute([$uuid, $name, $email]);
        $sessionId = (int)$db->lastInsertId();

        // Notify admin of new chat session
        fcm_send(
            '💬 New Chat — ' . $name,
            'A new customer wants to chat',
            ['type' => 'chat_new', 'session_id' => (string)$sessionId]
        );

        echo json_encode(['success' => true, 'uuid' => $uuid, 'session_id' => $sessionId]);
        break;

    // ════════════════════════════════════════════════════════
    // PUBLIC — Customer sends a message
    // POST /api/livechat.php?action=send&uuid=XXX
    // Body: { message: "Hello" }
    // ════════════════════════════════════════════════════════
    case 'send':
        if ($method !== 'POST') { http_response_code(405); exit; }

        $uuid    = trim($_GET['uuid'] ?? '');
        $message = mb_substr(trim($body['message'] ?? ''), 0, 2000);

        if (!$uuid || !$message) {
            echo json_encode(['error' => 'Missing uuid or message']); exit;
        }

        $stmt = $db->prepare(
            "SELECT id, customer_name FROM chat_sessions WHERE uuid=? AND status='active' LIMIT 1"
        );
        $stmt->execute([$uuid]);
        $session = $stmt->fetch();

        if (!$session) {
            echo json_encode(['error' => 'Session not found or closed']); exit;
        }

        $sessionId = (int)$session['id'];
        $db->prepare("INSERT INTO chat_messages (session_id, sender, body) VALUES (?,?,?)")
           ->execute([$sessionId, 'customer', $message]);
        $msgId = (int)$db->lastInsertId();

        $db->prepare(
            "UPDATE chat_sessions
             SET last_msg=?, last_msg_at=NOW(), unread_admin=unread_admin+1
             WHERE id=?"
        )->execute([mb_substr($message, 0, 200), $sessionId]);

        $name = $session['customer_name'] ?: 'Customer';
        fcm_send(
            '💬 ' . $name,
            $message,
            ['type' => 'chat_message', 'session_id' => (string)$sessionId]
        );

        echo json_encode(['success' => true, 'id' => $msgId]);
        break;

    // ════════════════════════════════════════════════════════
    // PUBLIC — Poll for new messages (customer polling)
    // GET /api/livechat.php?action=poll&uuid=XXX&after=N
    // Returns messages with id > N
    // ════════════════════════════════════════════════════════
    case 'poll':
        $uuid    = trim($_GET['uuid'] ?? '');
        $afterId = max(0, (int)($_GET['after'] ?? 0));

        if (!$uuid) {
            echo json_encode(['messages' => [], 'closed' => false]); exit;
        }

        $stmt = $db->prepare(
            "SELECT id, status FROM chat_sessions WHERE uuid=? LIMIT 1"
        );
        $stmt->execute([$uuid]);
        $session = $stmt->fetch();

        if (!$session) {
            echo json_encode(['messages' => [], 'closed' => true]); exit;
        }

        $msgs = $db->prepare(
            "SELECT id, sender, body, created_at
             FROM chat_messages
             WHERE session_id=? AND id>?
             ORDER BY id ASC LIMIT 50"
        );
        $msgs->execute([(int)$session['id'], $afterId]);

        echo json_encode([
            'messages' => $msgs->fetchAll(),
            'closed'   => $session['status'] === 'closed',
        ]);
        break;

    // ════════════════════════════════════════════════════════
    // ADMIN — Register FCM device token
    // POST /api/livechat.php?action=register_token
    // Body: { token: "FCM_TOKEN_STRING" }
    // ════════════════════════════════════════════════════════
    case 'register_token':
        chatRequireAuth();
        if ($method !== 'POST') { http_response_code(405); exit; }

        $token = trim($body['token'] ?? '');
        if (!$token) { echo json_encode(['error' => 'Token required']); exit; }

        $db->prepare(
            "INSERT INTO fcm_tokens (token) VALUES (?)
             ON DUPLICATE KEY UPDATE updated_at=NOW()"
        )->execute([$token]);

        echo json_encode(['success' => true]);
        break;

    // ════════════════════════════════════════════════════════
    // ADMIN — List chat sessions
    // GET /api/livechat.php?action=sessions&status=active
    // ════════════════════════════════════════════════════════
    case 'sessions':
        chatRequireAuth();

        $status = in_array($_GET['status'] ?? '', ['active', 'closed'], true)
                ? $_GET['status'] : 'active';

        $stmt = $db->prepare(
            "SELECT id, uuid, customer_name, customer_email, status,
                    unread_admin, last_msg, last_msg_at, created_at
             FROM chat_sessions
             WHERE status=?
             ORDER BY COALESCE(last_msg_at, created_at) DESC
             LIMIT 100"
        );
        $stmt->execute([$status]);
        echo json_encode($stmt->fetchAll());
        break;

    // ════════════════════════════════════════════════════════
    // ADMIN — Get all messages for a session
    // GET /api/livechat.php?action=messages&id=N
    // Also marks session as read (unread_admin=0)
    // ════════════════════════════════════════════════════════
    case 'messages':
        chatRequireAuth();

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

        $stmt = $db->prepare(
            "SELECT id, uuid, customer_name, customer_email, status, created_at
             FROM chat_sessions WHERE id=?"
        );
        $stmt->execute([$id]);
        $session = $stmt->fetch();
        if (!$session) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

        $msgs = $db->prepare(
            "SELECT id, sender, body, created_at FROM chat_messages
             WHERE session_id=? ORDER BY id ASC"
        );
        $msgs->execute([$id]);

        // Mark as read
        $db->prepare("UPDATE chat_sessions SET unread_admin=0 WHERE id=?")->execute([$id]);

        echo json_encode([
            'session'  => $session,
            'messages' => $msgs->fetchAll(),
        ]);
        break;

    // ════════════════════════════════════════════════════════
    // ADMIN — Reply to a session
    // POST /api/livechat.php?action=reply&id=N
    // Body: { message: "Hi, thanks for reaching out..." }
    // ════════════════════════════════════════════════════════
    case 'reply':
        chatRequireAuth();
        if ($method !== 'POST') { http_response_code(405); exit; }

        $id      = (int)($_GET['id'] ?? 0);
        $message = mb_substr(trim($body['message'] ?? ''), 0, 2000);

        if (!$id || !$message) {
            echo json_encode(['error' => 'Missing id or message']); exit;
        }

        $stmt = $db->prepare(
            "SELECT id FROM chat_sessions WHERE id=? AND status='active' LIMIT 1"
        );
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'Session not found or already closed']); exit;
        }

        $db->prepare("INSERT INTO chat_messages (session_id, sender, body) VALUES (?,?,?)")
           ->execute([$id, 'admin', $message]);
        $msgId = (int)$db->lastInsertId();

        $db->prepare("UPDATE chat_sessions SET last_msg=?, last_msg_at=NOW() WHERE id=?")
           ->execute([mb_substr($message, 0, 200), $id]);

        echo json_encode(['success' => true, 'id' => $msgId]);
        break;

    // ════════════════════════════════════════════════════════
    // ADMIN — Close a session
    // POST /api/livechat.php?action=close&id=N
    // ════════════════════════════════════════════════════════
    case 'close':
        chatRequireAuth();
        if ($method !== 'POST') { http_response_code(405); exit; }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

        $db->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}
