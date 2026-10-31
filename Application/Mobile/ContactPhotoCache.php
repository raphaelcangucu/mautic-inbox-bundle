<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Mautic\CoreBundle\Helper\CoreParametersHelper;

/**
 * As fotos de perfil que a Meta manda junto da mensagem sao URLs assinadas de vida curta
 * (`platform-lookaside.fbsbx.com` e afins): poucos dias depois respondem 404 e o avatar perde a
 * imagem. Aqui a foto e baixada uma vez, reduzida a um quadrado pequeno em WebP e servida por
 * uma URL estavel do proprio Mautic, que vale para o app e para o inbox web.
 *
 * O WhatsApp nao entra nisto: a API da Meta nao manda foto de contato de WhatsApp, nem pelo
 * canal homologado nem pela sessao por QR, entao nao ha o que guardar.
 */
final class ContactPhotoCache
{
    public const SIZE = 96;
    private const MAX_BYTES = 2097152;
    private const FOLDER = 'inbox-avatars';
    private const HOSTS = ['.cdninstagram.com', '.fbcdn.net', '.fbsbx.com'];

    /** Quantas fotos esta requisicao ainda pode baixar: uma listagem nunca vira uma fila de downloads. */
    private int $budget = 1;
    private readonly string $mediaPath;
    private readonly string $mediaUrl;

    public function __construct(CoreParametersHelper $parameters, string $projectDir)
    {
        $this->mediaPath = rtrim($projectDir, '/').'/media/images';
        $this->mediaUrl  = rtrim((string) $parameters->get('site_url'), '/').'/media/images';
    }

    /** So URLs https de CDN da Meta: nunca busca um endereco que veio de outro lugar. */
    public static function acceptable(?string $url): bool
    {
        if (!is_string($url) || !str_starts_with($url, 'https://')) { return false; }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) { return false; }
        foreach (self::HOSTS as $suffix) { if (str_ends_with($host, $suffix)) { return true; } }

        return false;
    }

    /** O nome do arquivo vem da identidade, nao da URL, que muda a cada assinatura. */
    public static function name(string $identity): string
    {
        return substr(hash('sha256', $identity), 0, 32).'.webp';
    }

    public function url(string $identity): ?string
    {
        $name = self::name($identity);

        return is_file($this->mediaPath.'/'.self::FOLDER.'/'.$name) ? rtrim($this->mediaUrl, '/').'/'.self::FOLDER.'/'.$name : null;
    }

    /** Devolve a URL local, baixando e convertendo quando ainda nao houver copia. */
    public function store(string $identity, ?string $remote): ?string
    {
        if ($existing = $this->url($identity)) { return $existing; }
        if ($this->budget < 1 || !self::acceptable($remote) || !function_exists('imagewebp')) { return null; }
        --$this->budget;

        $folder = $this->mediaPath.'/'.self::FOLDER;
        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) { return null; }

        $bytes = $this->read((string) $remote);
        if (null === $bytes) { return null; }

        $square = $this->square($bytes);
        if (null === $square) { return null; }

        $target = $folder.'/'.self::name($identity);
        $temporary = $target.'.'.bin2hex(random_bytes(4));
        if (!imagewebp($square, $temporary, 82)) { imagedestroy($square); @unlink($temporary); return null; }
        imagedestroy($square);
        // O rename e atomico: ninguem le um arquivo pela metade enquanto a conversao acontece.
        if (!rename($temporary, $target)) { @unlink($temporary); return null; }

        return $this->url($identity);
    }

    private function read(string $url): ?string
    {
        $context = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 0, 'ignore_errors' => true]]);
        $handle = @fopen($url, 'rb', false, $context);
        if (false === $handle) { return null; }
        $bytes = @stream_get_contents($handle, self::MAX_BYTES + 1);
        $status = $http_response_header[0] ?? '';
        fclose($handle);

        if (!is_string($bytes) || '' === $bytes || strlen($bytes) > self::MAX_BYTES) { return null; }
        if (is_string($status) && !str_contains($status, ' 200')) { return null; }

        return $bytes;
    }

    /** Recorta pelo centro e reduz: o avatar e redondo, entao o quadrado e o que serve. */
    private function square(string $bytes): ?\GdImage
    {
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof \GdImage) { return null; }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        $ok = imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);
        imagedestroy($source);
        if (!$ok) { imagedestroy($target); return null; }

        return $target;
    }
}
