<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSubscriptions;
use MauticPlugin\MauticInboxBundle\Application\Push\VapidKeyStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Inscricao de aparelhos para notificacao.
 *
 * Tudo aqui vive sob a sessao do Mautic, sob permissao de inbox e, nas rotas de escrita, sob
 * CSRF — o mesmo trio que o InboxController ja aplica.
 */
class PushController extends CommonController
{
    public function config(CorePermissions $permissions, UserHelper $userHelper, VapidKeyStore $keys, PushSubscriptions $subscriptions): JsonResponse
    {
        $this->grant($permissions);

        $configured = $keys->isConfigured();

        return new JsonResponse([
            // Somente a publica. A privada nunca sai daqui, sob nenhum ramo: e o unico
            // segredo cuja perda invalida todas as inscricoes existentes de uma vez.
            'publicKey'  => $configured ? $keys->load()->publicKey() : null,
            'configured' => $configured,
            'subscribed' => [] !== $subscriptions->activeFor($userHelper->getUser()),
        ]);
    }

    public function subscribe(Request $request, CorePermissions $permissions, UserHelper $userHelper, PushSubscriptions $subscriptions): JsonResponse
    {
        $this->grantMutation($request, $permissions);

        $payload  = $this->decode($request);
        $endpoint = (string) ($payload['endpoint'] ?? '');
        $p256dh   = (string) ($payload['keys']['p256dh'] ?? '');
        $auth     = (string) ($payload['keys']['auth'] ?? '');

        if ('' === $endpoint || '' === $p256dh || '' === $auth) {
            return new JsonResponse(['error' => 'Inscricao incompleta: endpoint e as duas chaves sao obrigatorios.'], 400);
        }

        $device = $subscriptions->subscribe(
            $userHelper->getUser(),
            $endpoint,
            $p256dh,
            $auth,
            substr((string) $request->headers->get('User-Agent', ''), 0, 255) ?: null,
        );

        return new JsonResponse(['id' => $device->getId(), 'active' => $device->isActive()], 201);
    }

    public function unsubscribe(Request $request, CorePermissions $permissions, UserHelper $userHelper, PushSubscriptions $subscriptions): JsonResponse
    {
        $this->grantMutation($request, $permissions);

        $endpoint = (string) ($this->decode($request)['endpoint'] ?? '');
        if ('' === $endpoint) {
            return new JsonResponse(['error' => 'Informe o endpoint a cancelar.'], 400);
        }

        // O servico so apaga quando o registro pertence a quem pediu, entao um usuario nunca
        // desinscreve o aparelho de outro.
        return new JsonResponse(['removed' => $subscriptions->unsubscribe($userHelper->getUser(), $endpoint)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function grant(CorePermissions $permissions): void
    {
        if (!$permissions->isGranted('inbox:conversations:view')) {
            throw $this->createAccessDeniedException();
        }
    }

    private function grantMutation(Request $request, CorePermissions $permissions): void
    {
        $this->grant($permissions);
        if (!$this->isCsrfTokenValid('mautic_inbox', $request->headers->get('X-CSRF-Token', ''))) {
            throw $this->createAccessDeniedException();
        }
    }
}
