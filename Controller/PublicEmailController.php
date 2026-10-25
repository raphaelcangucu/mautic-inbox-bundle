<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Email\EmailMessages;
use MauticPlugin\MauticInboxBundle\Application\Email\InboxSigner;
use MauticPlugin\MauticInboxBundle\Application\Forms\FormActivity;
use MauticPlugin\MauticInboxBundle\Application\Forms\FormLinks;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * As tres paginas publicas do canal: o link do formulario, o original no app e o descadastro.
 * Nenhuma tem sessao; o token assinado e a autorizacao inteira, e um token ruim ganha sempre a
 * mesma pagina neutra, sem dizer o que estava errado.
 */
final class PublicEmailController extends CommonController
{
    /** As linguas com traducao no plugin, pela forma curta que o navegador manda. */
    private const LOCALES = ['pt' => 'pt_BR', 'en' => 'en_US'];

    public function form(string $token, Request $request, FormLinks $links, EntityManagerInterface $entityManager, ContactTracker $tracker, FormActivity $activity): Response
    {
        $link = $links->resolve($token);
        $form = null !== $link ? $entityManager->find(Form::class, $link['form']) : null;
        $contact = null !== $link ? $entityManager->find(Lead::class, $link['contact']) : null;
        $conversation = null !== $link ? $entityManager->find(MetaConversation::class, $link['conversation']) : null;
        if (!$form instanceof Form || !$form->isPublished() || !$contact instanceof Lead || !$conversation instanceof MetaConversation || $conversation->getContact()?->getId() !== $contact->getId()) {
            return $this->neutral('mautic.inbox.public.link_expired_title', 'mautic.inbox.public.link_expired', Response::HTTP_GONE, $this->locale($request));
        }
        // Quem abre o link passa como o contato: campos com auto-preenchimento chegam
        // preenchidos e o envio fica no contato certo. E o mesmo risco dos links rastreados do
        // Mautic, limitado pelos 30 dias do token.
        $tracker->setTrackedContact($contact);
        $activity->opened($conversation, $form, $contact);

        return new RedirectResponse($this->generateUrl('mautic_form_preview', ['id' => $form->getId()], UrlGeneratorInterface::ABSOLUTE_URL), Response::HTTP_FOUND, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function original(string $token, Request $request, EmailMessages $messages, EntityManagerInterface $entityManager, ConversationAccess $access): Response
    {
        $link = $messages->resolveOriginalLink($token);
        $user = null !== $link ? $entityManager->find(User::class, $link['user']) : null;
        $found = null !== $link ? $messages->find($link['message']) : null;
        if (!$user instanceof User || !$user->isPublished() || null === $found || !$access->canView($found['state'], $user)) {
            return $this->neutral('mautic.inbox.public.link_expired_title', 'mautic.inbox.public.link_expired', Response::HTTP_GONE, $this->locale($request));
        }
        $locale = $this->locale($request, $user);
        $html = $messages->original($found['message'], true);
        if (null === $html) {
            return $this->neutral('mautic.inbox.ui.email_original_title', 'mautic.inbox.ui.email_original_unavailable', Response::HTTP_NOT_FOUND, $locale);
        }
        $images = $request->query->getBoolean('images');
        $title = $this->text('mautic.inbox.ui.email_original_title', $locale);
        $bar = $images ? '' : '<p class="bar">'.$this->text('mautic.inbox.ui.email_images_blocked', $locale).' <a href="?images=1">'.$this->text('mautic.inbox.public.original_images', $locale).'</a></p>';

        return new Response(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>body{margin:0;padding:16px;font:15px/1.5 system-ui,sans-serif;color:#222c40;background:#fff;word-wrap:break-word}img{max-width:100%;height:auto}.bar{margin:0 0 16px;padding:10px 12px;border-radius:10px;background:#f1f4f9;color:#4a5670;font-size:13px}</style></head><body>'.$bar.$html.'</body></html>',
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                // Sandbox sem allow-scripts nem allow-same-origin: o HTML ja saneado roda sem
                // origem. Imagem remota so depois do toque, para o pixel do remetente nao saber
                // quando e de onde o atendente leu.
                'Content-Security-Policy' => "sandbox allow-popups allow-popups-to-escape-sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:".($images ? ' https:' : '')."; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
            ],
        );
    }

    /**
     * List-Unsubscribe da acao de campanha (RFC 8058). O POST de um clique, que o provedor do
     * lead manda com "List-Unsubscribe=One-Click", grava o DNC e responde 200. O GET nunca
     * grava nada: antivirus e pre-visualizador de link abrem URL sozinhos, e um descadastro
     * por GET tiraria da lista quem nunca pediu.
     */
    public function unsubscribe(string $token, Request $request, InboxSigner $signer, EntityManagerInterface $entityManager, DoNotContact $doNotContact): Response
    {
        $values = $signer->verify(InboxSigner::UNSUBSCRIBE, $token, 2);
        $contact = null !== $values ? $entityManager->find(Lead::class, $values[0]) : null;
        $locale = $this->locale($request);
        if (!$contact instanceof Lead) {
            return $this->neutral('mautic.inbox.public.link_expired_title', 'mautic.inbox.public.link_expired', Response::HTTP_GONE, $locale);
        }
        if (!$request->isMethod('POST')) {
            $title = $this->text('mautic.inbox.public.unsubscribe_title', $locale);

            return $this->page($title, '<h1>'.$title.'</h1><p>'.$this->text('mautic.inbox.public.unsubscribe_confirm', $locale).'</p><form method="post"><input type="hidden" name="List-Unsubscribe" value="One-Click"><button type="submit">'.$this->text('mautic.inbox.public.unsubscribe_button', $locale).'</button></form>', Response::HTTP_OK, true);
        }
        $doNotContact->addDncForContact($contact, 'email', DNC::UNSUBSCRIBED, 'Inbox: List-Unsubscribe');
        $asset = $entityManager->find(MetaAsset::class, $values[1]);
        $conversation = $asset instanceof MetaAsset ? $entityManager->getRepository(MetaConversation::class)->findOneBy(['asset' => $asset, 'channel' => 'email', 'contact' => $contact]) : null;
        if ($conversation instanceof MetaConversation) {
            $entityManager->persist((new EventLog())->setConversation($conversation)->setEventType('email_unsubscribed')->setDetails(['source' => 'list_unsubscribe']));
            $entityManager->flush();
        }
        $title = $this->text('mautic.inbox.public.unsubscribe_title', $locale);

        return $this->page($title, '<h1>'.$title.'</h1><p>'.$this->text('mautic.inbox.public.unsubscribe_done', $locale).'</p>', Response::HTTP_OK);
    }

    /**
     * Pagina publica nao tem sessao, entao fala a lingua do navegador de quem abre: o lead no
     * dele, o atendente no do celular. Sem lingua traduzida ali, vale o perfil do atendente; sem
     * nenhum dos dois, o idioma padrao do Mautic (null).
     */
    private function locale(Request $request, ?User $user = null): ?string
    {
        foreach ([...$request->getLanguages(), (string) $user?->getLocale()] as $language) {
            $locale = self::LOCALES[strtolower(substr($language, 0, 2))] ?? null;
            if (null !== $locale) { return $locale; }
        }

        return null;
    }

    private function text(string $key, ?string $locale): string
    {
        return htmlspecialchars($this->translator->trans($key, [], null, $locale), ENT_QUOTES, 'UTF-8');
    }

    private function neutral(string $titleKey, string $textKey, int $status, ?string $locale): Response
    {
        $title = $this->text($titleKey, $locale);

        return $this->page($title, '<h1>'.$title.'</h1><p>'.$this->text($textKey, $locale).'</p>', $status);
    }

    private function page(string $title, string $body, int $status, bool $form = false): Response
    {
        return new Response(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>'.$title.'</title><style>body{font:16px/1.5 system-ui,sans-serif;background:#f5f7fb;color:#222c40;margin:0;padding:24px}main{max-width:420px;margin:10vh auto;background:#fff;border:1px solid #e2e7f0;border-radius:20px;padding:28px}h1{font-size:20px;margin:0 0 12px}button{background:#244184;color:#fff;font:600 16px system-ui;border:0;border-radius:12px;padding:14px;width:100%}</style></head><body><main>'.$body.'</main></body></html>',
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action ".($form ? "'self'" : "'none'")."; base-uri 'none'; frame-ancestors 'none'",
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
            ],
        );
    }
}
