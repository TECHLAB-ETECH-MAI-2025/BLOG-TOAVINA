<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class GmailService
{
    private $transport;
    private string $fromEmail;
    private string $fromName;

    public function __construct(
        #[Autowire('%env(MAILER_DSN)%')]
        private string $mailerDsn,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')]
        string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        string $fromName
    ) {
        // Utiliser la configuration de ton .env
        $this->transport = Transport::fromDsn($this->mailerDsn);
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    public function sendEmail(string $toEmail, string $subject, string $textContent, string $htmlContent): bool
    {
        try {
            $email = (new Email())
                ->from($this->fromEmail)
                ->to($toEmail)
                ->subject($subject)
                ->text($textContent)
                ->html($htmlContent);

            $this->transport->send($email);
            return true;
        } catch (\Exception $e) {
            error_log('GmailService error: ' . $e->getMessage());
            return false;
        }
    }

    public function sendTestEmail(string $toEmail, string $subject, string $content): bool
    {
        try {
            $email = (new Email())
                ->from($this->fromEmail)
                ->to($toEmail)
                ->subject($subject)
                ->text($content)
                ->html('<h1>' . $subject . '</h1><p>' . $content . '</p>');

            $this->transport->send($email);
            return true;
        } catch (\Exception $e) {
            error_log('GmailService test error: ' . $e->getMessage());
            return false;
        }
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }
}
