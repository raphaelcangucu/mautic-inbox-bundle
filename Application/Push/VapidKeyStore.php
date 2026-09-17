<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

use Mautic\CoreBundle\Helper\EncryptionHelper;

/**
 * Guarda o par VAPID da instalacao.
 *
 * O par e unico por instalacao: perder a privada invalida toda inscricao ja feita, por isso
 * ela vai selada pelo EncryptionHelper. A publica fica em claro de proposito — o navegador a
 * recebe de qualquer jeito, e decifrar na requisicao mais quente do fluxo nao compraria nada.
 */
final class VapidKeyStore
{
    public const PRIVATE_SETTING = 'vapid_private';
    public const PUBLIC_SETTING  = 'vapid_public';
    public const SUBJECT_SETTING = 'vapid_subject';

    public function __construct(
        private EncryptionHelper $encryption,
        private PushSettingStore $settings,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->settings->get(self::PRIVATE_SETTING) && null !== $this->settings->get(self::PUBLIC_SETTING);
    }

    public function load(): VapidKeys
    {
        $sealed = $this->settings->get(self::PRIVATE_SETTING);
        $public = $this->settings->get(self::PUBLIC_SETTING);
        if (null === $sealed || null === $public) {
            throw new \RuntimeException('O push nao esta configurado: rode mautic:inbox:push:setup para gerar o par VAPID.');
        }

        $pem = $this->encryption->decrypt($sealed);
        if (!is_string($pem) || '' === $pem) {
            throw new \RuntimeException('Nao foi possivel abrir a chave privada VAPID guardada. Se a chave secreta da instalacao mudou, rode mautic:inbox:push:setup --force, ciente de que toda inscricao existente morre.');
        }

        return VapidKeys::fromStorage($pem, $public);
    }

    public function generate(): VapidKeys
    {
        $keys = VapidKeys::generate();
        $this->settings->set(self::PRIVATE_SETTING, $this->encryption->encrypt($keys->privatePem()));
        $this->settings->set(self::PUBLIC_SETTING, $keys->publicKey());

        return $keys;
    }

    public function subject(): ?string
    {
        return $this->settings->get(self::SUBJECT_SETTING);
    }

    public function storeSubject(string $subject): void
    {
        $this->settings->set(self::SUBJECT_SETTING, $subject);
    }
}
