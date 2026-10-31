<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\AssistantSession;
use MauticPlugin\MauticInboxBundle\Entity\AssistantSessionRepository;

/**
 * As sessoes do assistente do ponto de vista do app: abrir, listar, retomar e apagar.
 *
 * A validacao e o recorte vivem aqui, em metodos estaticos puros, para que os testes do
 * diretorio Tests/Mobile possam exercita-los sem banco nem kernel, como ja fazem com o
 * SessionStore e o ModerationStore.
 */
final class AssistantSessions
{
    /** Teto de turnos por sessao: o JSON de uma linha nao pode crescer sem fim. */
    public const MAX_TURNS = 120;
    public const MAX_TEXT = 4000;
    public const MAX_TITLE = 160;
    private const CONTEXTS = ['conversation', 'contact'];

    public function __construct(private AssistantSessionRepository $sessions) {}

    public function listFor(User $user): array
    {
        return ['items' => $this->sessions->listFor($user)];
    }

    public function start(array $payload, User $user): array
    {
        $agent = trim((string) ($payload['agent_key'] ?? ''));
        if (strlen($agent) > 100) { throw new \DomainException('invalid_request'); }
        [$kind, $id] = self::context($payload['context'] ?? null);
        $session = (new AssistantSession())->setUser($user)->setAgentKey($agent)
            ->setTitle(self::title((string) ($payload['title'] ?? '')))
            ->openedFrom($kind, $id);

        return self::describe($this->sessions->save($session), true);
    }

    public function open(int $id, User $user): array
    {
        return self::describe($this->session($id, $user), true);
    }

    /** O app manda o fio inteiro que tem na tela; aqui ele e recortado e substitui o guardado. */
    public function record(int $id, array $payload, User $user): array
    {
        $session = $this->session($id, $user);
        if (!array_key_exists('turns', $payload) || !is_array($payload['turns'])) { throw new \DomainException('invalid_request'); }
        $session->setTurns(self::turns($payload['turns']));
        $title = trim((string) ($payload['title'] ?? ''));
        if ('' !== $title) { $session->setTitle(self::title($title)); }
        if ('' === $session->getTitle()) { $session->setTitle(self::titleFromTurns($session->getTurns())); }

        return self::describe($this->sessions->save($session), true);
    }

    public function remove(int $id, User $user): array
    {
        $this->sessions->remove($this->session($id, $user));

        return ['removed' => true];
    }

    private function session(int $id, User $user): AssistantSession
    {
        $session = $this->sessions->openFor($user, $id);
        if (!$session instanceof AssistantSession) { throw new \DomainException('not_found'); }

        return $session;
    }

    public static function describe(AssistantSession $session, bool $withTurns = false): array
    {
        $body = [
            'id' => (int) $session->getId(),
            'title' => $session->getTitle(),
            'agent_key' => $session->getAgentKey(),
            'context_kind' => $session->getContextKind(),
            'context_id' => $session->getContextId(),
            'turns' => count($session->getTurns()),
            'created' => $session->getDateAdded()->format(DATE_ATOM),
            'updated' => $session->getDateModified()->format(DATE_ATOM),
        ];

        return $withTurns ? $body + ['history' => $session->getTurns()] : $body;
    }

    /** @return array{0:?string,1:?int} */
    public static function context(mixed $context): array
    {
        if (!is_array($context)) { return [null, null]; }
        $kind = (string) ($context['kind'] ?? '');
        $id = (int) ($context['id'] ?? 0);

        return in_array($kind, self::CONTEXTS, true) && $id > 0 ? [$kind, $id] : [null, null];
    }

    public static function title(string $raw): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '');

        return mb_substr($clean, 0, self::MAX_TITLE);
    }

    public static function titleFromTurns(array $turns): string
    {
        foreach ($turns as $turn) {
            if (is_array($turn) && 'user' === ($turn['role'] ?? '') && '' !== trim((string) ($turn['text'] ?? ''))) {
                return self::title(mb_substr((string) $turn['text'], 0, 80));
            }
        }

        return '';
    }

    /**
     * So o fio legivel e guardado. As propostas de acao ficam de fora de proposito: elas
     * expiram e sao reemitidas pelo assistente, e uma sessao retomada nao pode oferecer
     * como clicavel uma acao que o servidor ja esqueceu.
     */
    public static function turns(array $turns): array
    {
        $clean = [];
        foreach ($turns as $turn) {
            if (!is_array($turn)) { continue; }
            $role = (string) ($turn['role'] ?? '');
            $text = (string) ($turn['text'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true) || '' === trim($text)) { continue; }
            $row = ['id' => mb_substr((string) ($turn['id'] ?? ''), 0, 64), 'role' => $role, 'text' => mb_substr($text, 0, self::MAX_TEXT)];
            if ('' === $row['id']) { $row['id'] = bin2hex(random_bytes(8)); }
            $tool = mb_substr((string) ($turn['tool'] ?? ''), 0, 64);
            if ('' !== $tool) { $row['tool'] = $tool; }
            $conversation = (int) ($turn['conversationId'] ?? 0);
            if ($conversation > 0) { $row['conversationId'] = $conversation; }
            $clean[] = $row;
        }

        return count($clean) > self::MAX_TURNS ? array_slice($clean, -self::MAX_TURNS) : $clean;
    }
}
