<?php
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/vendor/autoload.php';

header('Content-Type: application/json');

function supabaseRequest($method, $endpoint, $body = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

$action = $_GET['action'] ?? null;
$requestMethod = $_SERVER['REQUEST_METHOD'];

if ($action === 'rewrite-article') {
    require_once __DIR__ . '/../../backend/scrapersArticles/scrapeArticlesWrapper.php';
    exit;
}

if ($requestMethod === 'GET' && $action === 'posts') {
    echo supabaseRequest('GET', 'posts?select=*,source:sources(*)&deleted=eq.false&limit=2000');
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
    $mail->SMTPDebug = 2; // Debug aktivieren
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
        echo json_encode(['error' => $e->getMessage(), 'info' => $mail->ErrorInfo]);
    }
    exit;
}

if ($requestMethod === 'POST' && $action === 'mark-deleted' && isset($_GET['id'])) {
    $id = $_GET['id'];
    echo supabaseRequest('PATCH', 'posts?id=eq.' . $id, ['deleted' => true]);
    exit;
}

if ($requestMethod === 'POST' && $action === 'restore' && isset($_GET['id'])) {
    $id = $_GET['id'];
    echo supabaseRequest('PATCH', 'posts?id=eq.' . $id, ['deleted' => false]);
    exit;
}

if ($requestMethod === 'GET' && $action === 'posts-trash') {
    echo supabaseRequest('GET', 'posts?select=*,source:sources(*)&deleted=eq.true&limit=2000');
    exit;
}

if ($requestMethod === 'GET' && $action === 'sources') {
    echo supabaseRequest('GET', 'sources?select=*&order=created_at.desc');
    exit;
}

if ($requestMethod === 'POST' && $action === 'toggle-source' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $active = $_GET['active'] === 'true' ? 'true' : 'false';
    echo supabaseRequest('PATCH', 'sources?id=eq.' . $id, ['active' => $active]);
    exit;
}

if ($requestMethod === 'POST' && $action === 'update-comment' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $data = json_decode(file_get_contents('php://input'), true);
    $comment = $data['comment'] ?? '';
    echo supabaseRequest('PATCH', 'posts?id=eq.' . $id, ['comment' => $comment]);
    exit;
}

if ($requestMethod === 'POST' && $action === 'update-rewritten' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $data = json_decode(file_get_contents('php://input'), true);
    $rewritten = $data['rewrittentext'] ?? null; // null erlaubt Löschen
    echo supabaseRequest('PATCH', 'posts?id=eq.' . $id, ['rewrittentext' => $rewritten]);
    exit;
}

if ($action === 'article-sources') {
    $files = glob(__DIR__ . '/../../backend/scrapersArticles/*.php');
    $sources = array_map(function($file) {
        return basename($file, '.php');
    }, $files);
    echo json_encode($sources);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);