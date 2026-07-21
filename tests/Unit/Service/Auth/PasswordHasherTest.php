<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Auth;

use App\Service\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * 04-back-office §1 : « Hachage Argon2id (`PASSWORD_ARGON2ID`, `memory_cost`
 * 64 Mio, `time_cost` 4, `threads` 2), reverifie et reencode a la connexion si
 * les parametres ont change. »
 *
 * Un hachage coute environ 130 ms aux parametres de la spec : ce fichier en
 * compte les appels et n'en fait pas un de plus que necessaire.
 */
final class PasswordHasherTest extends TestCase
{
    private const MOT_DE_PASSE = 'un mot de passe d’atelier, long et prononçable';

    private PasswordHasher $hacheur;

    protected function setUp(): void
    {
        $this->hacheur = new PasswordHasher();
    }

    public function test_le_hachage_emploie_argon2id_aux_parametres_de_la_spec(): void
    {
        // L'empreinte porte ses parametres en clair : c'est ce qui permet de
        // les verifier ici, et a needsRehash() de les comparer plus tard.
        $empreinte = $this->hacheur->hash(self::MOT_DE_PASSE);

        $this->assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=2$', $empreinte);
    }

    public function test_le_bon_mot_de_passe_est_reconnu_et_le_mauvais_refuse(): void
    {
        $empreinte = $this->hacheur->hash(self::MOT_DE_PASSE);

        $this->assertTrue($this->hacheur->verify(self::MOT_DE_PASSE, $empreinte));
        $this->assertFalse($this->hacheur->verify('un mot de passe d’atelier, long et prononçabl', $empreinte));
    }

    public function test_deux_hachages_du_meme_mot_de_passe_different(): void
    {
        // Sel aleatoire : deux comptes au meme mot de passe ne doivent pas
        // porter la meme empreinte, sinon une seule table precalculee les
        // ouvrirait tous les deux.
        $this->assertNotSame(
            $this->hacheur->hash(self::MOT_DE_PASSE),
            $this->hacheur->hash(self::MOT_DE_PASSE),
        );
    }

    public function test_une_empreinte_aux_parametres_perimes_demande_un_reencodage(): void
    {
        // Empreinte litterale, produite hors test avec m=16384,t=2,p=1 : la
        // fabriquer ici couterait un hachage de plus pour rien.
        $ancienne = '$argon2id$v=19$m=16384,t=2,p=1$aHYySDBDSFBsNFRCTHpVWA'
            . '$Rz5HegGIepl3M+AqPKfEJH41SEov1zfsIlqfmL00YSI';

        $this->assertTrue($this->hacheur->needsRehash($ancienne));
    }

    public function test_une_empreinte_courante_ne_demande_aucun_reencodage(): void
    {
        $this->assertFalse($this->hacheur->needsRehash($this->hacheur->hash(self::MOT_DE_PASSE)));
    }

    public function test_une_empreinte_illisible_demande_un_reencodage(): void
    {
        // Une valeur corrompue en base ne doit pas passer pour a jour : elle ne
        // verifiera jamais rien, autant la remplacer a la premiere occasion.
        $this->assertTrue($this->hacheur->needsRehash('n’est pas une empreinte'));
        $this->assertFalse($this->hacheur->verify(self::MOT_DE_PASSE, 'n’est pas une empreinte'));
    }

    public function test_la_comparaison_factice_coute_le_meme_travail_qu_une_vraie(): void
    {
        // 04-back-office §1 : « duree de traitement constante (comparaison
        // factice si l'utilisateur est inconnu) ». Sans elle, une reponse en
        // 2 ms au lieu de 130 ms revele qu'aucun compte ne porte cette adresse,
        // et le formulaire devient un enumerateur de comptes.
        $depart = microtime(true);
        $this->hacheur->verifyDummy();
        $ecoule = (microtime(true) - $depart) * 1000;

        $this->assertGreaterThan(
            20,
            $ecoule,
            'La comparaison factice doit réellement hacher, pas rendre la main aussitôt.',
        );
    }
}
