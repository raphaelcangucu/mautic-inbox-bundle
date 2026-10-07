<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Entity\{CannedResponse,CannedResponseRepository};

final class CannedResponses
{
    public function create(array $payload, User $actor, CannedResponseRepository $responses, EntityManagerInterface $em): array
    {
        $name = trim((string) ($payload['name'] ?? '')); $body = trim((string) ($payload['body'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100 || $body === '' || mb_strlen($body) > 4000) { throw new InboxException('mautic.inbox.ui.enter_a_name_and_text_of_up_to_4_000_characters_2f496b'); }
        $existing = $responses->findOneBy(['name' => $name]);
        if ($existing instanceof CannedResponse && $existing->isEnabled()) { throw new InboxException('mautic.inbox.settings.canned_duplicate', 409); }
        $response = $existing ?? new CannedResponse();
        $response->setName($name)->setBody($body)->setEnabled(true)->setCreatedBy($response->getCreatedBy() ?? $actor);
        $em->persist($response);
        try { $em->flush(); } catch (UniqueConstraintViolationException) { throw new InboxException('mautic.inbox.settings.canned_duplicate', 409); }
        return ['id' => (int) $response->getId(), 'name' => $name, 'body' => $body, 'enabled' => true];
    }
}
