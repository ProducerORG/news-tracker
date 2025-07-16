<?php
require_once __DIR__ . '/../../backend/config/config.php';

header('Content-Type: application/json');

function supabaseRequest($method, $endpoint, $body = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $headers[] = 'Prefer: return=representation';
    }

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

$action = $_GET['action'] ?? null;
$requestMethod = $_SERVER['REQUEST_METHOD'];

if ($requestMethod === 'GET' && $action === 'posts') {
    echo supabaseRequest('GET', 'posts?select=*');
    exit;
}

if ($requestMethod === 'DELETE' && $action === 'posts' && isset($_GET['id'])) {
    $id = $_GET['id'];
    echo supabaseRequest('DELETE', 'posts?id=eq.' . $id);
    exit;
}

if ($requestMethod === 'POST' && $action === 'source-request') {
    $data = json_decode(file_get_contents('php://input'), true);
    $url = $data['url'] ?? '';
    $comment = $data['comment'] ?? '';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
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
        $mail->Subject = 'Neue Quellen-Anfrage ';
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