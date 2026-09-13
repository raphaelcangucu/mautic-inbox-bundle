<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:inbox:reconcile', description: 'Prepara conversas Meta existentes para o Atendimento.')]
final class ReconcileCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager, private ConversationStateRepository $states, private MetaMessageRepository $messages, private MetaInboxIntegration $integration, private ConversationManager $metaConversations) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('asset-id', null, InputOption::VALUE_REQUIRED, 'ID exato do ativo Meta (obrigatório).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de conversas.', '500')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Grava as alterações; sem esta opção apenas mostra a prévia.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetId = (int) $input->getOption('asset-id');
        $asset = $assetId > 0 ? $this->entityManager->find(MetaAsset::class, $assetId) : null;
        if (!$asset instanceof MetaAsset) { $output->writeln('<error>Informe um --asset-id válido.</error>'); return Command::INVALID; }
        $limit = max(1, min(5000, (int) $input->getOption('limit')));
        $conversations = $this->entityManager->getRepository(MetaConversation::class)->findBy(['asset' => $asset], ['id' => 'ASC'], $limit);
        $missing = array_values(array_filter($conversations, fn (MetaConversation $c): bool => !($this->states->findOneBy(['conversation' => $c]) instanceof ConversationState)));
        $comments = $this->messages->findBy(['asset' => $asset, 'channel' => $asset->getType()->channel()->value, 'direction' => 'inbound', 'messageType' => 'comment'], ['id' => 'ASC'], $limit);
        $output->writeln(sprintf('%d de %d conversa(s) precisam de reconciliação.', count($missing), count($conversations)));
        $output->writeln(sprintf('%d comentário(s) serão verificados e separados por origem.', count($comments)));
        if (!$input->getOption('apply')) { $output->writeln('Prévia concluída. Use --apply para gravar.'); return Command::SUCCESS; }
        // Legacy explicit-change-tracking records may not have saved their conversation link.
        $orphans = $this->messages->findBy(['asset' => $asset, 'conversation' => null], ['id' => 'ASC'], $limit);
        foreach ($orphans as $message) {
            $this->metaConversations->record($message);
            $this->integration->messagePersisted($message);
        }
        foreach ($missing as $conversation) {
            $message = $this->messages->createQueryBuilder('message')->where('message.conversation = :conversation')->andWhere("message.direction = 'inbound'")->andWhere("message.messageType <> 'comment'")->setParameter('conversation', $conversation)->orderBy('message.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if (!$message instanceof MetaMessage) { $message = $this->messages->findOneBy(['conversation' => $conversation, 'direction' => 'outbound'], ['id' => 'DESC']); }
            if ($message instanceof MetaMessage) { $this->integration->messagePersisted($message); }
        }
        foreach ($comments as $comment) {
            $recipient = 'comment:'.(string) ($comment->getPayload()['commentId'] ?? $comment->getExternalId());
            if ($comment->getConversation()?->getRecipient() !== $recipient) { $this->metaConversations->record($comment); }
            $this->integration->messagePersisted($comment);
        }
        foreach ($conversations as $conversation) {
            if (str_starts_with($conversation->getRecipient(), 'comment:')) { continue; }
            $message = $this->messages->createQueryBuilder('message')->where('message.conversation = :conversation')->andWhere("message.direction = 'inbound'")->andWhere("message.messageType <> 'comment'")->setParameter('conversation', $conversation)->orderBy('message.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if ($message instanceof MetaMessage) { $this->integration->messagePersisted($message); }
        }
        // Backfill must retain historical dates, especially the Meta response window.
        foreach ($this->entityManager->getRepository(MetaConversation::class)->findBy(['asset' => $asset], ['id' => 'ASC'], $limit) as $conversation) {
            $latest = $this->messages->findOneBy(['conversation' => $conversation], ['dateAdded' => 'DESC', 'id' => 'DESC']);
            if (!$latest instanceof MetaMessage) { continue; }
            $inbound = $this->messages->findOneBy(['conversation' => $conversation, 'direction' => 'inbound'], ['dateAdded' => 'DESC', 'id' => 'DESC']);
            $conversation->setLastMessageAt($latest->getDateAdded());
            $conversation->setLastInboundAt($inbound?->getDateAdded());
            $conversation->setUnreadCount(min($conversation->getUnreadCount(), $this->messages->count(['conversation' => $conversation, 'direction' => 'inbound'])));
            $this->entityManager->persist($conversation);
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('%d conversa(s) verificadas e %d comentário(s) reconciliados.', count($conversations), count($comments)));
        return Command::SUCCESS;
    }
}
