<?php

declare(strict_types=1);

namespace App\Service\Spam;

/**
 * Verdict rendu par le {@see SpamGuard} : une décision et le score qui l'a motivée.
 *
 * Le contrôleur ne prend aucune décision lui-même ; il lit `shouldPersist()`,
 * `shouldNotify()` et `status()`, ce qui garantit qu'un rejet reste bien
 * silencieux et qu'un message signalé n'est jamais notifié.
 */
final class SpamVerdict
{
    private function __construct(
        public readonly SpamDecision $decision,
        public readonly int $score,
        /** Motif technique, pour le journal de sécurité — jamais montré au visiteur. */
        public readonly string $reason,
    ) {
    }

    public static function accept(int $score): self
    {
        return new self(SpamDecision::Accept, $score, 'ok');
    }

    public static function reject(string $reason): self
    {
        return new self(SpamDecision::Reject, 0, $reason);
    }

    public static function flag(int $score): self
    {
        return new self(SpamDecision::Flag, $score, 'heuristics');
    }

    public function isAccepted(): bool
    {
        return $this->decision === SpamDecision::Accept;
    }

    public function isRejected(): bool
    {
        return $this->decision === SpamDecision::Reject;
    }

    /** Un rejet silencieux n'enregistre rien ; accepté et signalé, si. */
    public function shouldPersist(): bool
    {
        return $this->decision !== SpamDecision::Reject;
    }

    /** Seul un message accepté déclenche la notification de l'artiste. */
    public function shouldNotify(): bool
    {
        return $this->decision === SpamDecision::Accept;
    }

    /** Statut à donner au message enregistré. */
    public function status(): string
    {
        return $this->decision === SpamDecision::Flag ? 'spam' : 'new';
    }
}
