<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Membership\MembershipManager;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

/**
 * Liga o endereco que apareceu na conversa ao CRM.
 *
 * Um comentarista do Facebook chega sem e-mail nenhum — o Meta nao entrega isso. Quando a pessoa
 * digita o endereco no proprio comentario, aquele texto e a unica ponte entre a conversa e o
 * contato do Mautic, e ate agora era preciso copiar a mao, procurar o contato e editar. Aqui a
 * ponte vira uma acao.
 */
final class ContactLinking
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private LeadModel $leads,
        private MembershipManager $membership,
        private CorePermissions $permissions,
    ) {
    }

    /**
     * Normaliza e recusa o que nao e endereco. A deteccao no cliente e generosa de proposito —
     * ela so precisa acertar o que vale a pena oferecer — entao a recusa mora aqui, do lado que
     * grava.
     */
    public function email(string $raw): string
    {
        $email = mb_strtolower(trim($raw));
        if ('' === $email || mb_strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InboxException('mautic.inbox.contact.invalid_email', 422);
        }

        return $email;
    }

    /**
     * O que a folha de acoes precisa, numa ida so: o contato que a conversa ja tem, os contatos
     * que ja usam aquele endereco, e as campanhas e segmentos que o atendente pode escolher.
     *
     * @return array<string, mixed>
     */
    public function options(MetaConversation $conversation, string $email, User $user): array
    {
        $current = $conversation->getContact();
        $matches = [];
        foreach ($this->byEmail($email) as $found) {
            // O contato da conversa nao entra como "outro": ele ja esta na tela, acima.
            if (null !== $current && null !== $current->getId() && $current->getId() === $found->getId()) {
                continue;
            }
            $matches[] = $this->card($found);
        }

        return [
            'email' => $email,
            'contact' => null === $current ? null : $this->card($current),
            'matches' => $matches,
            'campaigns' => $this->pick('campaigns', 'campaign:campaigns', $user),
            'segments' => $this->pick('lead_lists', 'lead:lists', $user),
            'can_edit' => $this->permissions->isGranted(['lead:leads:editown', 'lead:leads:editother'], 'MATCH_ONE'),
        ];
    }

    /**
     * Grava. Nada aqui e feito por adivinhacao: o contato alvo chega decidido pela tela, porque
     * escolher entre o contato da conversa e um homonimo por e-mail e julgamento de quem atende,
     * nao regra de codigo.
     *
     * @return array<string, mixed>
     */
    public function apply(MetaConversation $conversation, string $email, ?int $contactId, bool $saveEmail, ?int $campaignId, ?int $segmentId, User $user): array
    {
        if (!$this->permissions->isGranted(['lead:leads:editown', 'lead:leads:editother'], 'MATCH_ONE')) {
            throw new InboxException('mautic.inbox.contact.not_allowed', 403);
        }

        $contact = $this->target($conversation, $email, $contactId);
        $done = [];

        if ($saveEmail && $email !== mb_strtolower((string) $contact->getEmail())) {
            $contact->addUpdatedField('email', $email);
            $this->leads->saveEntity($contact);
            $done[] = 'email';
        }

        // A conversa passa a apontar para o contato escolhido. Sem isto a acao seguinte — abrir a
        // ficha, ver a campanha — nao encontra nada a partir da conversa.
        if ($conversation->getContact() !== $contact) {
            $conversation->setContact($contact);
            $this->entityManager->persist($conversation);
            $this->entityManager->flush();
            $done[] = 'linked';
        }

        if (null !== $campaignId) {
            $campaign = $this->entityManager->find(Campaign::class, $campaignId);
            if (!$campaign instanceof Campaign) {
                throw new InboxException('mautic.inbox.contact.campaign_missing', 404);
            }
            $this->membership->addContacts(new ArrayCollection([$contact]), $campaign, true);
            $done[] = 'campaign';
        }

        if (null !== $segmentId) {
            $segment = $this->entityManager->find(LeadList::class, $segmentId);
            if (!$segment instanceof LeadList) {
                throw new InboxException('mautic.inbox.contact.segment_missing', 404);
            }
            $this->leads->addToLists($contact, $segment);
            $done[] = 'segment';
        }

        return ['contact' => $this->card($contact), 'applied' => $done];
    }

    /** O contato que recebe a acao: o escolhido na tela, o da conversa, ou um novo. */
    private function target(MetaConversation $conversation, string $email, ?int $contactId): Lead
    {
        if (null !== $contactId && $contactId > 0) {
            $chosen = $this->entityManager->find(Lead::class, $contactId);
            if (!$chosen instanceof Lead) {
                throw new InboxException('mautic.inbox.contact.contact_missing', 404);
            }

            return $chosen;
        }

        $current = $conversation->getContact();
        if ($current instanceof Lead) {
            return $current;
        }

        // Sem contato e sem escolha: um homonimo por e-mail e melhor ponto de partida que um
        // registro novo, porque o novo nasceria duplicado no mesmo instante.
        $existing = $this->byEmail($email);
        if ([] !== $existing) {
            return $existing[0];
        }

        $novo = new Lead();
        $novo->addUpdatedField('email', $email);
        $this->leads->saveEntity($novo);

        return $novo;
    }

    /** @return Lead[] */
    private function byEmail(string $email): array
    {
        $found = $this->leads->getRepository()->getLeadsByFieldValue('email', $email);

        return is_array($found) ? array_values(array_filter($found, static fn ($l) => $l instanceof Lead)) : [];
    }

    /** @return array<string, mixed> */
    private function card(Lead $contact): array
    {
        return [
            'id' => (int) $contact->getId(),
            'name' => trim((string) $contact->getName()) ?: trim((string) $contact->getEmail()) ?: '#'.$contact->getId(),
            'email' => (string) $contact->getEmail(),
        ];
    }

    /**
     * As campanhas e os segmentos publicados, em SQL direto: a tela so precisa de id e nome, e
     * carregar as entidades inteiras para desenhar duas listas custaria caro numa rota que abre
     * a cada toque.
     *
     * Quando o atendente so tem permissao sobre o que e dele, a lista se limita ao que e dele —
     * a mesma regra que o Mautic aplica nas telas proprias.
     *
     * @return list<array{id:int,name:string}>
     */
    private function pick(string $table, string $permission, User $user): array
    {
        if (!$this->permissions->isGranted([$permission.':viewown', $permission.':viewother'], 'MATCH_ONE')) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder()
            ->select('id', 'name')
            // Mesma guarda do WhatsAppConversationMerger: a constante so existe com o kernel de pe.
            ->from((defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '').$table)
            ->where('is_published = 1')
            ->orderBy('name', 'ASC')
            ->setMaxResults(200);

        if (!$this->permissions->isGranted($permission.':viewother')) {
            $qb->andWhere('created_by = :owner')->setParameter('owner', (int) $user->getId());
        }

        return array_map(
            static fn (array $row) => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $qb->executeQuery()->fetchAllAssociative()
        );
    }
}
