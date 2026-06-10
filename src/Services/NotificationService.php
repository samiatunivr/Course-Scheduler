<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;

/**
 * Multi-channel notifications: in-app (always), email, Slack/Teams
 * webhooks, SMS gateway. External channels are best-effort and never
 * block the calling operation.
 */
final class NotificationService
{
    public function __construct(
        private readonly array $mailConfig,
        private readonly array $integrations,
    ) {
    }

    /** @param string[] $channels subset of inapp|email|sms|teams|slack */
    public function notify(int $userId, string $type, string $title, string $body = '', array $channels = ['inapp']): int
    {
        $channels = array_values(array_unique(array_merge(['inapp'], $channels)));
        $id = DB::insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'channels' => implode(',', $channels),
        ]);

        $user = DB::selectOne('SELECT email, name FROM users WHERE id = ?', [$userId]);

        foreach ($channels as $channel) {
            try {
                match ($channel) {
                    'email' => $user !== null && $this->sendEmail((string) $user['email'], $title, $body),
                    'slack' => $this->postWebhook($this->integrations['slack_webhook'], ['text' => "*$title*\n$body"]),
                    'teams' => $this->postWebhook($this->integrations['teams_webhook'], ['text' => "**$title**\n\n$body"]),
                    'sms' => $this->sendSms($title),
                    default => null,
                };
            } catch (\Throwable $e) {
                error_log("Notification channel $channel failed: " . $e->getMessage());
            }
        }
        DB::update('notifications', $id, ['sent_at' => date('Y-m-d H:i:s')]);

        return $id;
    }

    public function notifyRole(string $roleCode, string $type, string $title, string $body = '', array $channels = ['inapp']): void
    {
        $users = DB::select(
            'SELECT DISTINCT u.id FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.code = ? AND u.is_active = 1',
            [$roleCode]
        );
        foreach ($users as $u) {
            $this->notify((int) $u['id'], $type, $title, $body, $channels);
        }
    }

    private function sendEmail(string $to, string $subject, string $body): bool
    {
        if ($this->mailConfig['smtp_host'] === '') {
            // Hostinger shared hosting: PHP mail() routes through local MTA.
            $headers = sprintf("From: %s <%s>\r\nContent-Type: text/plain; charset=UTF-8",
                $this->mailConfig['from_name'], $this->mailConfig['from']);

            return mail($to, $subject, $body, $headers);
        }
        // SMTP delivery is delegated to the configured relay via mail() with ini overrides,
        // or a Composer mailer (symfony/mailer) when installed.
        ini_set('SMTP', (string) $this->mailConfig['smtp_host']);
        ini_set('smtp_port', (string) $this->mailConfig['smtp_port']);

        return mail($to, $subject, $body);
    }

    private function postWebhook(string $url, array $payload): bool
    {
        if ($url === '') {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        curl_close($ch);

        return $ok;
    }

    private function sendSms(string $message): bool
    {
        $url = $this->integrations['sms_gateway_url'];
        if ($url === '') {
            return false;
        }

        return $this->postWebhook($url, ['key' => $this->integrations['sms_gateway_key'], 'message' => $message]);
    }
}
