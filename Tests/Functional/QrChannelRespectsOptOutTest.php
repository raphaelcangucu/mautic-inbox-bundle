<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSender;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;

/**
 * Quem pediu para sair pediu para sair em todo canal.
 *
 * O canal por QR Code nao e homologado e nasceu de um plugin separado. Houve um caminho
 * considerado em que ele traria o proprio remetente, e esse caminho teria deixado o canal
 * novo ser o unico que ignora opt-out: a checagem de consentimento mora no remetente do
 * conector, nao na borda. O desenho recuou dali, e este teste e o que impede alguem de
 * voltar para la sem perceber.
 *
 * Roda sem WhatsApp nenhum de proposito: a recusa acontece antes de o remetente escolher
 * o transporte, entao nao ha sessao para parear nem servico para responder. E por isso que
 * esta garantia nao precisa esperar um chip.
 *
 * O numero e valido em E.164 porque, sem mensagem recebida recente, o remetente normaliza
 * o destinatario ANTES de perguntar pelo consentimento. Um numero mal formado pararia na
 * normalizacao e o teste ficaria verde sem nunca chegar na pergunta que ele faz.
 */
final class QrChannelRespectsOptOutTest extends MauticMysqlTestCase
{
    public function testAQrSessionRefusesToWriteToSomebodyWhoOptedOut(): void
    {
        $connection = (new MetaConnection())->setName('QR opt-out test')->setAppId('qr-optout-app')->setStatus('active');
        $asset = (new MetaAsset())
            ->setConnection($connection)
            ->setName('Atendimento QR')
            ->setExternalId('sess-optout')
            ->setType(AssetType::WhatsAppQrSession)
            ->setStatus('active');
        $asset->setIsPublished(true);
        $identity = (new MetaContactIdentity())
            ->setAsset($asset)
            ->setExternalId('5511987654321')
            ->setConsentStatus(ConsentStatus::OptedOut);

        foreach ([$connection, $asset, $identity] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $sender = static::getContainer()->get(WhatsAppSender::class);

        // A mensagem faz parte da afirmacao, e nao e preciosismo: sem ela, uma versao em
        // que o canal por QR pula a checagem de consentimento continua verde, porque
        // alguma outra coisa mais adiante -- sessao nao configurada, transporte ausente --
        // tambem estoura DomainException. Medi isso: desligando a checagem so para este
        // tipo de asset, o teste sem a mensagem passava igual.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Contact opted out of WhatsApp messages.');
        $sender->sendText($asset, '5511987654321', 'Nao deve sair por canal nenhum', false, null, true);
    }
}
