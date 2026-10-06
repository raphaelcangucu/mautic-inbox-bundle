<?php
// Real MCP adapter with in-memory collaborators; no kernel or database.
namespace Doctrine\ORM { interface EntityManagerInterface {} }
namespace Mautic\CoreBundle\Helper { class UserHelper { public function getUser($force): object { return new class { public function getId(): int { return 1; } }; } } }
namespace Mautic\CoreBundle\Security\Permissions { class CorePermissions { public function isGranted($p): bool { return true; } } }
namespace MauticPlugin\MauticInboxBundle\Application { class InboxQuery {} class ConversationActions {} class ChannelTransportRegistry {} }
namespace MauticPlugin\MauticInboxBundle\Entity { class ConversationStateRepository {} }
namespace MauticPlugin\MauticMetaBundle\Application\Conversation { class ConversationManager {} }
namespace Symfony\Component\Security\Csrf { interface CsrfTokenManagerInterface { public function getToken($name): object; } }
namespace Symfony\Component\HttpFoundation {
    class Request {
        public object $headers;
        public function __construct(public array $query = [], public array $request = [], public array $attributes = [], public array $cookies = [], public array $files = [], public array $server = [], public string $content = '') { $this->headers = new class { public function set($k, $v): void {} }; }
        public function setSession($s): void {}
    }
    class JsonResponse { public function __construct(private array $data) {} public function getContent(): string { return json_encode($this->data); } public function getStatusCode(): int { return 200; } }
    class RequestStack { public int $depth = 0; public function push($r): void { ++$this->depth; } public function pop(): void { --$this->depth; } }
}
namespace Symfony\Component\HttpFoundation\Session { class Session { public function __construct($storage) {} } }
namespace Symfony\Component\HttpFoundation\Session\Storage { class MockArraySessionStorage {} }
namespace MauticPlugin\MauticInboxBundle\Controller {
    class InboxController {
        public array $calls = [];
        public function state(int $id, $request, $permissions, $users, $states, $actions, $query, $em, $conversations, \MauticPlugin\MauticInboxBundle\Application\ChannelTransportRegistry $transports): \Symfony\Component\HttpFoundation\JsonResponse {
            $this->calls[] = [$id, json_decode($request->content, true)['action'], $transports];
            return new \Symfony\Component\HttpFoundation\JsonResponse(['ok' => true]);
        }
    }
}
namespace {
    require __DIR__.'/../../Mcp/InboxToolService.php';
    $ref = new \ReflectionClass(\MauticPlugin\MauticInboxBundle\Mcp\InboxToolService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $controller = new \MauticPlugin\MauticInboxBundle\Controller\InboxController();
    $transports = new \MauticPlugin\MauticInboxBundle\Application\ChannelTransportRegistry();
    $stack = new \Symfony\Component\HttpFoundation\RequestStack();
    foreach (['inbox' => $controller, 'users' => new \Mautic\CoreBundle\Helper\UserHelper(), 'permissions' => new \Mautic\CoreBundle\Security\Permissions\CorePermissions(), 'states' => new \MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository(), 'actions' => new \MauticPlugin\MauticInboxBundle\Application\ConversationActions(), 'query' => new \MauticPlugin\MauticInboxBundle\Application\InboxQuery(), 'em' => new class implements \Doctrine\ORM\EntityManagerInterface {}, 'conversations' => new \MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager(), 'requests' => $stack, 'channelTransports' => $transports, 'csrf' => new class implements \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface { public function getToken($name): object { return new class { public function getValue(): string { return 'local-test'; } }; } }] as $key => $value) $ref->getProperty($key)->setValue($service, $value);
    foreach (['unassign', 'read', 'resolve', 'reopen', 'transfer', 'snooze'] as $action) {
        $result = $service->manage($action, 274, ['version' => 9]);
        if (($result['ok'] ?? false) !== true || $stack->depth !== 0) throw new \RuntimeException('State adapter failed or request scope leaked');
        $call = end($controller->calls);
        if ($call !== [274, $action, $transports]) throw new \RuntimeException('Channel transport registry was not passed to the controller');
    }
    echo "MCP state adapter checks passed (no database)\n";
}
