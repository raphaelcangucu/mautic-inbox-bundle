<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile\Push;

/** Only bundled functional text is localized. Customer previews retain their original text. */
final class NativePushLocale
{
    private const MESSAGES = [
        'pt-BR' => ['new' => 'Você recebeu uma nova mensagem.', 'test' => 'Push remoto do Mautic conectado a este aparelho.', 'contact' => 'Contato'],
        'en' => ['new' => 'You received a new message.', 'test' => 'Mautic remote push is connected to this device.', 'contact' => 'Contact'],
        'es' => ['new' => 'Has recibido un mensaje nuevo.', 'test' => 'El push remoto de Mautic está conectado a este dispositivo.', 'contact' => 'Contacto'],
    ];

    public static function checked(mixed $locale): string
    {
        if (!is_string($locale) || !isset(self::MESSAGES[$locale])) {
            throw new \DomainException('invalid_device');
        }
        return $locale;
    }

    public static function text(mixed $locale, string $key): string
    {
        $locale = is_string($locale) && isset(self::MESSAGES[$locale]) ? $locale : 'pt-BR';
        return self::MESSAGES[$locale][$key];
    }
}
