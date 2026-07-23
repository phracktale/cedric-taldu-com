<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Core\View;
use App\Domain\Locale;
use App\Repository\PersistedOrder;

/**
 * E-mails de commande (03-boutique §7).
 *
 * Deux destinataires a chaque commande : le client, dans la langue de SA
 * commande, et l'artiste, toujours en francais.
 *
 * Les messages sont rendus par des GABARITS de `templates/`, jamais construits
 * en PHP. Ce n'est pas une preference : c'est ce qui les place sous la
 * surveillance d'EscapingTest, qui exige que toute valeur affichee passe par un
 * helper d'echappement. Un client de messagerie qui rend le HTML executerait ce
 * qu'un gabarit laisserait passer.
 *
 * Ce service N'ATTRAPE PAS les echecs d'envoi : c'est a l'appelant d'appliquer
 * 03-boutique §7 — la commande reste payee, l'echec est journalise et
 * rejouable. Un e-mail n'est jamais une condition de validite d'une commande.
 */
final class OrderMailer
{
    public function __construct(
        private readonly View $view,
        private readonly MailerInterface $mailer,
        private readonly string $artistEmail,
        private readonly string $artistName,
    ) {
    }

    public function sendConfirmation(PersistedOrder $order, string $consultationUrl): void
    {
        $this->mailer->send($this->customerMessage(
            $order,
            'emails/order-confirmation',
            self::subject($order, 'confirmation'),
            $consultationUrl,
        ));

        $this->mailer->send($this->artistMessage($order));
    }

    public function sendShipped(PersistedOrder $order, string $consultationUrl): void
    {
        $this->mailer->send($this->customerMessage(
            $order,
            'emails/order-shipped',
            self::subject($order, 'shipped'),
            $consultationUrl,
        ));
    }

    private function customerMessage(
        PersistedOrder $order,
        string $template,
        string $subject,
        string $consultationUrl,
    ): Email {
        $data = [
            'order' => $order,
            'locale' => $order->locale,
            'consultationUrl' => $consultationUrl,
            'legalMention' => $order->legalMention(),
            'strings' => self::strings($order->locale),
        ];

        return new Email(
            to: $order->customerEmail,
            toName: $order->customerName,
            subject: $subject,
            html: $this->view->render($template, $data, layout: 'layouts/email'),
            text: self::toText($this->view->render($template, $data)),
            // L'acheteur repond a l'artiste, pas a une boite sans retour.
            replyTo: $this->artistEmail,
        );
    }

    private function artistMessage(PersistedOrder $order): Email
    {
        $data = [
            'order' => $order,
            // L'artiste lit toujours en francais (04-back-office).
            'locale' => Locale::Fr,
            'strings' => self::strings(Locale::Fr),
        ];

        return new Email(
            to: $this->artistEmail,
            toName: $this->artistName,
            subject: sprintf('Nouvelle commande %s', $order->reference),
            html: $this->view->render('emails/order-artist', $data, layout: 'layouts/email'),
            text: self::toText($this->view->render('emails/order-artist', $data)),
            replyTo: $order->customerEmail,
        );
    }

    private static function subject(PersistedOrder $order, string $kind): string
    {
        return match ($order->locale) {
            Locale::Fr => match ($kind) {
                'shipped' => sprintf('Votre commande %s a été expédiée', $order->reference),
                default => sprintf('Votre commande %s', $order->reference),
            },
            Locale::En => match ($kind) {
                'shipped' => sprintf('Your order %s has shipped', $order->reference),
                default => sprintf('Your order %s', $order->reference),
            },
        };
    }

    /**
     * Partie texte, derivee du HTML.
     *
     * Un message sans partie texte part plus facilement en indesirable, et
     * reste illisible pour qui refuse le HTML.
     */
    private static function toText(string $html): string
    {
        $withBreaks = preg_replace('#</(p|h1|h2|h3|li|tr|div)>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $collapsed = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        $lines = array_map(trim(...), explode("\n", $collapsed));

        return trim(implode("\n", array_filter($lines, static fn (string $l): bool => $l !== '')));
    }

    /**
     * Libelles des gabarits.
     *
     * Ils vivent ici, en PHP, et non dans les gabarits : le lot 5 les
     * remplacera par le Translator sans toucher au HTML.
     *
     * @return array<string, string>
     */
    private static function strings(Locale $locale): array
    {
        return match ($locale) {
            Locale::Fr => [
                'greeting' => 'Merci pour votre commande',
                'intro' => 'Votre commande est enregistrée. Voici son récapitulatif.',
                'order' => 'Commande',
                'item' => 'Article',
                'quantity' => 'Quantité',
                'amount' => 'Montant',
                'subtotal' => 'Sous-total',
                'shipping' => 'Frais d’expédition',
                'total' => 'Total',
                'shippingAddress' => 'Adresse de livraison',
                'pickup' => 'Remise en main propre à Amiens, sur rendez-vous',
                'consult' => 'Consulter ma commande',
                'withdrawal' => 'Vous disposez de 14 jours à compter de la réception pour '
                    . 'exercer votre droit de rétractation. Les frais de retour restent à votre charge.',
                'shippedIntro' => 'Votre commande vient de partir.',
                'carrier' => 'Transporteur',
                'tracking' => 'Numéro de suivi',
                'customer' => 'Client',
                'edition' => 'N° d’édition',
            ],
            Locale::En => [
                'greeting' => 'Thank you for your order',
                'intro' => 'Your order has been recorded. Here is a summary.',
                'order' => 'Order',
                'item' => 'Item',
                'quantity' => 'Quantity',
                'amount' => 'Amount',
                'subtotal' => 'Subtotal',
                'shipping' => 'Shipping',
                'total' => 'Total',
                'shippingAddress' => 'Shipping address',
                'pickup' => 'Collection in person in Amiens, by appointment',
                'consult' => 'View my order',
                'withdrawal' => 'You have 14 days from delivery to exercise your right of '
                    . 'withdrawal. Return shipping is at your expense.',
                'shippedIntro' => 'Your order is on its way.',
                'carrier' => 'Carrier',
                'tracking' => 'Tracking number',
                'customer' => 'Customer',
                'edition' => 'Edition no.',
            ],
        };
    }
}
