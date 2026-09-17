<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Entity;

use MauticPlugin\MauticInboxBundle\Entity\PushDevice;
use PHPUnit\Framework\TestCase;

final class PushDeviceTest extends TestCase
{
    public function testANewDeviceStartsActiveWithoutFailures(): void
    {
        $device = (new PushDevice())->setEndpoint('https://fcm.googleapis.com/fcm/send/abc')->setKeys('p256dh-cru', 'auth-cru');

        self::assertTrue($device->isActive());
        self::assertSame(0, $device->getConsecutiveFailures());
    }

    public function testTenConsecutiveFailuresRetireTheDevice(): void
    {
        $device = new PushDevice();

        for ($i = 0; $i < 9; ++$i) {
            $device->recordFailure();
        }
        self::assertTrue($device->isActive(), 'nove falhas ainda nao aposentam');

        $device->recordFailure();
        self::assertFalse($device->isActive(), 'a decima aposenta');
    }

    public function testASuccessClearsTheFailureCountAndRevives(): void
    {
        $device = new PushDevice();
        for ($i = 0; $i < 10; ++$i) {
            $device->recordFailure();
        }

        $device->recordSuccess();

        self::assertTrue($device->isActive());
        self::assertSame(0, $device->getConsecutiveFailures());
        self::assertNotNull($device->getLastDeliveredAt());
    }

    public function testTheSameEndpointAlwaysHashesTheSameWay(): void
    {
        $a = (new PushDevice())->setEndpoint('https://fcm.googleapis.com/fcm/send/abc');
        $b = (new PushDevice())->setEndpoint('https://fcm.googleapis.com/fcm/send/abc');

        self::assertSame($a->getEndpointHash(), $b->getEndpointHash());
        self::assertSame(64, strlen($a->getEndpointHash()));
    }

    public function testTheKeyMaterialIsKeptExactlyAsTheBrowserSentIt(): void
    {
        $p256dh = 'BN1kSGFmbGF0dGVuZWRwb2ludHZhbHVldGhhdGlzYmFzZTY0dXJs';
        $auth   = 'c3VwZXItc2VjcmV0LTE2';

        $device = (new PushDevice())->setKeys($p256dh, $auth);

        self::assertSame($p256dh, $device->getP256dh(), 'nada aqui decodifica: o envio decodifica na hora certa');
        self::assertSame($auth, $device->getAuth());
    }
}
