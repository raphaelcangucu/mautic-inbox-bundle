<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use MauticPlugin\MauticInboxBundle\Application\Push\PushSettingStore;

/** O mesmo contrato estreito do PushSettingRepository, sem banco nenhum. */
final class InMemorySettings implements PushSettingStore
{
    /** @var array<string, string> */
    public array $rows = [];

    public function get(string $name): ?string
    {
        return $this->rows[$name] ?? null;
    }

    public function set(string $name, string $value): void
    {
        $this->rows[$name] = $value;
    }
}
