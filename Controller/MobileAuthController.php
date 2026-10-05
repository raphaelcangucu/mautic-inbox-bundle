<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\User;
use Mautic\EmailBundle\Helper\MailHelper;
use Psr\Log\LoggerInterface;
use MauticPlugin\MauticInboxBundle\Application\Mobile\SessionStore;
use Symfony\Component\HttpFoundation\{JsonResponse,RedirectResponse,Request,Response};
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MobileAuthController extends CommonController
{
    public const REDIRECT = 'mautic-inbox-demo://oauth/callback';

    public function config(Request $request): JsonResponse
    {
        $origin = $request->getSchemeAndHttpHost().$request->getBaseUrl();
        return $this->jsonPrivate([
            'version' => 1, 'name' => 'Mautic Inbox', 'origin' => $origin,
            'api_base' => $origin.'/inbox/mobile/api', 'authorization_endpoint' => $request->getSchemeAndHttpHost().$this->generateUrl('mautic_inbox_mobile_authorize'),
            'magic_code_endpoint' => $origin.'/inbox/mobile/magic-code', 'token_endpoint' => $origin.'/inbox/mobile/token', 'redirect_uri' => self::REDIRECT,
            'capabilities' => ['channels' => ['whatsapp','instagram','facebook','webchat'], 'auth' => 'email_code_pkce', 'magic_code_login' => true, 'operator_session' => true, 'transfer' => true, 'snooze' => true, 'notes' => true, 'templates' => true, 'canned' => true, 'crm' => true, 'agents' => true, 'media_upload' => false, 'moderation' => true, 'push_remote' => false],
        ]);
    }

    public function authorize(Request $request, UserHelper $users, CorePermissions $permissions, CsrfTokenManagerInterface $csrf, SessionStore $store): Response
    {
        $user = $users->getUser(true);
        if (!$user || !$permissions->isGranted(['inbox:conversations:view','meta:messages:view'])) { throw $this->createAccessDeniedException(); }
        $challenge = $request->query->getString('code_challenge'); $state = $request->query->getString('state');
        $redirect = $request->query->getString('redirect_uri');
        if ($redirect !== self::REDIRECT || $request->query->getString('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) || !preg_match('/^[A-Za-z0-9._~-]{16,256}$/D', $state)) {
            return $this->jsonPrivate(['error' => 'invalid_authorization_request'], 400);
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('inbox_mobile_authorize', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            $code = $store->authorize((int) $user->getId(), hash('sha256', (string) $user->getPassword()), $challenge, $redirect);
            $response = new RedirectResponse($redirect.'?'.http_build_query(['code' => $code, 'state' => $state]), 303);
            $response->headers->set('Cache-Control', 'no-store'); $response->headers->set('Referrer-Policy', 'no-referrer');
            return $response;
        }
        $name = htmlspecialchars($user->getName() ?: (string) $user->getUsername(), ENT_QUOTES, 'UTF-8');
        $instance = htmlspecialchars($request->getHost(), ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars($csrf->getToken('inbox_mobile_authorize')->getValue(), ENT_QUOTES, 'UTF-8');
        return new Response('<!doctype html><html lang="pt-BR"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Conectar Mautic Inbox</title><style>body{font:16px system-ui;background:#f5f7fb;color:#222c40;margin:0;padding:24px}main{max-width:420px;margin:10vh auto;background:white;border:1px solid #e2e7f0;border-radius:20px;padding:28px}p{line-height:1.6}button{background:#244184;color:white;font:600 16px system-ui;border:0;border-radius:12px;padding:16px;width:100%}small{color:#66738b}</style><main><small>'.$instance.'</small><h1>Conectar seu aplicativo</h1><p>Usuário: <strong>'.$name.'</strong></p><p>O aplicativo poderá consultar e atender conversas usando as permissões desta conta. O acesso é exclusivo deste aparelho e pode ser encerrado ao sair.</p><form method="post"><input type="hidden" name="_token" value="'.$token.'"><button type="submit">Conectar Mautic Inbox</button></form><p><small>Sua senha permanece no Mautic. A sessão do app fica separada do login no navegador.</small></p></main></html>', 200, ['Cache-Control' => 'no-store','Referrer-Policy' => 'no-referrer','Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'",'X-Content-Type-Options' => 'nosniff']);
    }

    public function token(Request $request, SessionStore $store, EntityManagerInterface $em): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 4096) { return $this->jsonPrivate(['error' => 'invalid_request'], 400); }
            $p = str_contains($request->headers->get('Content-Type',''), 'application/json') ? json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR) : $request->request->all();
            if (!is_array($p) || array_is_list($p)) { throw new \DomainException('invalid_request'); }
            foreach (['grant_type','request_id','code','code_verifier','redirect_uri','refresh_token','device_code'] as $field) { if (isset($p[$field]) && !is_string($p[$field])) { throw new \DomainException('invalid_request'); } }
            $tokens = match ($p['grant_type'] ?? '') {
                'authorization_code' => $store->exchange((string) ($p['code'] ?? ''), (string) ($p['code_verifier'] ?? ''), (string) ($p['redirect_uri'] ?? '')),
                'email_code' => $store->exchangeMagic((string) ($p['request_id'] ?? ''), (string) ($p['code'] ?? ''), (string) ($p['code_verifier'] ?? '')),
                'refresh_token' => $store->refresh((string) ($p['refresh_token'] ?? '')),
                'urn:ietf:params:oauth:grant-type:device_code' => $store->exchangeDevice((string) ($p['device_code'] ?? ''), (string) ($p['code_verifier'] ?? '')),
                default => throw new \DomainException('unsupported_grant_type'),
            };
            if (isset($tokens['error'])) { return $this->jsonPrivate($tokens, 400); }
            $user = $em->find(User::class, $tokens['user_id']);
            if (!$user instanceof User || !$user->isPublished() || (isset($tokens['email_hash']) && !hash_equals($tokens['email_hash'], hash('sha256', strtolower((string) $user->getEmail())))) || !hash_equals($tokens['fingerprint'], hash('sha256', (string) $user->getPassword()))) { $store->revoke($tokens['access_token']); throw new \DomainException('invalid_grant'); }
            unset($tokens['user_id'], $tokens['fingerprint'], $tokens['email_hash']);
            $tokens['user'] = ['id' => (int) $user->getId(), 'name' => $user->getName() ?: $user->getUsername(), 'email' => $user->getEmail()];
            return $this->jsonPrivate($tokens);
        } catch (\JsonException|\DomainException $e) { return $this->jsonPrivate(['error' => $e instanceof \JsonException ? 'invalid_request' : $e->getMessage()], 400); }
    }

    public function magicCode(Request $request, SessionStore $store, EntityManagerInterface $em, MailHelper $mailHelper, LoggerInterface $logger): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 2048) { throw new \DomainException('invalid_request'); }
            $p = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($p) || !is_string($p['email'] ?? null)) { throw new \DomainException('invalid_request'); }
            $email = strtolower(trim($p['email'])); $challenge = $p['code_challenge'] ?? '';
            if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($challenge) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) { throw new \DomainException('invalid_request'); }
            // Read-only lookup of operators; this never creates a user or a contact.
            $matches = $em->getRepository(User::class)->createQueryBuilder('u')->where('LOWER(u.email) = :email')->andWhere('u.isPublished = true')->setParameter('email', $email)->setMaxResults(2)->getQuery()->getResult();
            $user = count($matches) === 1 && $matches[0] instanceof User ? $matches[0] : null;
            $grant = $store->startMagic($email, $request->getClientIp() ?? 'unknown', $challenge, $user ? (int) $user->getId() : 0, $user ? hash('sha256', (string) $user->getPassword()) : '');
            if ($user) {
                try {
                    $instance = htmlspecialchars($request->getHost(), ENT_QUOTES, 'UTF-8');
                    $mailer = $mailHelper->getMailer();
                    $mailer->setTo([(string) $user->getEmail() => $user->getName() ?: $user->getUsername()]);
                    $mailer->setSubject('Seu código de acesso ao Mautic Inbox');
                    $mailer->setBody('<h2>Entrar no Mautic Inbox</h2><p>Instância: '.$instance.'</p><p>Seu código: <strong style="font-size:28px;letter-spacing:6px">'.$grant['code'].'</strong></p><p>Válido por 5 minutos, somente no aparelho que o solicitou. Ao confirmar, o app usará as permissões do seu usuário e manterá uma sessão renovável por até 30 dias. Você pode encerrá-la ao sair do app.</p><p>Não compartilhe este código. Se não solicitou o acesso, ignore este e-mail.</p>', 'text/html', 'UTF-8', true);
                    $mailer->setPlainText('Mautic Inbox — '.$request->getHost()."\nCódigo de acesso: ".$grant['code']."\nValidade: 5 minutos, somente neste aparelho. Sessão renovável por até 30 dias. Não compartilhe o código. Se não solicitou, ignore este e-mail.");
                    if (!$mailer->send()) { throw new \RuntimeException('mail_not_accepted'); }
                } catch (\Throwable) {
                    $store->cancelMagic($grant['request_id']);
                    $logger->error('Inbox mobile magic code: transactional mail unavailable.');
                }
            }
            // Identical response for unknown, ambiguous, unpublished users and mail failures.
            unset($grant['code']);
            return $this->jsonPrivate($grant + ['message' => 'Se este e-mail pertence a um usuário ativo desta instância, você receberá um código de acesso.'], 202);
        } catch (\JsonException|\DomainException $e) {
            return $this->jsonPrivate(['error' => $e instanceof \JsonException ? 'invalid_request' : $e->getMessage()], $e->getMessage() === 'rate_limited' ? 429 : 400);
        }
    }

    public function device(Request $request, SessionStore $store): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 2048) { return $this->jsonPrivate(['error' => 'invalid_request'], 400); }
            $p = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            $challenge = $p['code_challenge'] ?? '';
            if (!is_string($challenge) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) { return $this->jsonPrivate(['error' => 'invalid_request'], 400); }
            $device = $store->startDevice($challenge, $request->getClientIp() ?? 'unknown');
            $device['verification_uri_complete'] = $request->getSchemeAndHttpHost().$this->generateUrl('mautic_inbox_mobile_device_authorize', ['user_code' => $device['user_code']]);
            return $this->jsonPrivate($device);
        } catch (\JsonException|\DomainException $e) { return $this->jsonPrivate(['error' => $e instanceof \JsonException ? 'invalid_request' : $e->getMessage()], $e->getMessage() === 'rate_limited' ? 429 : 400); }
    }

    public function authorizeDevice(Request $request, UserHelper $users, CorePermissions $permissions, CsrfTokenManagerInterface $csrf, SessionStore $store): Response
    {
        $user = $users->getUser(true);
        if (!$user || !$permissions->isGranted(['inbox:conversations:view','meta:messages:view'])) { throw $this->createAccessDeniedException(); }
        $code = $request->query->getString('user_code');
        if (!preg_match('/^[A-F0-9]{4}-[A-F0-9]{4}$/D', $code)) { return $this->jsonPrivate(['error' => 'invalid_device_code'], 400); }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('inbox_mobile_authorize', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            try { $store->approveDevice($code, (int) $user->getId(), hash('sha256', (string) $user->getPassword())); }
            catch (\DomainException) { return $this->jsonPrivate(['error' => 'invalid_device_code'], 400); }
            return new Response('<!doctype html><html lang="pt-BR"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mautic conectado</title><body style="font:18px system-ui;padding:32px"><h1>Aplicativo conectado</h1><p>Volte ao Mautic Inbox no seu aparelho.</p></body></html>', 200, ['Cache-Control' => 'no-store','Referrer-Policy' => 'no-referrer']);
        }
        $name = htmlspecialchars($user->getName() ?: (string) $user->getUsername(), ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars($csrf->getToken('inbox_mobile_authorize')->getValue(), ENT_QUOTES, 'UTF-8');
        return new Response('<!doctype html><html lang="pt-BR"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Conectar Mautic Inbox</title><body style="font:16px system-ui;background:#f5f7fb;color:#222c40;padding:24px"><main style="max-width:420px;margin:8vh auto;padding:28px;background:white;border-radius:20px"><h1>Conectar seu aplicativo</h1><p>Confira este código no aparelho:</p><h2>'.$code.'</h2><p>Usuário: <strong>'.$name.'</strong></p><p>Esta sessão usará suas permissões de atendimento e poderá ser encerrada ao sair do app.</p><form method="post"><input type="hidden" name="_token" value="'.$token.'"><button style="background:#244184;color:white;padding:16px;border:0;border-radius:12px;font:600 16px system-ui;width:100%">Conectar Mautic Inbox</button></form></main></body></html>', 200, ['Cache-Control' => 'no-store','Referrer-Policy' => 'no-referrer','Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'"]);
    }

    private function jsonPrivate(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store','Pragma' => 'no-cache','X-Content-Type-Options' => 'nosniff']);
    }
}
