<?php
/**
 * Пример: запуск раз в минуту из cron.
 *   * * * * * php /путь/к/сайту/easyseller-cron.php
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use EasySeller\SiteSdk\{Client, Connector};
use EasySeller\SiteSdk\Store\PdoStorage;

$pdo = new PDO('mysql:host=localhost;dbname=shop;charset=utf8mb4', 'shop', getenv('DB_PASSWORD') ?: '');
$storage = new PdoStorage($pdo);
$storage->install();

$connector = new Connector(
    new Client(getenv('EASYSELLER_API_KEY') ?: ''),
    $storage,
    new MyShopData(),      // ваш класс, см. README, шаг 2
    new MyShopChanges(),   // ваш класс, см. README, шаг 3
    fn(string $text) => error_log('EasySeller: ' . $text),
);
$connector->run();
