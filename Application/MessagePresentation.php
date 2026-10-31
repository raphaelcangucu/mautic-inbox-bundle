<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MessagePresentation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private ?AttachmentRegistry $attachmentMedia = null,
        private ?Email\EmailContentRegistry $emailContent = null,
        private ?Email\EmailHtmlSanitizer $emailHtml = null,
    ) {}

    /**
     * @param bool $withHtml false para quem so le o texto (previa da lista, contexto da IA): o
     *                       saneamento do HTML de e-mail custa um parser por mensagem
     */
    public function present(MetaMessage $message, bool $withHtml = true): array
    {
        $payload = $message->getPayload();
        if ('email' === $message->getChannel() && is_array($payload['email'] ?? null)) {
            return $this->presentEmail($message, $payload['email'], $withHtml);
        }
        $content = is_array($payload['message'] ?? null) ? $payload['message'] : $payload;
        $type = $message->getMessageType();
        $text = $content['text']['body'] ?? $content['text'] ?? $payload['text'] ?? '';
        $text = is_string($text) ? $text : '';
        if ('' === $text && is_string($payload['message'] ?? null)) { $text = $payload['message']; }
        $caption = null;
        $attachments = [];
        if ('template' === $type) {
            $template = $payload['template'] ?? $content['template'] ?? [];
            $name = (string) ($template['name'] ?? $this->translator->trans('mautic.inbox.ui.unnamed_54e5aa'));
            $language = (string) ($template['language']['code'] ?? '');
            $caption = $this->translator->trans('mautic.inbox.ui.whatsapp_template_6d8d5d').$name.' · '.$language;
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
            $text = $parts ? implode("\n\n", $parts) : $this->translator->trans('mautic.inbox.ui.template_a44434').$name.$this->translator->trans('mautic.inbox.ui.this_template_s_text_is_not_available_in_the_local_catalog_08ff0c');
            if ($parts) { $caption .= $this->translator->trans('mautic.inbox.ui.catalog_version_db4744'); }
        } elseif ('unsupported' === $type && is_array($payload['whatsqr'] ?? null)) {
            // The QR decoder could not sync this content. This is an Inbox
            // placeholder, never text actually sent by the customer/operator.
            $viewOnce = 'view_once' === ($payload['whatsqr']['unsupported_reason'] ?? $content['unsupported_reason'] ?? null);
            $text = $this->translator->trans($viewOnce ? 'mautic.inbox.qr.content.view_once' : 'mautic.inbox.qr.content.unavailable');
            $caption = $this->translator->trans('mautic.inbox.qr.content.placeholder');
        } elseif ('unsupported' === $type) {
            $code = $content['errors'][0]['code'] ?? null;
            $text = $this->translator->trans('mautic.inbox.ui.whatsapp_did_not_provide_this_message_s_content_1fd44c').($code ? $this->translator->trans('mautic.inbox.ui.code_cea766').$code.')' : '').$this->translator->trans('mautic.inbox.ui.open_the_conversation_in_the_original_app_or_ask_the_person_to_re_2fc435');
            $caption = $this->translator->trans('mautic.inbox.ui.message_not_provided_by_whatsapp_7dbeab');
        } elseif (is_array($payload['whatsqr'] ?? null) && in_array($type, ['contact', 'location', 'poll', 'interactive'], true)) {
            $caption = $this->translator->trans('mautic.inbox.qr.content.'.$type);
        } elseif (isset($content['interactive'])) {
            $reply = $content['interactive']['button_reply'] ?? $content['interactive']['list_reply'] ?? [];
            $text = (string) ($reply['title'] ?? $reply['description'] ?? $this->translator->trans('mautic.inbox.ui.interactive_reply_7e5fff'));
        } elseif (isset($content['button'])) {
            $text = (string) ($content['button']['text'] ?? $this->translator->trans('mautic.inbox.ui.button_reply_79dc10'));
        }
        foreach (['image' => $this->translator->trans('mautic.inbox.ui.image_17bac1'), 'audio' => $this->translator->trans('mautic.inbox.ui.audio_54a83d'), 'video' => $this->translator->trans('mautic.inbox.ui.video_03e7e1'), 'document' => $this->translator->trans('mautic.inbox.ui.document_8ae11c'), 'sticker' => $this->translator->trans('mautic.inbox.ui.sticker_5e4458')] as $mediaType => $label) {
            if (!isset($content[$mediaType]) || !is_array($content[$mediaType])) { continue; }
            $media = $content[$mediaType];
            $mediaId = trim((string) ($media['id'] ?? ''));
            $proxiedUrl = 'whatsapp' === $message->getChannel()
                && 'inbound' === $message->getDirection()
                && null !== $message->getId()
                && 1 === preg_match('/^[0-9]{5,40}$/', $mediaId)
                ? $this->urlGenerator->generate('mautic_inbox_media', ['messageId' => $message->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
                : null;
            $url = $this->attachmentMedia?->url($message, $mediaType) ?? $proxiedUrl ?? $this->url($media['link'] ?? $media['url'] ?? null);
            $attachments[] = ['type' => $mediaType, 'label' => trim((string) ($media['filename'] ?? '')) ?: $label, 'url' => $url, 'available' => null !== $url];
            if ('' === $text) { $text = (string) ($media['caption'] ?? $label); }
        }
        foreach ($content['attachments'] ?? [] as $attachment) {
            $attachmentType = $attachment['type'] ?? 'document';
            $label = match ($attachmentType) { 'image' => $this->translator->trans('mautic.inbox.ui.image_17bac1'), 'video' => $this->translator->trans('mautic.inbox.ui.video_03e7e1'), 'audio' => $this->translator->trans('mautic.inbox.ui.audio_54a83d'), 'sticker' => $this->translator->trans('mautic.inbox.ui.sticker_5e4458'), default => 'Anexo' };
            $attachments[] = ['type' => $attachmentType, 'label' => $label, 'url' => $this->url($attachment['payload']['url'] ?? null)];
            if ('' === $text) { $text = $label; }
        }
        return ['body' => $text ?: $this->translator->trans('mautic.inbox.ui.message_type_89f48a').$type, 'content_label' => $caption, 'attachments' => $attachments, 'failure' => $this->failure($message->getError())];
    }

    /**
     * A mensagem de e-mail na forma do contrato (plano, secao 1.6): `body` e o texto novo em
     * markdown, `subject` vem a parte, `email` carrega cabecalhos e marcas, e os anexos sao
     * varios. O HTML vai saneado em `email.html`, para o iframe do web; o app nao o recebe.
     *
     * @param array<string,mixed> $email
     * @return array<string,mixed>
     */
    public function presentEmail(MetaMessage $message, array $email, bool $withHtml = true): array
    {
        $kind = in_array($email['kind'] ?? null, ['message', 'auto_reply', 'bounce', 'unreadable'], true) ? $email['kind'] : 'message';
        $from = self::address($email['from'] ?? null);
        $text = is_string($email['text'] ?? null) ? trim($email['text']) : '';
        $html = is_string($email['html'] ?? null) ? $email['html'] : '';
        $stored = null !== $message->getId() && (bool) $this->emailContent?->supports($message);
        $attachments = [];
        $byCid = [];
        foreach (is_array($email['attachments'] ?? null) ? array_values($email['attachments']) : [] as $position => $attachment) {
            if (!is_array($attachment)) { continue; }
            $index = is_numeric($attachment['index'] ?? null) ? (int) $attachment['index'] : $position;
            $available = $stored && !empty($attachment['available']) && $index >= 0;
            $url = $available ? $this->urlGenerator->generate('mautic_inbox_email_attachment', ['messageId' => $message->getId(), 'index' => $index], UrlGeneratorInterface::ABSOLUTE_URL) : null;
            $type = in_array($attachment['type'] ?? null, ['image', 'audio', 'video', 'document'], true) ? $attachment['type'] : 'document';
            $name = is_string($attachment['name'] ?? null) ? trim(basename(str_replace('\\', '/', $attachment['name']))) : '';
            $attachments[] = [
                'type' => $type,
                'label' => '' !== $name ? mb_substr($name, 0, 200) : $this->translator->trans('mautic.inbox.ui.email_attachment'),
                'url' => $url,
                'available' => $available,
                'size' => max(0, (int) ($attachment['size'] ?? 0)),
                'mime' => is_string($attachment['mime'] ?? null) ? strtolower(trim($attachment['mime'])) : '',
            ];
            if (is_string($attachment['cid'] ?? null) && '' !== trim($attachment['cid'], " <>")) {
                $byCid[strtolower(trim($attachment['cid'], " <>"))] = $url;
            }
        }
        if ('' === $text) {
            $text = match ($kind) {
                'unreadable' => $this->translator->trans('mautic.inbox.ui.email_unreadable', ['%sender%' => $from['address'] ?: $message->getRecipient()]),
                default => '' !== $html ? $this->translator->trans('mautic.inbox.ui.email_html_only') : ([] !== $attachments ? $this->translator->trans('mautic.inbox.ui.email_attachment') : $this->translator->trans('mautic.inbox.ui.email_empty')),
            };
        }
        $clean = $withHtml && '' !== trim($html) && null !== $this->emailHtml
            ? $this->emailHtml->sanitize($html, static fn (string $cid): ?string => $byCid[strtolower($cid)] ?? null)
            : '';
        // O +tag do Reply-To carrega o token da conversa e nao aparece na interface. O canal ja
        // grava o endereco da conta sem ele; esta e a garantia de que um payload antigo ou
        // escrito por outro caminho tambem nao o mostre.
        $account = strtolower(trim((string) ($message->getAsset()->getSettings()['email_address'] ?? '')));
        $untag = static function (array $list) use ($account): array {
            if ('' === $account || !str_contains($account, '@')) { return $list; }
            [$local, $domain] = explode('@', $account, 2);
            foreach ($list as &$item) {
                if (preg_match('/^'.preg_quote($local, '/').'\+[^@]*@'.preg_quote($domain, '/').'$/D', $item['address'])) { $item['address'] = $account; }
            }
            unset($item);

            return $list;
        };

        return [
            'body' => $text,
            'subject' => is_string($email['subject'] ?? null) ? mb_substr(trim($email['subject']), 0, 500) : null,
            'content_label' => match ($kind) {
                'auto_reply' => $this->translator->trans('mautic.inbox.ui.email_auto_reply'),
                'bounce' => $this->translator->trans('mautic.inbox.ui.email_bounce'),
                default => null,
            },
            'email' => [
                'from' => $from,
                'to' => $untag(self::addresses($email['to'] ?? [])),
                'cc' => $untag(self::addresses($email['cc'] ?? [])),
                'quoted' => is_string($email['quoted'] ?? null) && '' !== trim($email['quoted']) ? $email['quoted'] : null,
                'has_html' => '' !== trim($html),
                'has_original' => $stored && !empty($email['has_original']),
                'auto_reply' => !empty($email['auto_reply']) || 'auto_reply' === $kind,
                'unverified_sender' => !empty($email['unverified_sender']),
                'kind' => $kind,
            ] + ($withHtml ? ['html' => $clean] : []),
            'attachments' => $attachments,
            'failure' => $this->failure($message->getError()),
        ];
    }

    /** @return array{name:string,address:string} */
    public static function address(mixed $value): array
    {
        if (is_string($value)) { $value = ['address' => $value]; }
        $address = is_array($value) && is_string($value['address'] ?? null) ? strtolower(trim($value['address'])) : '';
        $name = is_array($value) && is_string($value['name'] ?? null) ? trim(str_replace(["\r", "\n"], ' ', $value['name'])) : '';

        return ['name' => mb_substr($name, 0, 200), 'address' => mb_substr($address, 0, 254)];
    }

    /** @return list<array{name:string,address:string}> */
    public static function addresses(mixed $value): array
    {
        if (!is_array($value)) { return []; }
        $list = [];
        foreach (array_slice(array_values($value), 0, 50) as $item) {
            $address = self::address($item);
            if ('' !== $address['address']) { $list[] = $address; }
        }

        return $list;
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
        if (preg_match('/24 hours|customer.*window/i', $error)) { return $this->translator->trans('mautic.inbox.ui.the_whatsapp_free_form_reply_window_has_closed_an_approved_templa_8b2caf'); }
        if (preg_match('/token|OAuth|session.*expired/i', $error)) { return $this->translator->trans('mautic.inbox.ui.meta_rejected_the_channel_credential_review_the_connection_in_met_1c6ad0'); }
        if (preg_match('/permission|access.*denied/i', $error)) { return $this->translator->trans('mautic.inbox.ui.the_account_does_not_have_the_required_sending_permission_in_meta_e0359a'); }
        if (preg_match('/recipient.*limit|anti.spam|rate.*limit/i', $error)) { return 'O limite de envio foi atingido. Aguarde antes de tentar novamente.'; }
        return $this->translator->trans('mautic.inbox.ui.the_channel_rejected_the_send_check_the_message_log_in_meta_for_d_b99fb0');
    }
}
