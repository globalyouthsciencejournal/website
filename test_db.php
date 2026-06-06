<?php
require_once __DIR__ . '/includes/bootstrap.php';
$_POST['action'] = 'get_chat';
$_POST['submission_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'POST';

// bypass csrf
function csrf_validate_bypass() {}
// We need to inject our bypass or just run the code
$pdo->exec("ALTER TABLE submission_messages ADD COLUMN is_read TINYINT(1) DEFAULT 0");
$pdo->prepare("UPDATE submission_messages SET is_read = 1 WHERE submission_id = ? AND sender_type = 'user'")->execute([1]);
$stmt = $pdo->prepare("SELECT sender_name, sender_type, message, created_at FROM submission_messages WHERE submission_id = ? ORDER BY created_at ASC, id ASC");
$stmt->execute([1]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isTyping = false;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing (submission_id INT, sender_type VARCHAR(50), last_typed DATETIME, PRIMARY KEY(submission_id, sender_type))");
    $stmtTyping = $pdo->prepare("SELECT last_typed, CURRENT_TIMESTAMP as db_now FROM chat_typing WHERE submission_id = ? AND sender_type = 'user'");
    $stmtTyping->execute([1]);
    $typingRow = $stmtTyping->fetch(PDO::FETCH_ASSOC);
    var_dump($typingRow);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
echo json_encode(['success' => true, 'messages' => $messages, 'is_typing' => $isTyping]);
