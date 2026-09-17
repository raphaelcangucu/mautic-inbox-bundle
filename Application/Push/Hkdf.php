<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * Derivacao HKDF-SHA256 da RFC 5869, no subconjunto que o Web Push usa.
 */
final class Hkdf
{
    public static function extract(string $salt, string $inputKeyMaterial): string
    {
        return hash_hmac('sha256', $inputKeyMaterial, $salt, true);
    }

    public static function expand(string $pseudoRandomKey, string $info, int $length): string
    {
        if ($length < 1 || $length > 255 * 32) {
            throw new \InvalidArgumentException('Comprimento fora da faixa do HKDF.');
        }

        $output   = '';
        $previous = '';
        for ($counter = 1; strlen($output) < $length; ++$counter) {
            $previous = hash_hmac('sha256', $previous.$info.chr($counter), $pseudoRandomKey, true);
            $output .= $previous;
        }

        return substr($output, 0, $length);
    }
}
