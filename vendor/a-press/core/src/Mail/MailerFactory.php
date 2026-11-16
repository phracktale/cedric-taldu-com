<?php

declare(strict_types=1);

namespace APress\Core\Mail;

use APress\Core\ClockInterface;
use APress\Core\Env;
use APress\Core\RandomInterface;
use InvalidArgumentException;

/**
 * Choix de l'envoi selon l'environnement :
 *
 *   APRESS_SMTP_HOST        vide ou absent : aucun courriel ne part
 *   APRESS_SMTP_PORT        587 par défaut
 *   APRESS_SMTP_ENCRYPTION  starttls (défaut), tls ou none
 *   APRESS_SMTP_USER / APRESS_SMTP_PASSWORD   facultatifs
 *   APRESS_MAIL_FROM        expéditeur, obligatoire dès qu'un serveur est configuré
 */
final class MailerFactory
{
    public static function fromEnv(Env $env, ClockInterface $clock, RandomInterface $random): Mailer
    {
        $host = trim($env->getOptional('APRESS_SMTP_HOST', '') ?? '');
        if ($host === '') {
            return new NullMailer();
        }
        $from = self::from($env);
        if (!Message::isAddress($from)) {
            throw new InvalidArgumentException('APRESS_MAIL_FROM doit être une adresse valide quand APRESS_SMTP_HOST est défini.');
        }

        return new SmtpMailer(
            new StreamSmtpTransport(),
            $host,
            (int) ($env->getOptional('APRESS_SMTP_PORT', '587') ?? '587'),
            $env->getOptional('APRESS_SMTP_ENCRYPTION', 'starttls') ?? 'starttls',
            $env->getOptional('APRESS_SMTP_USER', '') ?? '',
            $env->getOptional('APRESS_SMTP_PASSWORD', '') ?? '',
            substr($from, strrpos($from, '@') + 1),
            $clock,
            $random,
        );
    }

    /** Adresse d'expédition, vide quand rien n'est configuré. */
    public static function from(Env $env): string
    {
        return trim($env->getOptional('APRESS_MAIL_FROM', '') ?? '');
    }
}
