<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class ReplyAvailability
{
    public function __construct(private EntityManagerInterface $entityManager) {}
    public function reason(ConversationState $state): ?string
    {
        $c = $state->getConversation();
        if ('resolved' === $state->getLifecycle()) { return 'Esta conversa está resolvida. Reabra para responder.'; }
        if ('active' !== $c->getAsset()->getStatus() || !$c->getAsset()->isPublished()) { return 'O canal está indisponível. Revise a conexão em Meta.'; }
        if ('facebook' === $c->getChannel() && false === ($c->getAsset()->getSettings()['facebook_reply_enabled'] ?? true)) { return 'A conexão do Facebook precisa de permissões adicionais na Meta antes de enviar respostas.'; }
        $repo = $this->entityManager->getRepository(MetaMessage::class);
        $comment = str_starts_with($c->getRecipient(), 'comment:');
        if ($comment && 'facebook' === $c->getChannel()) {
            $source = $repo->findOneBy(['conversation' => $c, 'direction' => 'inbound', 'messageType' => 'comment']);
            return !$source || !empty($source->getPayload()['removed']) ? 'Este comentário foi removido ou não está disponível para resposta.' : null;
        }
        if ($comment && ($repo->findOneBy(['conversation' => $c, 'direction' => 'outbound', 'messageType' => 'private_reply', 'status' => ['accepted', 'sent', 'delivered', 'read', 'pending', 'processing', 'uncertain']]) || $this->entityManager->getRepository(OutboundRequest::class)->findOneBy(['conversation' => $c, 'status' => ['pending', 'processing', 'waiting', 'uncertain', 'sent', 'accepted', 'delivered', 'read']]))) {
            return 'Este comentário já recebeu uma resposta privada ou tem um envio em andamento. Aguarde a pessoa responder no Direct para continuar.';
        }
        $last = $repo->findOneBy(['conversation' => $c, 'direction' => 'inbound'], ['dateAdded' => 'DESC', 'id' => 'DESC']);
        if ('whatsapp' === $c->getChannel() && $c->getContact()) {
            // Match the connector's window policy when Brazilian phone normalization changed the recipient.
            $last = $repo->createQueryBuilder('m')->where('m.asset = :asset AND m.channel = :channel AND m.direction = :direction')
                ->andWhere('(m.recipient = :recipient OR m.contact = :contact)')
                ->setParameters(['asset' => $c->getAsset(), 'channel' => 'whatsapp', 'direction' => 'inbound', 'recipient' => $c->getRecipient(), 'contact' => $c->getContact()])
                ->orderBy('m.dateAdded', 'DESC')->addOrderBy('m.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        }
        if ($comment) {
            if (!$last || $last->getDateAdded() < new \DateTimeImmutable('-7 days')) { return 'O prazo para responder a este comentário por mensagem privada terminou.'; }
        } elseif (!$last || $last->getDateAdded() < new \DateTimeImmutable('-24 hours')) {
            return 'whatsapp' === $c->getChannel() ? 'Não há mensagem recebida nas últimas 24 horas. Para iniciar o contato, use um modelo aprovado do WhatsApp; a resposta livre será liberada quando a pessoa responder.' : ('facebook' === $c->getChannel() ? 'Não há mensagem recebida nas últimas 24 horas. Aguarde uma nova mensagem no Messenger para responder.' : 'Não há mensagem recebida nas últimas 24 horas. Aguarde uma nova mensagem no Direct para responder por aqui.');
        }
        return null;
    }
}
