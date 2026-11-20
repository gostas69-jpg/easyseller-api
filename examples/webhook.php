<?php
/**
 * Пример: адрес для уведомлений EasySeller (его вписывают в окне магазина в EasySeller).
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use EasySeller\SiteSdk\{Client, Connector};
use EasySeller\SiteSdk\Store\PdoStorage;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$pdo = new PDO('mysql:host=localhost;dbname=shop;charset=utf8mb4', 'shop', getenv('DB_PASSWORD') ?: '');
$connector = new Connector(new Client(getenv('EASYSELLER_API_KEY') ?: ''), new PdoStorage($pdo), new MyShopData(), new MyShopChanges());

$r = $connector->webhook((string)file_get_contents('php://input'), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
http_response_code($r['status']);
header('Content-Type: application/json; charset=utf-8');
echo $r['body'];

if ($r['pull']) {
    // ответ уже отдан: забрать изменения самим
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $connector->pull();
}
