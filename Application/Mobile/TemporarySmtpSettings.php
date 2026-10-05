<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Optional, short-lived credentials kept outside the document root and compiled container. */
final class TemporarySmtpSettings
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    public function read(): ?array
    {
        // Sibling of the resolved project directory; never reachable under Mautic's web root.
        $directory = dirname(realpath($this->projectDir) ?: $this->projectDir).'/inbox-mobile-private';
        $path = $directory.'/mailer.json';
        if (!file_exists($path)) {
            return null;
        }
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || is_link($directory)
            || (fileperms($directory) & 0077) !== 0 || (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }
        try {
            $settings = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }
        if (!is_array($settings) || !is_int($settings['expires_at'] ?? null) || $settings['expires_at'] <= time()) {
            throw new \RuntimeException('Temporary SMTP settings expired or invalid.');
        }
        foreach (['host', 'username', 'password', 'from_email', 'from_name'] as $key) {
            if (!is_string($settings[$key] ?? null) || $settings[$key] === '' || preg_match('/[\r\n\x00]/', $settings[$key])) {
                throw new \RuntimeException('Invalid private SMTP settings.');
            }
        }
        if (!preg_match('/^[a-z0-9.-]+$/Di', $settings['host']) || ($settings['port'] ?? null) !== 587
            || !filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)
            || !filter_var($settings['username'], FILTER_VALIDATE_EMAIL)
            || $settings['username'] !== $settings['from_email']) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }

        return $settings;
    }
}
