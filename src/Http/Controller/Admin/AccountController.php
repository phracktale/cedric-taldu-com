<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Config;
use App\Core\Exception\NotFoundException;
use App\Core\RandomInterface;
use App\Core\Request;
use App\Core\Response;
use App\Repository\UserRepository;
use App\Service\Auth\AdminSession;
use App\Service\Auth\BackupCodes;
use App\Service\Auth\Totp;
use App\Service\View\AdminChrome;

/**
 * Compte de l'utilisateur connecte : activation et retrait du second facteur.
 *
 * 04-back-office §1 : la 2FA est optionnelle mais implementee des ce lot. Sans
 * ecran d'enrolement, `totp_secret` serait une colonne que seul un developpeur
 * pourrait remplir — ce que le critere de fin du lot interdit explicitement.
 *
 * Le secret n'est ecrit en base QU'APRES un code juste. L'ecrire des sa
 * proposition enfermerait dehors un artiste qui a mal recopie la cle dans son
 * application : il ne pourrait plus se connecter pour la corriger.
 *
 * Pas de QR code engendre : il demanderait soit une dependance, soit deux cents
 * lignes de matrices de correction d'erreurs. La cle est affichee en groupes
 * lisibles et le lien `otpauth:` est fourni — toutes les applications
 * d'authentification acceptent la saisie manuelle.
 */
final class AccountController
{
    public function __construct(
        private readonly AdminChrome $chrome,
        private readonly AdminSession $session,
        private readonly UserRepository $users,
        private readonly Totp $totp,
        private readonly BackupCodes $backupCodes,
        private readonly RandomInterface $random,
        private readonly Config $config,
    ) {
    }

    public function showTwoFactor(Request $request): Response
    {
        $user = $this->currentUser();

        if ($user->hasTwoFactor()) {
            return $this->chrome->page($request, 'admin/compte/deux-facteurs', [
                'titre' => 'Double facteur',
                'actif' => true,
                'codesRestants' => $this->users->countUnusedBackupCodes($user->id),
            ]);
        }

        // Un secret est propose a chaque affichage : recharger la page pendant
        // l'enrolement doit donner une cle utilisable, pas celle d'avant que
        // l'artiste n'a peut-etre pas fini de recopier.
        $secret = $this->totp->generateSecret($this->random);
        $this->session->proposeTotpSecret($secret);

        return $this->chrome->page($request, 'admin/compte/deux-facteurs', [
            'titre' => 'Double facteur',
            'actif' => false,
            'secret' => $secret,
            'secretLisible' => implode(' ', str_split($secret, 4)),
            'uri' => $this->totp->provisioningUri($secret, $user->email, $this->issuer()),
        ]);
    }

    public function enableTwoFactor(Request $request): Response
    {
        $user = $this->currentUser();
        $secret = $this->session->proposedTotpSecret();

        if ($user->hasTwoFactor() || $secret === null) {
            return $this->showTwoFactor($request);
        }

        $code = trim($request->input('code') ?? '');

        if ($this->totp->accept($secret, $code, $this->chrome->now(), null) === null) {
            return $this->chrome->page($request, 'admin/compte/deux-facteurs', [
                'titre' => 'Double facteur',
                'actif' => false,
                'erreur' => 'Ce code ne correspond pas. Vérifiez l’heure de votre téléphone, puis réessayez.',
                'secret' => $secret,
                'secretLisible' => implode(' ', str_split($secret, 4)),
                'uri' => $this->totp->provisioningUri($secret, $user->email, $this->issuer()),
            ], 422);
        }

        $codes = $this->backupCodes->generate($this->random);

        $this->users->updateTotpSecret($user->id, $secret);
        $this->users->replaceBackupCodes(
            $user->id,
            array_map($this->backupCodes->hash(...), $codes),
            $this->chrome->now(),
        );
        $this->session->clearProposedTotpSecret();
        $this->chrome->audit()->record($user->id, 'auth.two_factor_enabled', $request);

        // Les codes en clair n'existent qu'ici : la base n'en garde que les
        // empreintes. C'est le seul et unique moment ou ils sont montres.
        return $this->chrome->page($request, 'admin/compte/codes-de-secours', [
            'titre' => 'Codes de secours',
            'codes' => $codes,
        ]);
    }

    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->currentUser();

        $this->users->updateTotpSecret($user->id, null);
        $this->users->replaceBackupCodes($user->id, [], $this->chrome->now());
        $this->chrome->audit()->record($user->id, 'auth.two_factor_disabled', $request);

        return $this->showTwoFactor($request);
    }

    private function issuer(): string
    {
        $host = parse_url($this->config->url, PHP_URL_HOST);

        return is_string($host) ? $host : 'cedrictaldu.com';
    }

    private function currentUser(): \App\Domain\Admin\AdminUser
    {
        // AuthGuard a deja resolu l'utilisateur : son absence ici serait un
        // defaut de cablage, pas une situation a rattraper en silence.
        return $this->session->currentUser()
            ?? throw new NotFoundException('Aucun compte connecté.');
    }
}
