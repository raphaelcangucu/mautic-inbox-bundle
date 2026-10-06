<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;

final class WhatsAppTemplates
{
    public function __construct(private EntityManagerInterface $em, private MetaGraphClientInterface $graph, private IdentityManager $identities, private PhoneNormalizer $phones) {}

    public function catalog(ConversationState $state): array
    {
        $phone = $state->getConversation()->getAsset();
        if (null !== ($qr = $this->qrRefusal($state))) { throw new InboxException($qr); }
        if ('whatsapp' !== $state->getConversation()->getChannel() || $phone->getType() !== AssetType::WhatsAppPhoneNumber) { throw new InboxException('mautic.inbox.template.whatsapp_only'); }
        $account = null;
        foreach ($this->em->getRepository(MetaAsset::class)->findBy(['connection' => $phone->getConnection(), 'type' => AssetType::WhatsAppBusinessAccount->value, 'isPublished' => true]) as $waba) {
            $after = null; $pages = 0;
            do {
                ++$pages;
                try { $page = $this->graph->get($phone->getConnection(), $waba->getExternalId().'/phone_numbers', ['fields' => 'id', 'limit' => 100] + ($after ? ['after' => $after] : [])); }
                catch (\Throwable) { throw new InboxException('mautic.inbox.template.account_unverified'); }
                foreach ($page['data'] ?? [] as $item) { if ((string) ($item['id'] ?? '') === $phone->getExternalId()) { $account = $waba; break; } }
                $next = !empty($page['paging']['next']) ? ($page['paging']['cursors']['after'] ?? null) : null;
                if ($next === $after) { break; }
                $after = $next;
            } while (!$account && $after && $pages < 10);
            if ($account) { break; }
        }
        if (!$account) { throw new InboxException('mautic.inbox.template.account_unverified'); }
        return array_map(fn (WhatsAppTemplate $t): array => $this->describe($t), $this->em->getRepository(WhatsAppTemplate::class)->findBy(['businessAccount' => $account, 'status' => 'APPROVED'], ['name' => 'ASC', 'language' => 'ASC']));
    }

    public function prepare(ConversationState $state, int $id, array $values): array
    {
        $selected = null;
        foreach ($this->catalog($state) as $item) { if ($item['id'] === $id) { $selected = $item; break; } }
        if (!$selected || !$selected['supported']) { throw new InboxException('mautic.inbox.template.unavailable'); }
        $components = [];
        foreach ($selected['fields'] as $field) {
            $value = $values[$field['key']] ?? null;
            if (!is_string($value) || '' === trim($value) || mb_strlen($value) > 1024) { throw new InboxException('mautic.inbox.template.variables_required'); }
            $parameter = ['type' => 'text', 'text' => trim($value)];
            if (!ctype_digit($field['token'])) { $parameter['parameter_name'] = $field['token']; }
            $components[$field['component']]['type'] = strtolower($field['component']);
            $components[$field['component']]['parameters'][] = $parameter;

        }
        if (count($values) !== count($selected['fields'])) { throw new InboxException('mautic.inbox.template.variables_required'); }
        $conversation = $state->getConversation(); $asset = $conversation->getAsset();
        try { $recipient = $this->phones->normalize($conversation->getRecipient(), (string) ($asset->getSettings()['default_region'] ?? 'BR')); } catch (\InvalidArgumentException) { throw new InboxException('mautic.inbox.template.invalid_recipient'); }
        try { $this->identities->assertCanSend($asset, $recipient, $conversation->getContact()); }
        catch (\DomainException) { throw new InboxException('mautic.inbox.template.consent_required'); }
        $preview = implode("\n\n", array_map(static fn (array $part): string => preg_replace_callback('/\{\{([A-Za-z0-9_]+)\}\}/', static fn (array $m): string => trim($values[$part['type'].':'.$m[1]] ?? $m[0]), $part['text']), $selected['parts']));
        return ['body' => $selected['name'].' · '.$selected['language']."\n".$preview, 'payload' => ['recipient' => $recipient, 'name' => $selected['name'], 'language' => $selected['language'], 'components' => array_values($components), '_template_id' => $id]];
    }

    /**
     * A recusa propria do canal por QR.
     *
     * Modelo e produto do WABA: ele so existe porque ha uma conta de negocio na Meta que
     * aprova o texto. Uma sessao por QR vive fora do Graph e nao tem conta nenhuma, entao
     * aqui nao ha modelo indisponivel -- nao ha modelo. Dizer "modelos so para conversas
     * WhatsApp" mandaria o atendente conferir o canal errado, porque a conversa E WhatsApp;
     * e "este destinatario nao e um numero valido", que e o que a checagem de consentimento
     * devolvia, manda conferir o contato, que tambem nao e o problema.
     */
    private function qrRefusal(ConversationState $state): ?string
    {
        return AssetType::WhatsAppQrSession === $state->getConversation()->getAsset()->getType() ? 'mautic.inbox.template.qr_session' : null;
    }

    public function blockedReason(ConversationState $state): ?string
    {
        if (null !== ($qr = $this->qrRefusal($state))) { return $qr; }
        try {
            $c = $state->getConversation(); $a = $c->getAsset();
            $recipient = $this->phones->normalize($c->getRecipient(), (string) ($a->getSettings()['default_region'] ?? 'BR'));
            $this->identities->assertCanSend($a, $recipient, $c->getContact());
            return null;
        } catch (\InvalidArgumentException) { return 'mautic.inbox.template.invalid_recipient'; } catch (\DomainException) { return 'mautic.inbox.template.consent_required'; }
    }

    public function describe(WhatsAppTemplate $template): array
    {
        $fields = []; $texts = []; $parts = []; $supported = true;
        foreach ($template->getComponents() as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            if (in_array($type, ['HEADER', 'BODY', 'FOOTER'], true)) {
                if ('HEADER' === $type && 'TEXT' !== ($component['format'] ?? 'TEXT')) { $supported = false; }
                $text = (string) ($component['text'] ?? ''); $texts[] = $text; $parts[] = ['type' => $type, 'text' => $text];
                preg_match_all('/\{\{([A-Za-z0-9_]+)\}\}/', $text, $matches);
                $tokens = array_values(array_unique($matches[1]));
                if ($tokens && 'FOOTER' === $type) { $supported = false; }
                if ($tokens && ctype_digit((string) $tokens[0])) { sort($tokens, SORT_NUMERIC); if (array_map('intval', $tokens) !== range(1, count($tokens))) { $supported = false; } }
                foreach ($tokens as $token) { $fields[] = ['key' => $type.':'.$token, 'component' => $type, 'token' => (string) $token]; }
            } elseif ('BUTTONS' === $type) {
                foreach ($component['buttons'] ?? [] as $button) {
                    if (!in_array($button['type'] ?? '', ['URL','PHONE_NUMBER','QUICK_REPLY'], true) || str_contains(json_encode($button), '{{')) { $supported = false; }
                    $texts[] = '▸ '.($button['text'] ?? ''); $parts[] = ['type' => 'BUTTONS', 'text' => '▸ '.($button['text'] ?? '')];
                }
            } else { $supported = false; }
        }
        return ['id' => $template->getId(), 'name' => $template->getName(), 'language' => $template->getLanguage(), 'category' => $template->getCategory(), 'parts' => $parts, 'preview' => implode("\n\n", $texts), 'fields' => $fields, 'supported' => $supported];
    }
}
