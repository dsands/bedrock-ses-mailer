<?php
/**
 * Plugin Name: Bedrock SES Mailer
 * Description: wp_mail() via Symfony Mailer + MAILER_DSN. Forces verified From for SES.
 * Version: 2.1
 */

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

$__bedrock_mailer_autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (is_readable($__bedrock_mailer_autoload)) {
    require_once $__bedrock_mailer_autoload;
}

function bedrock_ses_log(string $msg): void
{
    error_log('[bedrock-ses-mailer] ' . $msg);
}

function bedrock_ses_env(string $key, string $default = ''): string
{
    if (function_exists('env')) {
        $v = env($key);
        if ($v !== null && $v !== false && $v !== '') {
            return (string) $v;
        }
    }
    if (defined($key) && constant($key) !== '') {
        return (string) constant($key);
    }
    $v = getenv($key);
    return ($v !== false && $v !== '') ? (string) $v : $default;
}

function bedrock_ses_addresses($emails): array
{
    $out = [];
    if (is_string($emails)) {
        $emails = preg_split('/[,;]+/', $emails) ?: [];
    }
    foreach ((array) $emails as $email) {
        if ($email instanceof Address) {
            $out[] = $email;
            continue;
        }
        $email = trim((string) $email);
        if ($email === '') {
            continue;
        }
        if (preg_match('/^(.*?)\s*<([^>]+)>/', $email, $m)) {
            $out[] = new Address(trim($m[2]), trim($m[1], " \t\"'"));
            continue;
        }
        if (is_email($email)) {
            $out[] = new Address($email);
        }
    }
    return $out;
}

function bedrock_ses_headers($headers): array
{
    $parsed = [
        'from'     => [],
        'cc'       => [],
        'bcc'      => [],
        'reply_to' => [],
        'is_html'  => false,
    ];
    if (empty($headers)) {
        return $parsed;
    }
    if (!is_array($headers)) {
        $headers = preg_split("/\r\n|\n|\r/", (string) $headers) ?: [];
    }
    foreach ($headers as $header) {
        if (!is_string($header) || !str_contains($header, ':')) {
            if (is_string($header) && stripos($header, 'text/html') !== false) {
                $parsed['is_html'] = true;
            }
            continue;
        }
        [$name, $value] = array_map('trim', explode(':', $header, 2));
        switch (strtolower($name)) {
            case 'from':
                $parsed['from'][] = $value;
                break;
            case 'cc':
                $parsed['cc'][] = $value;
                break;
            case 'bcc':
                $parsed['bcc'][] = $value;
                break;
            case 'reply-to':
                $parsed['reply_to'][] = $value;
                break;
            case 'content-type':
                if (stripos($value, 'text/html') !== false) {
                    $parsed['is_html'] = true;
                }
                break;
        }
    }
    return $parsed;
}

function bedrock_ses_mailer(): ?Mailer
{
    static $mailer = false;
    if ($mailer !== false) {
        return $mailer;
    }

    if (!class_exists(Transport::class)) {
        bedrock_ses_log('Symfony Mailer not loaded. Check vendor/autoload.php and: composer require symfony/mailer symfony/amazon-mailer');
        $mailer = null;
        return null;
    }

    $dsn = bedrock_ses_env('MAILER_DSN');
    if ($dsn === '') {
        bedrock_ses_log('MAILER_DSN is empty');
        $mailer = null;
        return null;
    }

    try {
        $mailer = new Mailer(Transport::fromDsn($dsn));
    } catch (\Throwable $e) {
        bedrock_ses_log('Transport::fromDsn failed: ' . $e->getMessage());
        $mailer = null;
    }

    return $mailer;
}

add_filter('pre_wp_mail', function ($short, $atts) {
    $mailer = bedrock_ses_mailer();
    if (!$mailer) {
        bedrock_ses_log('no mailer; leaving wp_mail to core');
        return $short;
    }

    $to      = $atts['to'] ?? '';
    $subject = (string) ($atts['subject'] ?? '');
    $message = (string) ($atts['message'] ?? '');
    $parsed  = bedrock_ses_headers($atts['headers'] ?? []);

    $forcedFrom = bedrock_ses_env(
        'MAILER_FROM',
        bedrock_ses_env('MAIL_FROM_ADDRESS', (string) get_option('admin_email'))
    );
    $forcedName = bedrock_ses_env(
        'MAILER_FROM_NAME',
        bedrock_ses_env('MAIL_FROM_NAME', (string) get_bloginfo('name'))
    );

    if (!is_email($forcedFrom)) {
        bedrock_ses_log('MAILER_FROM is not a valid email: ' . $forcedFrom);
        return false;
    }

    $toAddresses = bedrock_ses_addresses($to);
    if (!$toAddresses) {
        bedrock_ses_log('no valid To addresses: ' . json_encode($to));
        return false;
    }

    // SES: never send From as the form visitor. Keep their address as Reply-To.
    $replyTo = bedrock_ses_addresses($parsed['reply_to']);
    if (!$replyTo && $parsed['from']) {
        $replyTo = bedrock_ses_addresses($parsed['from']);
    }

    try {
        $email = (new Email())
            ->from(new Address($forcedFrom, $forcedName))
            ->to(...$toAddresses)
            ->subject($subject);

        if ($parsed['cc']) {
            $cc = bedrock_ses_addresses($parsed['cc']);
            if ($cc) {
                $email->cc(...$cc);
            }
        }
        if ($parsed['bcc']) {
            $bcc = bedrock_ses_addresses($parsed['bcc']);
            if ($bcc) {
                $email->bcc(...$bcc);
            }
        }
        if ($replyTo) {
            $email->replyTo(...$replyTo);
        }

        $html = $parsed['is_html'] || $message !== wp_strip_all_tags($message);
        if ($html) {
            $email->html($message)->text(wp_strip_all_tags($message));
        } else {
            $email->text($message);
        }

        foreach ((array) ($atts['attachments'] ?? []) as $path) {
            if (is_string($path) && $path !== '' && is_readable($path)) {
                $email->attachFromPath($path);
            }
        }

        $mailer->send($email);
        bedrock_ses_log('sent to ' . implode(', ', array_map(fn (Address $a) => $a->getAddress(), $toAddresses)));
        return true;
    } catch (\Throwable $e) {
        bedrock_ses_log('send failed: ' . $e->getMessage());
        return false;
    }
}, 10, 2);
