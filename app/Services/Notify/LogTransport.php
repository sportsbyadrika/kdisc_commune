<?php

declare(strict_types=1);

namespace App\Services\Notify;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Development mail transport (MAIL_DSN=log://default). Nothing leaves the machine:
 *  - appends a readable copy (headers + text + HTML) to storage/logs/mail.log
 *  - saves every message as storage/mail/{timestamp}-{slug}.eml (open in a mail client)
 *    and .html (open in a browser — the links work, e.g. the set-password link).
 */
final class LogTransport extends AbstractTransport
{
    public function __construct(private readonly string $logFile, private readonly string $mailDir)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        $email = match (true) {
            $original instanceof Email => $original,
            $original instanceof Message => MessageConverter::toEmail($original),
            default => (new Email())->text($original->toString()),
        };
        $to = implode(', ', array_map(static fn ($a) => $a->toString(), $email->getTo()));
        $subject = (string) $email->getSubject();
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        foreach ([dirname($this->logFile), $this->mailDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($subject)), '-');
        $base = sprintf('%s/%s-%s-%s', $this->mailDir, date('Ymd-His'), substr(bin2hex(random_bytes(3)), 0, 6), substr($slug, 0, 50));
        file_put_contents($base . '.eml', $message->toString());
        file_put_contents($base . '.html', $html);

        $rule = str_repeat('=', 78);
        $entry = implode("\n", [
            $rule,
            'Date:    ' . date('Y-m-d H:i:s'),
            'To:      ' . $to,
            'Subject: ' . $subject,
            'Saved:   ' . $base . '.eml / .html',
            str_repeat('-', 30) . ' text/plain ' . str_repeat('-', 36),
            $text,
            str_repeat('-', 30) . ' text/html ' . str_repeat('-', 37),
            $html,
            '',
        ]);
        file_put_contents($this->logFile, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    public function __toString(): string
    {
        return 'log://default';
    }
}
