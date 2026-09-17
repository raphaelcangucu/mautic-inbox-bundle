<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * As duas unicas operacoes que o push precisa da tabela de ajustes.
 *
 * O cofre depende deste contrato, e nao do repositorio concreto nem de um EntityManager,
 * para que possa ser provado com um duplo em memoria.
 */
interface PushSettingStore
{
    public function get(string $name): ?string;

    public function set(string $name, string $value): void;
}
