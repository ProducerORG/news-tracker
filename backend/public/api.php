<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

$pdo = new PDO('pgsql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);

$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = explode('/', trim($_SERVER['REQUEST_URI'], '/'));

if ($requestMethod === 'GET' && $requestUri[0] === 'posts') {
    $stmt = $pdo->query("SELECT * FROM posts WHERE deleted = FALSE ORDER BY date DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($requestMethod === 'DELETE' && $requestUri[0] === 'posts' && isset($requestUri[1])) {
    $id = $requestUri[1];
    $stmt = $pdo->prepare("UPDATE posts SET deleted = TRUE WHERE id = :id");
    $stmt->execute(['id' => $id]);
    echo json_encode(['status' => 'deleted']);
    exit;
}

if ($requestMethod === 'POST' && $requestUri[0] === 'source-request') {
    $data = json_decode(file_get_contents('php://input'), true);
    $url = $data['url'] ?? '';
    $comment = $data['comment'] ?? '';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = SMTP_PORT;
        $mail->setFrom(SMTP_FROM);
        $mail->addAddress(SMTP_TO);
        $mail->Subject = 'Neue Quellen-Anfrage';
        $mail->Body = "Quelle: $url\nKommentar: $comment";
        $mail->send();
        echo json_encode(['status' => 'sent']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $mail->ErrorInfo]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);