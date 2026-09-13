<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;

final class MessagePresentation
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function present(MetaMessage $message): array
    {
        $payload = $message->getPayload();
        $content = is_array($payload['message'] ?? null) ? $payload['message'] : $payload;
        $type = $message->getMessageType();
        $text = $content['text']['body'] ?? $content['text'] ?? $payload['text'] ?? '';
        $text = is_string($text) ? $text : '';
        if ('' === $text && is_string($payload['message'] ?? null)) { $text = $payload['message']; }
        $caption = null;
        $attachments = [];
        if ('template' === $type) {
            $template = $payload['template'] ?? $content['template'] ?? [];
            $name = (string) ($template['name'] ?? 'Sem nome');
            $language = (string) ($template['language']['code'] ?? '');
            $caption = 'Modelo de WhatsApp · '.$name.' · '.$language;
            // Match the owning connection as well as language; never show another account's template.
            $matches = $this->entityManager->createQueryBuilder()->select('t')->from(WhatsAppTemplate::class, 't')->join('t.businessAccount', 'a')
                ->where('a.connection = :connection AND t.name = :name AND t.language = :language')
                ->setParameters(['connection' => $message->getAsset()->getConnection(), 'name' => $name, 'language' => $language])->setMaxResults(2)->getQuery()->getResult();
            $components = 1 === count($matches) ? $matches[0]->getComponents() : [];
            $parts = [];
            foreach ($components as $component) {
                $part = $component['text'] ?? null;
                if (!is_string($part)) { continue; }
                foreach ($template['components'] ?? [] as $sent) {
                    if (strtolower((string) ($sent['type'] ?? '')) !== strtolower((string) ($component['type'] ?? ''))) { continue; }
                    foreach ($sent['parameters'] ?? [] as $index => $parameter) {
                        if (is_string($parameter['text'] ?? null)) { $part = str_replace('{{'.($index + 1).'}}', $parameter['text'], $part); }
                    }
                }
                $parts[] = $part;
            }
            $text = $parts ? implode("\n\n", $parts) : 'Modelo: '.$name.'. O texto deste modelo não está disponível no catálogo local.';
            if ($parts) { $caption .= ' · versão do catálogo'; }
        } elseif ('unsupported' === $type) {
            $code = $content['errors'][0]['code'] ?? null;
            $text = 'O WhatsApp não disponibilizou o conteúdo desta mensagem'.($code ? ' (código '.$code.')' : '').'. Abra a conversa no aplicativo de origem ou peça que a pessoa reenvie o conteúdo como texto.';
            $caption = 'Mensagem não disponibilizada pelo WhatsApp';
        } elseif (isset($content['interactive'])) {
            $reply = $content['interactive']['button_reply'] ?? $content['interactive']['list_reply'] ?? [];
            $text = (string) ($reply['title'] ?? $reply['description'] ?? 'Resposta interativa');
        } elseif (isset($content['button'])) {
            $text = (string) ($content['button']['text'] ?? 'Resposta por botão');
        }
        foreach (['image' => 'Imagem', 'audio' => 'Áudio', 'video' => 'Vídeo', 'document' => 'Documento', 'sticker' => 'Figurinha'] as $mediaType => $label) {
            if (!isset($content[$mediaType]) || !is_array($content[$mediaType])) { continue; }
            $media = $content[$mediaType];
            $attachments[] = ['type' => $mediaType, 'label' => $media['filename'] ?? $label, 'url' => $this->url($media['link'] ?? $media['url'] ?? null), 'available' => isset($media['link']) || isset($media['url'])];
            if ('' === $text) { $text = (string) ($media['caption'] ?? $label); }
        }
        foreach ($content['attachments'] ?? [] as $attachment) {
            $attachmentType = $attachment['type'] ?? 'document';
            $label = match ($attachmentType) { 'image' => 'Imagem', 'video' => 'Vídeo', 'audio' => 'Áudio', 'sticker' => 'Figurinha', default => 'Anexo' };
            $attachments[] = ['type' => $attachmentType, 'label' => $label, 'url' => $this->url($attachment['payload']['url'] ?? null)];
            if ('' === $text) { $text = $label; }
        }
        return ['body' => $text ?: 'Mensagem de tipo '.$type, 'content_label' => $caption, 'attachments' => $attachments, 'failure' => $this->failure($message->getError())];
    }

    private function url(mixed $url): ?string
    {
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) { return null; }
        $parts = parse_url($url);
        return 'https' === ($parts['scheme'] ?? '') && !isset($parts['user']) && !isset($parts['pass']) ? $url : null;
    }

    private function failure(?string $error): ?string
    {
        if (!$error) { return null; }
        if (preg_match('/24 hours|customer.*window/i', $error)) { return 'O prazo de resposta livre do WhatsApp terminou. É necessário usar um modelo aprovado.'; }
        if (preg_match('/token|OAuth|session.*expired/i', $error)) { return 'A Meta recusou a credencial do canal. Revise a conexão em Meta antes de tentar novamente.'; }
        if (preg_match('/permission|access.*denied/i', $error)) { return 'A conta não possui a permissão de envio necessária na Meta.'; }
        if (preg_match('/recipient.*limit|anti.spam|rate.*limit/i', $error)) { return 'O limite de envio foi atingido. Aguarde antes de tentar novamente.'; }
        return 'O canal recusou o envio. Consulte o registro da mensagem em Meta para o diagnóstico.';
    }
}
