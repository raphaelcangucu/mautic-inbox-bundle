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
    unlink($path);
    saveSettings($path, $valid);
    $selectionPath = dirname($path).'/mailer-selection.json';
    $resendPath = dirname($path).'/resend.json';
    $resend = array_replace($valid, ['provider' => 'resend', 'host' => 'smtp.resend.com', 'username' => 'resend',
        'password' => 're_unit-secret-abcdefghijklmnop', 'from_email' => 'support@updates.example.com', 'reply_to' => 'support@example.com']);
    saveSettings($resendPath, $resend);
    check($settings->read() === $valid, 'Inactive Resend profile must not replace the existing mailer');
    $selection = ['login' => 'resend', 'expires_at' => time() + 3600];
    saveSettings($selectionPath, $selection);
    check($settings->read() === $resend, 'Select Resend with its own username, domain sender and reply address');
    chmod($resendPath, 0644);
    refused(fn() => $settings->read());
    chmod($resendPath, 0600);
    foreach ([['host' => 'smtp.example.com'], ['provider' => 'smtp'], ['username' => 'support@example.com'], ['password' => 'invalid-secret'],
        ['reply_to' => "support@example.com\r\nBcc: secret@example.com"], ['expires_at' => time() - 1], ['port' => 465]] as $change) {
        saveSettings($resendPath, array_replace($resend, $change));
        refused(fn() => $settings->read());
    }
    saveSettings($resendPath, $resend);
    foreach ([['login' => '../mailer'], ['login' => ['resend']], ['expires_at' => time() - 1], ['expires_at' => 'future']] as $change) {
        saveSettings($selectionPath, array_replace($selection, $change));
        refused(fn() => $settings->read());
    }
    saveSettings($selectionPath, ['expires_at' => time() + 3600]);
    refused(fn() => $settings->read());
    saveSettings($selectionPath, $selection);
    unlink($resendPath);
    refused(fn() => $settings->read()); // A missing selected profile must never silently fall back.
    saveSettings($selectionPath, ['login' => 'dreamhost', 'expires_at' => time() + 3600]);
    check($settings->read() === $valid, 'Explicit selection of original DreamHost profile');
} finally {
    if (file_exists($path) || is_link($path)) { unlink($path); }
    if (file_exists($path.'.source')) { unlink($path.'.source'); }
    foreach (['resend.json', 'mailer-selection.json'] as $filename) {
        if (file_exists(dirname($path).'/'.$filename)) { unlink(dirname($path).'/'.$filename); }
    }
    rmdir($root.'/inbox-mobile-private'); rmdir($root.'/project'); rmdir($root);
}
echo "PASS: SMTP/Resend selection, private permissions, expiry, TLS port, sender identity, reply address, malformed settings, injection, no silent fallback and symlink refusal; no database or network\n";
