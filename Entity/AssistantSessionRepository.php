<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\UserBundle\Entity\User;

/** @extends CommonRepository<AssistantSession> */
final class AssistantSessionRepository extends CommonRepository
{
    /** Noventa dias sem toque e a sessao se apaga: o que o modelo leu de contatos nao fica para sempre. */
    public const RETENTION_DAYS = 90;

    public function getTableAlias(): string { return 'ias'; }

    /**
     * A lista nao carrega os turnos. Quem abre a aba quer titulos e datas, e o JSON de uma
     * sessao longa custa mais do que tudo o que a lista mostra junto.
     *
     * @return array<int,array{id:int,title:string,agent_key:string,context_kind:?string,context_id:?int,turns:int,created:string,updated:string}>
     */
    public function listFor(User $user, int $limit = 50): array
    {
        $this->purgeExpired();
        $rows = $this->createQueryBuilder('ias')
            ->select('ias.id, ias.title, ias.agentKey, ias.contextKind, ias.contextId, ias.turns, ias.dateAdded, ias.dateModified')
            ->where('ias.user = :user')->setParameter('user', $user)
            ->orderBy('ias.dateModified', 'DESC')->addOrderBy('ias.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 200)))->getQuery()->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'agent_key' => (string) $row['agentKey'],
            'context_kind' => $row['contextKind'],
            'context_id' => null === $row['contextId'] ? null : (int) $row['contextId'],
            'turns' => count(is_array($row['turns']) ? $row['turns'] : []),
            'created' => $row['dateAdded']->format(DATE_ATOM),
            'updated' => $row['dateModified']->format(DATE_ATOM),
        ], $rows);
    }

    /** Sempre pelo dono junto: um id de outro operador tem de responder "nao existe". */
    public function openFor(User $user, int $id): ?AssistantSession
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function save(AssistantSession $session): AssistantSession
    {
        $this->getEntityManager()->persist($session);
        $this->getEntityManager()->flush();

        return $session;
    }

    public function remove(AssistantSession $session): void
    {
        $this->getEntityManager()->remove($session);
        $this->getEntityManager()->flush();
    }

    /**
     * Limpeza oportunista na listagem: nao depende de cron para a retencao valer.
     *
     * O tipo do parametro e obrigatorio. Sem ele o Doctrine olha para o DateTimeImmutable,
     * conclui que e uma entidade e vai procurar o metadata dela, o que derruba a consulta --
     * e esta consulta roda dentro da listagem, ou seja, na primeira vez que alguem abre a aba.
     */
    public function purgeExpired(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->modify('-'.self::RETENTION_DAYS.' days');

        return (int) $this->createQueryBuilder('ias')->delete()
            ->where('ias.dateModified < :cutoff')->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()->execute();
    }
}
