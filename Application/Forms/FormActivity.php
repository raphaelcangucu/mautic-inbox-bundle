<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Forms;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

/**
 * O que o formulario de pesquisa deixa na linha do tempo: "abriu o formulario X" quando o
 * link e aberto, e "respondeu o formulario X", com as respostas, quando o envio chega.
 */
final class FormActivity
{
    /** Abrir o link de novo logo depois nao e noticia: uma linha por formulario a cada 10 min. */
    private const REOPEN_QUIET_SECONDS = 600;
    private const SKIPPED_FIELD_TYPES = ['button', 'captcha', 'freetext', 'freehtml', 'pagebreak', 'plugin.loginSocial'];
    private const MAX_ANSWERS = 50;
    private const MAX_ANSWER_CHARS = 500;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function opened(MetaConversation $conversation, Form $form, Lead $contact): void
    {
        $recent = $this->entityManager->createQueryBuilder()->select('e')->from(EventLog::class, 'e')
            ->where('e.conversation = :conversation')->andWhere('e.eventType = :type')->andWhere('e.dateAdded >= :since')
            ->setParameter('conversation', $conversation)->setParameter('type', 'form_opened')
            ->setParameter('since', new \DateTimeImmutable('-'.self::REOPEN_QUIET_SECONDS.' seconds'))
            ->setMaxResults(20)->getQuery()->getResult();
        foreach ($recent as $event) {
            if ($event instanceof EventLog && (int) ($event->getDetails()['form_id'] ?? 0) === (int) $form->getId()) { return; }
        }
        $this->entityManager->persist((new EventLog())->setConversation($conversation)->setEventType('form_opened')->setDetails([
            'form_id' => (int) $form->getId(),
            'form_name' => (string) $form->getName(),
            'contact_id' => (int) $contact->getId(),
        ]));
        $this->entityManager->flush();
    }

    /**
     * Acha a conversa pelo contato e pelo formulario aberto nos ultimos 30 dias. Sem link
     * aberto nao ha conversa a quem atribuir a resposta, e o envio segue so no Mautic.
     *
     * @param array<string,mixed> $results alias do campo => valor, como o Mautic entrega
     */
    public function submitted(Lead $contact, Form $form, array $results): ?MetaConversation
    {
        if (null === $contact->getId()) { return null; }
        $opened = $this->entityManager->createQueryBuilder()->select('e')->from(EventLog::class, 'e')->join('e.conversation', 'c')
            ->where('c.contact = :contact')->andWhere('e.eventType = :type')->andWhere('e.dateAdded >= :since')
            ->setParameter('contact', $contact)->setParameter('type', 'form_opened')
            ->setParameter('since', new \DateTimeImmutable('-'.FormLinks::VALIDITY_SECONDS.' seconds'))
            ->orderBy('e.dateAdded', 'DESC')->addOrderBy('e.id', 'DESC')->setMaxResults(100)->getQuery()->getResult();
        $conversation = null;
        foreach ($opened as $event) {
            if ($event instanceof EventLog && (int) ($event->getDetails()['form_id'] ?? 0) === (int) $form->getId()) { $conversation = $event->getConversation(); break; }
        }
        if (null === $conversation) { return null; }
        $this->entityManager->persist((new EventLog())->setConversation($conversation)->setEventType('form_submitted')->setDetails([
            'form_id' => (int) $form->getId(),
            'form_name' => (string) $form->getName(),
            'answers' => self::answers($form, $results),
        ]));
        $this->entityManager->flush();

        return $conversation;
    }

    /**
     * As respostas na ordem do formulario, com o rotulo que o lead viu.
     *
     * @param array<string,mixed> $results
     * @return list<array{label:string,value:string}>
     */
    public static function answers(Form $form, array $results): array
    {
        $answers = [];
        foreach ($form->getFields() as $field) {
            if (!$field instanceof Field || in_array((string) $field->getType(), self::SKIPPED_FIELD_TYPES, true)) { continue; }
            $alias = (string) $field->getAlias();
            if (!array_key_exists($alias, $results)) { continue; }
            $value = $results[$alias];
            $value = is_array($value) ? implode(', ', array_map('strval', array_filter($value, 'is_scalar'))) : (is_scalar($value) ? (string) $value : '');
            $value = trim(strip_tags($value));
            if ('' === $value) { continue; }
            $answers[] = ['label' => mb_substr(trim(strip_tags((string) $field->getLabel())) ?: $alias, 0, 200), 'value' => mb_substr($value, 0, self::MAX_ANSWER_CHARS)];
            if (count($answers) >= self::MAX_ANSWERS) { break; }
        }

        return $answers;
    }
}
