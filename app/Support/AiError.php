<?php

namespace App\Support;

/** Ошибка распознавания: код уходит в ответ API. */
class AiError extends \RuntimeException
{
    public function __construct(public readonly string $codeName)
    {
        parent::__construct($codeName);
    }
}
