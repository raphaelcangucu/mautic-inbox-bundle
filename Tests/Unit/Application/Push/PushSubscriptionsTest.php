<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Push\PushDeviceStore;
use MauticPlugin\MauticInboxBundle\Application\Push\PushSubscriptions;
use MauticPlugin\MauticInboxBundle\Entity\PushDevice;
use PHPUnit\Framework\TestCase;

/** O mesmo contrato do PushDeviceRepository, servido de um array. */
final class InMemoryDevices implements PushDeviceStore
{
    /** @var array<string, PushDevice> */
    public array $rows = [];

    public function findByEndpointHash(string $hash): ?PushDevice
    {
        return $this->rows[$hash] ?? null;
    }

    public function save(PushDevice $device): void
    {
        $this->rows[$device->getEndpointHash()] = $device;
    }

    public function remove(PushDevice $device): void
    {
        unset($this->rows[$device->getEndpointHash()]);
    }

    public function activeForUser(User $user): array
    {
        return array_values(array_filter($this->rows, static fn (PushDevice $d): bool => $d->isActive() && $d->getUser() === $user));
    }
}

final class PushSubscriptionsTest extends TestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc';
    private const OTHER    = 'https://fcm.googleapis.com/fcm/send/xyz';

    public function testSubscribingTwiceFromTheSameBrowserKeepsOneRecord(): void
    {
        $devices = new InMemoryDevices();
        $service = new PushSubscriptions($devices);
        $user    = new User();

        $first  = $service->subscribe($user, self::ENDPOINT, 'p1', 'a1', 'Firefox');
        $second = $service->subscribe($user, self::ENDPOINT, 'p2', 'a2', 'Firefox');

        self::assertCount(1, $devices->rows);
        self::assertSame($first, $second);
        self::assertSame('p2', $second->getP256dh(), 'a reinscricao traz chaves novas');
        self::assertSame('a2', $second->getAuth());
    }

    public function testResubscribingRevivesARetiredDevice(): void
    {
        $devices = new InMemoryDevices();
        $service = new PushSubscriptions($devices);
        $user    = new User();

        $device = $service->subscribe($user, self::ENDPOINT, 'p1', 'a1', null);
        for ($i = 0; $i < PushDevice::RETIREMENT_THRESHOLD; ++$i) {
            $device->recordFailure();
        }
        self::assertFalse($device->isActive());

        $revived = $service->subscribe($user, self::ENDPOINT, 'p2', 'a2', null);

        self::assertTrue($revived->isActive());
        self::assertSame(0, $revived->getConsecutiveFailures());
    }

    public function testUnsubscribingRemovesOnlyThatEndpoint(): void
    {
        $devices = new InMemoryDevices();
        $service = new PushSubscriptions($devices);
        $user    = new User();
        $service->subscribe($user, self::ENDPOINT, 'p1', 'a1', null);
        $service->subscribe($user, self::OTHER, 'p2', 'a2', null);

        self::assertTrue($service->unsubscribe($user, self::ENDPOINT));

        self::assertCount(1, $devices->rows);
        self::assertSame(self::OTHER, reset($devices->rows)->getEndpoint());
    }

    public function testAUserCannotRemoveAnotherUsersDevice(): void
    {
        $devices = new InMemoryDevices();
        $service = new PushSubscriptions($devices);
        $owner   = new User();
        $service->subscribe($owner, self::ENDPOINT, 'p1', 'a1', null);

        self::assertFalse($service->unsubscribe(new User(), self::ENDPOINT), 'o endpoint de outro nao e meu para apagar');
        self::assertCount(1, $devices->rows);
    }

    public function testOnlyActiveDevicesAreReturnedForAUser(): void
    {
        $devices = new InMemoryDevices();
        $service = new PushSubscriptions($devices);
        $user    = new User();
        $other   = new User();

        $alive   = $service->subscribe($user, self::ENDPOINT, 'p1', 'a1', null);
        $retired = $service->subscribe($user, self::OTHER, 'p2', 'a2', null);
        $service->subscribe($other, 'https://updates.push.services.mozilla.com/wpush/v2/gAA', 'p3', 'a3', null);

        for ($i = 0; $i < PushDevice::RETIREMENT_THRESHOLD; ++$i) {
            $retired->recordFailure();
        }

        self::assertSame([$alive], $service->activeFor($user));
    }
}
