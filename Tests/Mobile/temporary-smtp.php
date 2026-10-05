<?php

require __DIR__.'/../../Application/Mobile/TemporarySmtpSettings.php';

use MauticPlugin\MauticInboxBundle\Application\Mobile\TemporarySmtpSettings;

function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function refused(callable $action): void { try { $action(); } catch (RuntimeException $error) { check(!str_contains($error->getMessage(), 'unit-secret'), 'Secret leaked in exception'); return; } throw new RuntimeException('Expected refusal'); }

$root = sys_get_temp_dir().'/mautic_smtp_unit_'.bin2hex(random_bytes(8));
mkdir($root.'/project', 0700, true);
mkdir($root.'/inbox-mobile-private', 0700);
$path = $root.'/inbox-mobile-private/mailer.json';
$settings = new TemporarySmtpSettings($root.'/project');
check($settings->read() === null, 'Default mailer when no override exists');
$valid = ['host' => 'smtp.dreamhost.com', 'port' => 587, 'username' => 'support@example.com', 'password' => 'unit-secret^#', 'from_email' => 'support@example.com', 'from_name' => 'Mautic Inbox', 'expires_at' => time() + 3600];
function saveSettings(string $path, array $value): void { file_put_contents($path, json_encode($value)); chmod($path, 0600); }
try {
    saveSettings($path, $valid);
    check($settings->read() === $valid, 'Read private settings and preserve password special characters');
    chmod($path, 0644);
    refused(fn() => $settings->read());
    chmod($path, 0600);
    chmod(dirname($path), 0777);
    refused(fn() => $settings->read());
    chmod(dirname($path), 0700);
    foreach ([['expires_at' => time() - 1], ['expires_at' => 'invalid'], ['port' => 25], ['from_email' => 'different@example.com'], ['username' => 'invalid'], ['host' => "smtp.example.com\r\nmalicious"], ['password' => "unit-secret\nmalicious"]] as $change) {
        saveSettings($path, array_replace($valid, $change));
        refused(fn() => $settings->read());
    }
    file_put_contents($path, '{invalid');
    refused(fn() => $settings->read());
    unlink($path);
    saveSettings($path.'.source', $valid);
    symlink($path.'.source', $path);
    refused(fn() => $settings->read());
} finally {
    if (file_exists($path) || is_link($path)) { unlink($path); }
    if (file_exists($path.'.source')) { unlink($path.'.source'); }
    rmdir($root.'/inbox-mobile-private'); rmdir($root.'/project'); rmdir($root);
}
echo "PASS: private permissions, expiry, TLS port, sender identity, malformed settings, injection and symlink refusal; no database or network\n";
