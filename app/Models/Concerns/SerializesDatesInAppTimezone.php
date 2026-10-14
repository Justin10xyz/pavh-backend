<?php

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;

// Por defecto Eloquent serializa las fechas en UTC con sufijo Z. Aquí salen en la
// zona de config/app.php con su offset (2026-10-12T14:11:14-06:00): es el mismo
// instante, pero se lee igual que en la BD y en los PDFs.
trait SerializesDatesInAppTimezone
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)
            ->setTimezone(config('app.timezone'))
            ->toIso8601String();
    }
}
