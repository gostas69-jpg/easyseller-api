<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Contract;

/**
 * Где библиотека держит своё: очередь отправок, итоги применённых изменений, документы к отправке, несколько значений
 * (номер последнего изменения, когда была сверка). Готовая реализация на PDO: Store\PdoStorage.
 */
interface Storage
{
    public function getState(string $key): ?string;

    public function setState(string $key, string $value): void;

    /** @return array<string, mixed>|null итог изменения или null */
    public function applied(int $changeId): ?array;

    /** @param array<string, mixed> $result */
    public function saveApplied(int $changeId, array $result): void;

    /** Поставить отправку. Неотправленная строка того же вида и номера одна: повтор поднимает её счётчик. */
    public function push(string $kind, string $entity): void;

    /**
     * Строки, которым пора: `id`, `kind`, `entity`, `version`, `attempts`, `created_at` (unix).
     *
     * @return list<array{id: int, kind: string, entity: string, version: int, attempts: int, created_at: int}>
     */
    public function due(int $limit): array;

    /** Отправлено. Счётчик вырос во время отправки: строка остаётся в очереди. */
    public function sent(int $rowId, int $version): void;

    /** Повторить позже. */
    public function retry(int $rowId, int $attempts, int $nextAt, string $error): void;

    /** Закрыть с ошибкой, больше не пробовать. */
    public function close(int $rowId, string $error): void;

    /**
     * Наименьший номер заказа среди неотправленных строк `order`, кроме `$except` (заказы, которые уходят в этом же
     * запросе), или null.
     *
     * @param list<string> $except
     */
    public function minPendingOrder(array $except = []): ?string;

    /** Есть ли неотправленные строки вида. */
    public function pending(string $kind): bool;

    public function putDocument(string $orderId, string $kind, string $bytes, string $contentType): void;

    /** @return list<array{kind: string, bytes: string, content_type: string}> */
    public function documents(string $orderId): array;

    /** Уборка: отправленное и итоги старше `days` дней. */
    public function cleanup(int $days): void;

    /** Замок на имя, ждать до `wait` секунд. Не взят: false. */
    public function lock(string $name, int $wait): bool;

    public function unlock(string $name): void;
}
