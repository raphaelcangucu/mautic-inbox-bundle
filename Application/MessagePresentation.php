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
    ) {}

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
        } elseif ('unsupported' === $type) {
            $code = $content['errors'][0]['code'] ?? null;
            $text = $this->translator->trans('mautic.inbox.ui.whatsapp_did_not_provide_this_message_s_content_1fd44c').($code ? $this->translator->trans('mautic.inbox.ui.code_cea766').$code.')' : '').$this->translator->trans('mautic.inbox.ui.open_the_conversation_in_the_original_app_or_ask_the_person_to_re_2fc435');
            $caption = $this->translator->trans('mautic.inbox.ui.message_not_provided_by_whatsapp_7dbeab');
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
            $url = $proxiedUrl ?? $this->url($media['link'] ?? $media['url'] ?? null);
            $attachments[] = ['type' => $mediaType, 'label' => $media['filename'] ?? $label, 'url' => $url, 'available' => null !== $url];
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
