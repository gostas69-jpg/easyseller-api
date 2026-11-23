# EasySeller: библиотека для своего сайта

PHP-библиотека, которая подключает интернет-магазин на любом движке (MODX, WordPress, OpenCart, Laravel,
самописный) к [EasySeller](https://easyseller.kz). Сайт отдаёт EasySeller товары, заказы, склады и остатки, а
EasySeller присылает сайту цены, остатки, правки карточек и действия с заказами.

Всю работу с протоколом (пачки, повторы, очередь, отметки, вебхук, защиту от повторного применения) библиотека
делает сама. Разработчику сайта остаётся описать, где лежат его товары и заказы и что делать с изменением.

- [Обновления](CHANGELOG.md): все версии с датами и что изменилось.
- Описание протокола EasySeller (API, поля, примеры): выдаётся вместе с ключом подключения.

## Что нужно

- PHP 8.1 или новее.
- Ключ API магазина «Сайт»: создаётся в EasySeller, в окне магазина, кнопка «Создать ключ API».
- База для своих таблиц: MySQL, MariaDB или SQLite (через PDO), либо своё хранилище.
- Cron раз в минуту.

## Установка

```bash
composer require easyseller/site-sdk
```

## Подключение за пять шагов

### 1. Хранилище

```php
use EasySeller\SiteSdk\Store\PdoStorage;

$storage = new PdoStorage($pdo, 'easyseller_'); // приставка таблиц
$storage->install(); // один раз: создаст таблицы
```

### 2. Данные сайта

Класс, который отдаёт товары, заказы, склады и остатки в форме протокола (поля в описании протокола: `Product`,
`Order`, `Warehouse`).

```php
use EasySeller\SiteSdk\Contract\DataSource;

final class MyShopData implements DataSource
{
    public function site(): array
    {
        return [
            'module' => 'my-shop-connector 1.0',
            'capabilities' => ['products.read', 'orders.read', 'prices.write'],
            'modes' => ['prices' => 'on'],
            'currency' => 'KZT',
        ];
    }

    public function products(array $ids): array { /* модели с вариантами по номерам */ }
    public function orders(array $ids): array { /* заказы по номерам */ }
    public function warehouses(): array { return []; }
    public function stocks(): array { return []; }
    public function properties(): array { return []; }
    public function changedIds(string $kind, ?\DateTimeImmutable $since): iterable { /* номера изменённых с $since */ }
    public function lastOrderId(): string { /* наибольший номер заказа */ }
}
```

### 3. Что делать с изменениями

Наследуйте `AbstractChangeHandler` и переопределите только то, что сайт умеет. Остальное библиотека отклонит с
текстом «Сайт этого не умеет».

```php
use EasySeller\SiteSdk\Change;
use EasySeller\SiteSdk\Contract\AbstractChangeHandler;
use EasySeller\SiteSdk\Result;

final class MyShopChanges extends AbstractChangeHandler
{
    protected function priceSet(Change $change): Result
    {
        $variant = $change->data['variant_id'];
        $price = $change->data['price'];            // «44990.00»
        // запишите цену варианта
        return Result::applied();
    }
}
```

Итоги: `Result::applied()` записано, `Result::checked()` запись на сайте в режиме проверки, ничего не изменено,
`Result::rejected('текст для продавца', 'поле')` отказ, `Result::conflict('текст', ['product' => …])` версия на
сайте новее.

### 4. Cron раз в минуту

```php
use EasySeller\SiteSdk\{Client, Connector};

$connector = new Connector(new Client($apiKey), $storage, new MyShopData(), new MyShopChanges(),
    fn(string $text) => error_log('EasySeller: ' . $text));
$connector->run();
```

А там, где у сайта меняется товар или заказ:

```php
$connector->queue(Connector::PRODUCT, $productId);
$connector->queue(Connector::ORDER, $orderId);
$connector->queue(Connector::STOCKS);     // остатки целиком
```

### 5. Адрес уведомлений

Страница, адрес которой вы впишете в EasySeller в поле «Адрес для уведомлений». С ним цены и остатки доходят до
сайта за секунды, без него за минуту.

```php
$r = $connector->webhook(file_get_contents('php://input'), $_SERVER['REMOTE_ADDR']);
http_response_code($r['status']);
header('Content-Type: application/json');
echo $r['body'];
if ($r['pull']) {
    // запрос не от EasySeller или без изменений: забрать их самим, лучше после ответа
    $connector->pull();
}
```

Изменения из тела вебхука применяются, только если запрос пришёл с адреса EasySeller: список библиотека берёт у
EasySeller сама. Сайт за Cloudflare или другим прокси: передайте адрес из заголовка своего прокси, а не
`REMOTE_ADDR`.

## Деньги заказа: НДС и комиссия за оплату

У каждого расхода в `money` заказа есть `vat`, НДС внутри суммы. Сайт знает, есть ли НДС в цене перевозчика и
платёжной системы, поэтому отдавайте его числом: тогда EasySeller берёт сумму как есть, без своего переключателя
«С НДС / Без НДС». `null` оставляйте, только если не знаете.

```php
use EasySeller\SiteSdk\Money;

'money' => [
    'goods_sum'     => Money::amount(44990),
    'delivery_paid' => Money::amount(7630),
    'delivery_cost' => Money::deliveryCost(6500, false),        // перевозчик без НДС: vat "0.00"
    'insurance'     => Money::cost(450, false),                 // страхование без НДС
    'payment_fee'   => Money::paymentFee(52620, 0.95, true, false),
],
```

- Перевозчик с НДС: `Money::deliveryCost(3200, true, 16)` даст `vat` "441.38".
- Комиссию за оплату считайте по ставке той платёжной системы, через которую прошли деньги. У каждой системы своя
  ставка в настройках её модуля. Не оплачен заказ: `$paid = false`, комиссия ноль.

## Печатные формы заказа

Штрихкоды мест СДЭК (PDF), чтобы продавец печатал их в EasySeller:

```php
$connector->document($orderId, 'cdek_barcode', $pdfBytes);
```

## Свой HTTP-клиент или хранилище

- Запросы: реализуйте `EasySeller\SiteSdk\Transport` и передайте в `Client`.
- Хранилище: реализуйте `EasySeller\SiteSdk\Contract\Storage`.

## Проверка

```bash
composer install
vendor/bin/phpunit
```

Тесты идут на подменённом EasySeller и SQLite в памяти, сеть не нужна.

## Лицензия

MIT: пользуйтесь, меняйте, встраивайте в свои и коммерческие проекты, сохраните строку об авторстве.
