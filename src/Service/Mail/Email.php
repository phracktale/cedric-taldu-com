<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\Mail\Exception\InvalidEmail;

/**
 * Un message sortant, valide par construction.
 *
 * L'injection d'en-tetes de messagerie (06-securite §6.6) se ferme ICI, et non
 * au moment de l'envoi : un `\r\n` dans un nom ou un sujet permet d'ajouter un
 * `Bcc:` et de detourner le courrier. Repousser le controle jusqu'a l'envoi
 * suppose qu'on y pense a l'envoi, et c'est ce qu'on oublie.
 *
 * Le corps, lui, DOIT pouvoir contenir des retours a la ligne : ce n'est pas
 * un en-tete.
 */
final class Email
{
    public readonly string $to;
    public readonly string $toName;
    public readonly string $subject;
    public readonly ?string $replyTo;

    public function __construct(
        string $to,
        string $toName,
        string $subject,
        public readonly string $html,
        public readonly string $text,
        ?string $replyTo = null,
    ) {
        $this->to = self::address($to, 'to');
        $this->toName = self::header($toName, 'toName');
        $this->subject = self::requiredHeader($subject, 'subject');
        $this->replyTo = $replyTo === null ? null : self::address($replyTo, 'replyTo');
    }

    private static function address(string $value, string $field): string
    {
        // L'ordre compte : on refuse les caracteres de controle AVANT de
        // valider la forme. FILTER_VALIDATE_EMAIL rejette deja « a@b\r\nc »,
        // mais s'en remettre a ce detail d'implementation serait imprudent
        // pour la regle qui protege du detournement de courrier.
        $clean = self::header($value, $field);

        if (filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidEmail::address($field);
        }

        return $clean;
    }

    private static function requiredHeader(string $value, string $field): string
    {
        $clean = self::header($value, $field);

        if ($clean === '') {
            // Un message sans sujet part directement en indesirable.
            throw InvalidEmail::missing($field);
        }

        return $clean;
    }

    private static function header(string $value, string $field): string
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw InvalidEmail::header($field);
        }

        return trim($value);
    }
}
