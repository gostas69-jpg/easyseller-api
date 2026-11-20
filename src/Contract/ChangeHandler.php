<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Contract;

use EasySeller\SiteSdk\Change;
use EasySeller\SiteSdk\Result;

/**
 * Что сайт делает с изменением из EasySeller. Удобнее наследовать AbstractChangeHandler и переопределить только
 * нужные виды. Просьбу `resync` обрабатывает сама библиотека. Режим записи сайта («выключено», «проверка») решает
 * обработчик: выключено → `Result::rejected(…)`, проверка → проверить и вернуть `Result::checked()`.
 * Бросать можно: исключение превратится в отказ «Сайт не смог применить изменение».
 */
interface ChangeHandler
{
    public function handle(Change $change): Result;
}
