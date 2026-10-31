<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\EventListener;

use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticInboxBundle\Application\Forms\FormActivity;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * "Respondeu o formulario X" na conversa de onde o link saiu. O envio do formulario e do
 * Mautic e nao pode falhar por causa do Inbox: qualquer erro aqui fica no log.
 */
final class FormSubmissionSubscriber implements EventSubscriberInterface
{
    public function __construct(private FormActivity $activity, private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [FormEvents::FORM_ON_SUBMIT => ['onSubmit', -10]];
    }

    public function onSubmit(SubmissionEvent $event): void
    {
        try {
            $contact = $event->getSubmission()->getLead();
            $form = $event->getForm();
            if (!$contact instanceof Lead || !$form instanceof Form) { return; }
            $this->activity->submitted($contact, $form, $event->getResults());
        } catch (\Throwable $error) {
            $this->logger->warning('Inbox: form answer not recorded in the conversation.', ['error' => $error->getMessage()]);
        }
    }
}
