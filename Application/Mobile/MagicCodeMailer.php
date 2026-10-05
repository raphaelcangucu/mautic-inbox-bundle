<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Mautic\EmailBundle\Helper\MailHelper;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\{Address, Email};

final class MagicCodeMailer
{
    public function __construct(private readonly TemporarySmtpSettings $settings, private readonly MailHelper $mailHelper)
    {
    }

    public function send(string $email, string $name, string $instance, string $code): void
    {
        $safeInstance = htmlspecialchars($instance, ENT_QUOTES, 'UTF-8');
        if (!preg_match('/^[0-9]{6}$/D', $code)) {
            throw new \InvalidArgumentException('Invalid access code.');
        }
        $subject = 'Seu código de acesso ao Mautic Inbox';
        $html = '<h2>Entrar no Mautic Inbox</h2><p>Instância: '.$safeInstance.'</p><p>Seu código: <strong style="font-size:28px;letter-spacing:6px">'.$code.'</strong></p><p>Válido por 5 minutos, somente no aparelho que o solicitou. Ao confirmar, o app usará as permissões do seu usuário e manterá uma sessão renovável por até 30 dias. Você pode encerrá-la ao sair do app.</p><p>Não compartilhe este código. Se não solicitou o acesso, ignore este e-mail.</p>';
        $text = 'Mautic Inbox — '.$instance."\nCódigo de acesso: ".$code."\nValidade: 5 minutos, somente neste aparelho. Sessão renovável por até 30 dias. Não compartilhe o código. Se não solicitou, ignore este e-mail.";
        $settings = $this->settings->read();
        if ($settings === null) {
            $mailer = $this->mailHelper->getMailer();
            $mailer->setTo([$email => $name]);
            $mailer->setSubject($subject);
            $mailer->setBody($html, 'text/html', 'UTF-8', true);
            $mailer->setPlainText($text);
            if (!$mailer->send()) {
                throw new \RuntimeException('mail_not_accepted');
            }

            return;
        }
        // Immediate transactional delivery, without campaign events, tracking or queued credentials.
        $transport = new EsmtpTransport($settings['host'], $settings['port'], false);
        $transport->setRequireTls(true);
        $transport->setUsername($settings['username']);
        $transport->setPassword($settings['password']);
        $transport->getStream()->setTimeout(20);
        $message = (new Email())->from(new Address($settings['from_email'], $settings['from_name']))
            ->to(new Address($email, $name))->subject($subject)->text($text)->html($html);
        try {
            $transport->send($message);
        } finally {
            $transport->stop();
        }
    }
}
