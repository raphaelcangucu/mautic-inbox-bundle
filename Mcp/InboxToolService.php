<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticInboxBundle\Application\{InboxQuery,ConversationActions,WhatsAppTemplates};
use MauticPlugin\MauticInboxBundle\Application\Ai\{AiStore,AiService,PiClient};
use MauticPlugin\MauticInboxBundle\Controller\{InboxController,AiController};
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Fixed adapters reuse the same controller/domain validation as the inbox UI. */
final class InboxToolService
{
    public function __construct(
        private InboxController $inbox, private AiController $ai,
        private UserHelper $users, private CorePermissions $permissions,
        private InboxQuery $query, private ConversationStateRepository $states,
        private ConversationActions $actions, private EntityManagerInterface $em,
        private ConversationManager $conversations, private WhatsAppTemplates $templates,
        private AiStore $store, private AiService $agents, private PiClient $pi,
        private CsrfTokenManagerInterface $csrf,
        private \Symfony\Component\HttpFoundation\RequestStack $requests,
    ) {}

    public function authorize(string $permission='view', bool $admin=false): void
    {
        if (!$this->users->getUser(true)?->getId() || ($admin && !$this->users->getUser(true)->isAdmin()) || !$this->permissions->isGranted(['inbox:conversations:'.$permission,'meta:messages:'.$permission])) {
            throw new AccessDeniedHttpException('Inbox permissions required.');
        }
    }

    public function read(string $resource, ?int $id, array $filters): array
    {
        $this->authorize();
        $response = match ($resource) {
            'conversations' => new JsonResponse($this->query->conversations($this->users->getUser(true),$filters,false)),
            'conversation' => $this->inbox->detail($this->id($id),$this->permissions,$this->users,$this->query,$this->states),
            'timeline' => $this->inbox->timeline($this->id($id),new Request($filters),$this->permissions,$this->query,$this->states),
            'templates' => $this->inbox->templates($this->id($id),$this->permissions,$this->states,$this->templates),
            'agents' => $this->ai->available($this->id($id),$this->users,$this->permissions,$this->states,$this->store,$this->agents),
            'users' => new JsonResponse(['items'=>$this->query->users()]),
            'canned_responses' => new JsonResponse(['items'=>$this->query->cannedResponses()]),
            default => throw new \InvalidArgumentException('Unsupported inbox resource.'),
        };
        return $this->result($response);
    }

    public function manage(string $action,int $id,array $data): array
    {
        $this->authorize($action==='reply'?'create':'edit');
        $request=$this->request(['action'=>$action]+$data);
        try { $response=match($action){
            'take' => $this->inbox->take($id,$request,$this->permissions,$this->users,$this->states,$this->actions,$this->query),
            'resolve','reopen','transfer','unassign','snooze','read' => $this->inbox->state($id,$request,$this->permissions,$this->users,$this->states,$this->actions,$this->query,$this->em,$this->conversations),
            'note' => $this->inbox->note($id,$request,$this->permissions,$this->users,$this->states,$this->actions),
            'reply' => $this->inbox->reply($id,$request,$this->permissions,$this->users,$this->states,$this->actions,$this->query),
            'assign_ai' => $this->ai->assign($id,$request,$this->users,$this->permissions,$this->states,$this->agents),
            default => throw new \InvalidArgumentException('Unsupported inbox action.'),
        };
        return $this->result($response); } finally { $this->requests->pop(); }
    }

    public function readAi(string $resource,?string $key): array
    {
        $this->authorize('view',true);
        $data=$this->result($this->ai->data($this->users,$this->store,$this->agents,$this->pi));
        if($resource==='overview')return $data;
        if(!in_array($resource,['documents','agents','config','assets','health'],true))throw new \InvalidArgumentException('Unsupported AI resource.');
        if($key!==null && in_array($resource,['documents','agents'],true)){
            foreach($data[$resource] as $row)if($row['key']===$key)return ['item'=>$row];
            throw new \InvalidArgumentException('Record not found.');
        }
        return ['data'=>$data[$resource]];
    }

    public function manageAi(string $action,array $data): array
    {
        $this->authorize('edit',true);
        if(!in_array($action,['document','agent','config','install','import-auth','health','validate'],true))throw new \InvalidArgumentException('Unsupported AI action.');
        $request=$this->request(['action'=>$action]+$data);
        try { return $this->result($this->ai->action($request,$this->users,$this->store,$this->agents,$this->pi,$this->em)); } finally { $this->requests->pop(); }
    }

    private function id(?int $id): int {if(!$id || $id<1)throw new \InvalidArgumentException('A conversation state ID is required.');return $id;}
    private function request(array $data): Request
    {
        // MCP authenticates independently; preserve the controller's CSRF validation for this internal request.
        $r=new Request([],[],[],[],[],['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json'],json_encode($data,JSON_THROW_ON_ERROR));
        $r->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        $this->requests->push($r);
        $r->headers->set('X-CSRF-Token',$this->csrf->getToken('mautic_inbox')->getValue());
        return $r;
    }
    private function result(JsonResponse $response): array
    {
        $result=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
        return ['httpStatus'=>$response->getStatusCode()]+$result;
    }
}
