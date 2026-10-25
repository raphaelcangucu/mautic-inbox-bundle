<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use MauticPlugin\MauticInboxBundle\Application\Email\CampaignEmailPolicy;
use MauticPlugin\MauticInboxBundle\Application\Email\EmailReply;
use MauticPlugin\MauticInboxBundle\Application\Email\InboxSigner;
use MauticPlugin\MauticInboxBundle\Application\Forms\FormLinks;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Application\Mobile\EmailStartOptions;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Form\Type\EmailConversationActionType;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Iniciar conversa por e-mail": o opt-in de campanha. Mora no Inbox porque abrir conversa ja
 * e daqui (ContactDirectory::start) e o link do formulario tambem; a acao so garante a conversa
 * e enfileira email_message. O evento passa quando o job entra na fila; entrega e falha de
 * entrega aparecem no Inbox, na conversa.
 */
final class EmailCampaignSubscriber implements EventSubscriberInterface
{
    public const ACTION = 'inbox.email.start_conversation';
    public const BATCH_EVENT = 'mautic.inbox.campaign_email_start';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OutboundQueue $queue,
        private DoNotContact $doNotContact,
        private FormLinks $forms,
        private InboxSigner $signer,
        private MetaInboxIntegration $integration,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CampaignEvents::CAMPAIGN_ON_BUILD => ['onBuild', 0], self::BATCH_EVENT => ['onStart', 0]];
    }

    public function onBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(self::ACTION, [
            'label' => 'mautic.inbox.campaign.email_start',
            'description' => 'mautic.inbox.campaign.email_start_descr',
            'batchEventName' => self::BATCH_EVENT,
            'formType' => EmailConversationActionType::class,
        ]);
    }

    public function onStart(PendingEvent $event): void
    {
        if (!$event->checkContext(self::ACTION)) { return; }
        $event->setChannel('email');
        $campaignEvent = $event->getEvent();
        $properties = $campaignEvent->getProperties();
        $asset = $this->entityManager->find(MetaAsset::class, (int) ($properties['asset_id'] ?? 0));
        $usable = $asset instanceof MetaAsset && 'email_account' === $asset->getType()->value && 'active' === $asset->getStatus() && $asset->isPublished();
        foreach ($event->getPending() as $log) {
            if (!$usable) {
                $event->fail($log, $this->translator->trans('mautic.inbox.campaign.account_unavailable'), new \DateInterval('PT1H'));
                continue;
            }
            try {
                $outcome = $this->start($log, $asset, $properties, (int) $campaignEvent->getCampaign()->getId(), (string) $campaignEvent->getCampaign()->getName());
                if (null === $outcome) {
                    $event->pass($log);
                } elseif ($outcome['retry']) {
                    $event->fail($log, $outcome['reason']);
                } else {
                    // Sem e-mail, DNC ou e-mail de outro contato nao mudam sozinhos: tentar de novo
                    // so encheria o log. O evento fecha com o motivo, como o envio de e-mail do core.
                    $event->passWithError($log, $outcome['reason']);
                }
            } catch (\Throwable $error) {
                $this->logger->error('Inbox email campaign action failed.', ['error' => $error->getMessage()]);
                $event->fail($log, $error instanceof InboxException ? $this->translator->trans($error->getMessage()) : $error->getMessage(), new \DateInterval('PT15M'));
            }
        }
    }

    /**
     * @param array<string,mixed> $properties
     * @return array{reason:string,retry:bool}|null o motivo de nao enviar, ou null quando o job entrou na fila
     */
    private function start(LeadEventLog $log, MetaAsset $asset, array $properties, int $campaignId, string $campaignName): ?array
    {
        $key = CampaignEmailPolicy::idempotencyKey((int) $log->getId());
        $queued = $this->entityManager->getRepository(MetaOutboundJob::class)->findOneBy(['idempotencyKey' => $key]);
        if ($queued instanceof MetaOutboundJob) {
            // Reexecucao do mesmo evento: o e-mail ja esta na fila, e a linha do tempo ja diz.
            $log->appendToMetadata(['inbox_job_id' => $queued->getId(), 'queued' => true]);

            return null;
        }
        $contact = $log->getLead();
        $recipient = EmailStartOptions::recipient($contact->getEmail());
        $dnc = null !== $recipient && DNC::IS_CONTACTABLE !== $this->doNotContact->isContactable($contact, 'email');
        if (null === $recipient || $dnc) {
            return ['reason' => $this->translator->trans(null === $recipient ? 'mautic.inbox.campaign.no_email' : 'mautic.inbox.campaign.dnc'), 'retry' => false];
        }
        $context = (new ConversationState())->setConversation((new MetaConversation())->setAsset($asset)->setChannel('email')->setRecipient($recipient));

        return $this->integration->runHumanTransition($context, function () use ($log, $asset, $properties, $recipient, $contact, $key, $campaignId, $campaignName): ?array {
            $conversation = $this->entityManager->getRepository(MetaConversation::class)->findOneBy(['asset' => $asset, 'channel' => 'email', 'recipient' => $recipient]);
            $state = $conversation instanceof MetaConversation && null !== $conversation->getId() ? $this->entityManager->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]) : null;
            $outcome = CampaignEmailPolicy::outcome(
                $recipient,
                false,
                $state instanceof ConversationState ? ['lifecycle' => $state->getLifecycle(), 'assigned' => null !== $state->getAssignee(), 'takeover' => $state->isHumanTakeover()] : null,
                $conversation?->getContact()?->getId(),
                (int) $contact->getId(),
            );
            if (CampaignEmailPolicy::SEND !== $outcome) {
                // Em atendimento passa quando o atendente resolve; o e-mail de outro contato, nao.
                $inService = CampaignEmailPolicy::IN_SERVICE === $outcome;

                return ['reason' => $this->translator->trans($inService ? 'mautic.inbox.campaign.in_service' : 'mautic.inbox.campaign.other_contact'), 'retry' => $inService];
            }
            if (!$conversation instanceof MetaConversation) {
                $conversation = (new MetaConversation())->setAsset($asset)->setChannel('email')->setRecipient($recipient);
                $this->entityManager->persist($conversation);
            }
            $conversation->setContact($contact);
            // Entidades do Mautic usam rastreio explicito: sem persist, o vinculo nao seria gravado.
            $this->entityManager->persist($conversation);
            if (!$state instanceof ConversationState) {
                $state = (new ConversationState())->setConversation($conversation)->setNeedsResponse(false);
                $this->entityManager->persist($state);
                $this->entityManager->persist((new EventLog())->setConversation($conversation)->setEventType('created')->setDetails(['source' => 'campaign', 'channel' => 'email']));
            }
            $this->entityManager->flush();

            // O executor de campanha costuma entregar o contato com os campos carregados; quando
            // nao, os campos fixos ainda resolvem os tokens mais usados.
            $fields = array_replace(array_filter([
                'id' => $contact->getId(),
                'firstname' => $contact->getFirstname(),
                'lastname' => $contact->getLastname(),
                'email' => $contact->getEmail(),
            ],static fn (mixed $value): bool => null !== $value && '' !== $value), array_filter($contact->getProfileFields(), static fn (mixed $value): bool => null !== $value && '' !== $value));
            $subject = EmailReply::clean((string) TokenHelper::findLeadTokens((string) ($properties['subject'] ?? ''), $fields, true));
            $text = trim((string) TokenHelper::findLeadTokens((string) ($properties['message'] ?? ''), $fields, true));
            if ('' === $subject || '' === $text) { return ['reason' => $this->translator->trans('mautic.inbox.ui.write_a_message_of_up_to_4_000_characters_7aa641'), 'retry' => false]; }
            $expanded = $this->forms->expand($text, $conversation, true);
            $fromName = EmailReply::clean((string) ($properties['from_name'] ?? ''));
            $job = $this->queue->enqueue($asset, 'email_message', [
                'recipient' => $recipient,
                'subject' => $subject,
                'text' => $expanded,
                'author_name' => null,
                'from_name' => '' !== $fromName ? $fromName : null,
                'unsubscribe_url' => $this->signer->siteUrl().'/inbox/u/'.$this->signer->sign(InboxSigner::UNSUBSCRIBE, [(int) $contact->getId(), (int) $asset->getId()]),
                '_origin' => 'inbox_campaign',
                '_inbox_conversation_id' => $conversation->getId(),
            ], $contact, ConversationActions::sendAttempts($asset), $key);
            $this->entityManager->persist((new EventLog())->setConversation($conversation)->setEventType('campaign_email_started')->setDetails([
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'job_id' => $job->getId(),
            ]));
            $this->entityManager->flush();
            $log->appendToMetadata(['inbox_job_id' => $job->getId(), 'inbox_conversation_id' => $conversation->getId(), 'queued' => true]);

            return null;
        });
    }
}
