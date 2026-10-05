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
        $selection = $this->readPrivateFile($directory, 'mailer-selection.json');
        $profile = $selection['login'] ?? 'dreamhost';
        if ($selection !== null && (!isset($selection['login']) || !is_string($profile) || !in_array($profile, ['dreamhost', 'resend'], true)
            || !is_int($selection['expires_at'] ?? null) || $selection['expires_at'] <= time())) {
            throw new \RuntimeException('Invalid or expired mailer selection.');
        }
        $settings = $this->readPrivateFile($directory, $profile === 'resend' ? 'resend.json' : 'mailer.json');
        if ($settings === null) {
            if ($selection !== null) {
                throw new \RuntimeException('Selected mailer profile unavailable.');
            }

            return null;
        }
        if (!is_int($settings['expires_at'] ?? null) || $settings['expires_at'] <= time()) {
            throw new \RuntimeException('Temporary SMTP settings expired or invalid.');
        }
        foreach (['host', 'username', 'password', 'from_email', 'from_name'] as $key) {
            if (!is_string($settings[$key] ?? null) || $settings[$key] === '' || preg_match('/[\r\n\x00]/', $settings[$key])) {
                throw new \RuntimeException('Invalid private SMTP settings.');
            }
        }
        if (!preg_match('/^[a-z0-9.-]+$/Di', $settings['host']) || ($settings['port'] ?? null) !== 587
            || !filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }
        $provider = $settings['provider'] ?? 'smtp';
        if ($profile === 'resend') {
            if ($provider !== 'resend' || $settings['host'] !== 'smtp.resend.com' || $settings['username'] !== 'resend'
                || !preg_match('/^re_[A-Za-z0-9_-]{20,}$/D', $settings['password'])) {
                throw new \RuntimeException('Invalid private Resend settings.');
            }
        } elseif ($provider !== 'smtp' || !filter_var($settings['username'], FILTER_VALIDATE_EMAIL)
            || $settings['username'] !== $settings['from_email']) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }
        if (isset($settings['reply_to']) && (!is_string($settings['reply_to'])
            || preg_match('/[\r\n\x00]/', $settings['reply_to']) || !filter_var($settings['reply_to'], FILTER_VALIDATE_EMAIL))) {
            throw new \RuntimeException('Invalid reply address.');
        }

        return $settings;
    }

    private function readPrivateFile(string $directory, string $filename): ?array
    {
        $path = $directory.'/'.$filename;
        clearstatcache(true);
        if (is_link($path) || is_link($directory)) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path) || (fileperms($directory) & 0077) !== 0 || (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }
        if (!is_array($value)) {
            throw new \RuntimeException('Invalid private SMTP settings.');
        }

        return $value;
    }
}
