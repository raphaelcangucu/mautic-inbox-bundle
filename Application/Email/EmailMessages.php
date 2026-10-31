<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Email;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O lado do Inbox do conteudo guardado de um e-mail: anexos e original. O provider (Multi
 * Mail) diz onde esta o arquivo; quem decide se ele sai, e com que cabecalhos, e daqui.
 *
 * A checagem de acesso a conversa fica com quem chama -- navegador, bearer do app ou link
 * assinado --, porque cada um conhece o usuario de um jeito.
 */
final class EmailMessages
{
    /** O link do original no app vale cinco minutos: o navegador do sistema nao manda o Bearer. */
    public const ORIGINAL_LINK_SECONDS = 300;

    /** Imagens que o navegador pode mostrar em linha; todo o resto desce como arquivo. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** O que pode sair com o proprio tipo. Fora daqui, application/octet-stream. */
    private const SAFE_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic',
        'application/pdf', 'text/plain', 'text/csv', 'text/calendar',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
        'application/zip', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'video/mp4', 'video/quicktime',
    ];

    private const INLINE_IMAGE_BYTES = 1_048_576;
    private const INLINE_TOTAL_BYTES = 4_194_304;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailContentRegistry $content,
        private EmailHtmlSanitizer $sanitizer,
        private UrlGeneratorInterface $urls,
        private InboxSigner $signer,
    ) {
    }

    /** A mensagem de e-mail e a conversa dela no Inbox, ou null. */
    public function find(int $messageId): ?array
    {
        $message = $this->entityManager->find(MetaMessage::class, $messageId);
        if (!$message instanceof MetaMessage || 'email' !== $message->getChannel() || null === $message->getConversation()) { return null; }
        $state = $this->entityManager->getRepository(ConversationState::class)->findOneBy(['conversation' => $message->getConversation()]);

        return $state instanceof ConversationState ? ['message' => $message, 'state' => $state] : null;
    }

    public function attachment(MetaMessage $message, int $index): Response
    {
        $listed = $this->listed($message, $index);
        $file = null !== $listed && !empty($listed['available']) ? $this->content->attachment($message, $index) : null;
        if (null === $file) {
            return new Response('Arquivo indisponível.', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $mime = self::safeType($file['mime']);
        $name = trim(basename(str_replace('\\', '/', $file['name']))) ?: 'anexo';
        $response = new BinaryFileResponse($file['path'], Response::HTTP_OK, [], false, null, false, false);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            in_array($mime, self::INLINE, true) ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            self::asciiName($name),
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Um anexo aberto direto no navegador e um documento sem origem: sem script, sem form,
        // sem acesso ao Mautic, mesmo que o tipo engane.
        $response->headers->set('Content-Security-Policy', 'sandbox');
        $response->headers->set('Cache-Control', 'private, max-age=300');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * O original completo, saneado. Imagem embutida (cid:) vira a URL do proxy de anexo no web
     * e data: no link do app, que abre num navegador sem a sessao do Mautic.
     */
    public function original(MetaMessage $message, bool $inlineImages): ?string
    {
        if (empty($message->getPayload()['email']['has_original'])) { return null; }
        $html = $this->content->originalHtml($message);
        if (null === $html) { return null; }
        $budget = self::INLINE_TOTAL_BYTES;

        return $this->sanitizer->sanitize($html, function (string $cid) use ($message, $inlineImages, &$budget): ?string {
            foreach ($this->attachments($message) as $position => $attachment) {
                if (strtolower(trim((string) ($attachment['cid'] ?? ''), ' <>')) !== strtolower($cid) || empty($attachment['available'])) { continue; }
                $index = is_numeric($attachment['index'] ?? null) ? (int) $attachment['index'] : $position;
                if (!$inlineImages) {
                    return $this->urls->generate('mautic_inbox_email_attachment', ['messageId' => $message->getId(), 'index' => $index], UrlGeneratorInterface::ABSOLUTE_URL);
                }
                $file = $this->content->attachment($message, $index);
                $mime = null === $file ? '' : self::safeType($file['mime']);
                if (null === $file || !in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true) || $file['size'] > self::INLINE_IMAGE_BYTES || $file['size'] > $budget) { return null; }
                $budget -= $file['size'];
                $bytes = file_get_contents($file['path']);

                return false === $bytes ? null : 'data:'.$mime.';base64,'.base64_encode($bytes);
            }

            return null;
        });
    }

    /** @return array{url:string,expires_at:string} */
    public function originalLink(MetaMessage $message, User $user, ?int $now = null): array
    {
        $expires = ($now ?? time()) + self::ORIGINAL_LINK_SECONDS;

        return [
            'url' => $this->signer->siteUrl().'/inbox/mobile/original/'.$this->signer->sign(InboxSigner::ORIGINAL, [(int) $message->getId(), (int) $user->getId(), $expires]),
            'expires_at' => gmdate(DATE_ATOM, $expires),
        ];
    }

    /** @return array{message:int,user:int}|null */
    public function resolveOriginalLink(string $token, ?int $now = null): ?array
    {
        $values = $this->signer->verify(InboxSigner::ORIGINAL, $token, 3);

        return null === $values || $values[2] < ($now ?? time()) ? null : ['message' => $values[0], 'user' => $values[1]];
    }

    public function hasOriginal(MetaMessage $message): bool
    {
        return !empty($message->getPayload()['email']['has_original']) && $this->content->supports($message);
    }

    public static function safeType(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return in_array($mime, self::SAFE_TYPES, true) ? $mime : 'application/octet-stream';
    }

    /** @return list<array<string,mixed>> */
    private function attachments(MetaMessage $message): array
    {
        $list = $message->getPayload()['email']['attachments'] ?? [];

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** O anexo como o payload o lista: o indice pedido precisa existir e estar disponivel. */
    private function listed(MetaMessage $message, int $index): ?array
    {
        foreach ($this->attachments($message) as $position => $attachment) {
            if ((is_numeric($attachment['index'] ?? null) ? (int) $attachment['index'] : $position) === $index) { return $attachment; }
        }

        return null;
    }

    private static function asciiName(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));

        return '' === trim($ascii, '_.') ? 'anexo' : $ascii;
    }
}
