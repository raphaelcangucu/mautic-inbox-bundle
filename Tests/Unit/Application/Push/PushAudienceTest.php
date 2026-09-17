<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Push\InboxAccess;
use MauticPlugin\MauticInboxBundle\Application\Push\PushAudience;
use MauticPlugin\MauticInboxBundle\Application\Push\PushDeviceStore;
use MauticPlugin\MauticInboxBundle\Entity\PushDevice;
use PHPUnit\Framework\TestCase;

final class PushAudienceTest extends TestCase
{
    public function testAnAssignedConversationOnlyReachesItsOwner(): void
    {
        $dona   = $this->user(1);
        $outro  = $this->user(2);
        $devices = $this->audience(
            [$this->device($dona), $this->device($outro)],
            todosPodem: true,
        );

        $alcancados = $devices->devicesFor($dona);

        self::assertCount(1, $alcancados);
        self::assertSame(1, $alcancados[0]->getUser()?->getId());
    }

    public function testAConversationOwnedBySomeoneElseReachesNobodyElse(): void
    {
        $dona  = $this->user(1);
        $audience = $this->audience([$this->device($this->user(2)), $this->device($this->user(3))], todosPodem: true);

        self::assertSame([], $audience->devicesFor($dona), 'conversa alheia nao notifica a equipe inteira');
    }

    public function testAnUnassignedConversationReachesEveryAgentWhoCanSeeTheInbox(): void
    {
        $audience = $this->audience(
            [$this->device($this->user(1)), $this->device($this->user(2))],
            todosPodem: true,
        );

        self::assertCount(2, $audience->devicesFor(null));
    }

    public function testAnUnassignedConversationSkipsPeopleWithoutInboxPermission(): void
    {
        $permitido = $this->user(1);
        $barrado   = $this->user(2);

        $audience = new PushAudience(
            new class([$this->device($permitido), $this->device($barrado)]) implements PushDeviceStore {
                public function __construct(private array $todos) {}
                public function findByEndpointHash(string $hash): ?PushDevice { return null; }
                public function save(PushDevice $device): void {}
                public function remove(PushDevice $device): void {}
                public function allActive(): array { return $this->todos; }
                public function activeForUser(User $user): array { return []; }
            },
            new class implements InboxAccess {
                public function canViewInbox(User $user): bool { return 1 === $user->getId(); }
            },
        );

        $alcancados = $audience->devicesFor(null);

        self::assertCount(1, $alcancados);
        self::assertSame(1, $alcancados[0]->getUser()?->getId());
    }

    public function testARetiredDeviceNeverAppears(): void
    {
        // A aposentadoria e aplicada na fonte: allActive so devolve ativos. Este teste trava
        // o contrato, porque um aparelho aposentado que continuasse recebendo envio para
        // sempre seria exatamente o vazamento que a regra existe para impedir.
        $audience = $this->audience([], todosPodem: true);

        self::assertSame([], $audience->devicesFor(null));
    }

    /**
     * @param list<PushDevice> $devices
     */
    private function audience(array $devices, bool $todosPodem): PushAudience
    {
        return new PushAudience(
            new class($devices) implements PushDeviceStore {
                public function __construct(private array $todos) {}
                public function findByEndpointHash(string $hash): ?PushDevice { return null; }
                public function save(PushDevice $device): void {}
                public function remove(PushDevice $device): void {}
                public function allActive(): array { return $this->todos; }
                public function activeForUser(User $user): array { return []; }
            },
            new class($todosPodem) implements InboxAccess {
                public function __construct(private bool $podem) {}
                public function canViewInbox(User $user): bool { return $this->podem; }
            },
        );
    }

    private function device(User $user): PushDevice
    {
        return (new PushDevice())
            ->setUser($user)
            ->setEndpoint('https://fcm.googleapis.com/fcm/send/'.$user->getId())
            ->setKeys('p', 'a');
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
