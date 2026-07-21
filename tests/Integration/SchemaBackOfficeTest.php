<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use Tests\Support\DatabaseTestCase;

/**
 * Le lot 2 est le PREMIER consommateur des tables `users` et `audit_log`, posees
 * au lot 0 et jamais eprouvees jusqu'ici. Ce fichier verifie a la fois ce que la
 * migration 0003 ajoute et les invariants de 0001 dont l'authentification depend
 * reellement — un compte en double, un verrouillage qui ne persiste pas ou un
 * code de secours rejouable sont des defauts de securite, pas des details.
 */
final class SchemaBackOfficeTest extends DatabaseTestCase
{
    // ------------------------------------------------------- comptes (0001)

    public function test_deux_comptes_ne_peuvent_pas_partager_une_adresse(): void
    {
        // Sans cette contrainte, findByEmail() rendrait un compte arbitraire
        // parmi deux, et le verrouillage d'un seul laisserait l'autre ouvert.
        $this->creerCompte('artiste@example.test');

        $this->expectException(PDOException::class);

        $this->creerCompte('artiste@example.test');
    }

    public function test_un_compte_est_administrateur_et_intact_par_defaut(): void
    {
        $id = $this->creerCompte('artiste@example.test');

        $ligne = $this->lire('SELECT role, failed_attempts, locked_until, totp_secret FROM users WHERE id = ' . $id);

        $this->assertSame('admin', $ligne['role']);
        $this->assertSame(0, $ligne['failed_attempts']);
        $this->assertNull($ligne['locked_until']);
        $this->assertNull($ligne['totp_secret']);
    }

    public function test_un_role_hors_de_la_liste_est_refuse(): void
    {
        // L'ENUM ferme la porte cote base : `editor` ne peut pas devenir
        // `superadmin` par une ecriture maladroite.
        $this->creerCompte('artiste@example.test');

        $this->expectException(PDOException::class);

        $this->pdo->exec("UPDATE users SET role = 'superadmin' WHERE email = 'artiste@example.test'");
    }

    // -------------------------------------------------------- audit (0001)

    public function test_supprimer_un_compte_conserve_ses_traces_d_audit(): void
    {
        // ON DELETE SET NULL : le journal d'audit se conserve trois ans
        // (06-securite §9). Le depart d'un utilisateur n'efface pas ce qu'il a
        // fait, sinon supprimer son compte suffirait a effacer ses traces.
        $id = $this->creerCompte('artiste@example.test');
        $this->tracer($id, 'artwork.update');

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $id);

        $ligne = $this->lire('SELECT user_id, action FROM audit_log ORDER BY id DESC LIMIT 1');

        $this->assertNull($ligne['user_id']);
        $this->assertSame('artwork.update', $ligne['action']);
    }

    // ------------------------------------------------- codes de secours (0003)

    public function test_la_table_des_codes_de_secours_est_creee(): void
    {
        $this->assertContains('user_backup_codes', $this->tables());
    }

    public function test_un_code_de_secours_disparait_avec_son_compte(): void
    {
        $id = $this->creerCompte('artiste@example.test');
        $this->creerCodeDeSecours($id, 'a');

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $id);

        $this->assertSame(0, $this->compter('user_backup_codes'));
    }

    public function test_le_meme_code_ne_peut_pas_etre_enregistre_deux_fois_pour_un_compte(): void
    {
        // Un doublon rendrait le code utilisable deux fois : la marque « utilise »
        // ne porterait que sur l'une des deux lignes.
        $id = $this->creerCompte('artiste@example.test');
        $this->creerCodeDeSecours($id, 'a');

        $this->expectException(PDOException::class);

        $this->creerCodeDeSecours($id, 'a');
    }

    public function test_un_code_de_secours_est_inutilise_a_la_creation(): void
    {
        $id = $this->creerCompte('artiste@example.test');
        $this->creerCodeDeSecours($id, 'a');

        $ligne = $this->lire('SELECT used_at FROM user_backup_codes LIMIT 1');

        $this->assertNull($ligne['used_at']);
    }

    // --------------------------------------------------- bande methode (0003)

    public function test_une_rubrique_porte_un_texte_de_methode_par_langue(): void
    {
        // 02-front-public §5 et 04-back-office §3 : la bande basse de la page
        // rubrique est un texte libre, traduisible, facultatif.
        $this->pdo->exec('INSERT INTO categories (id, created_at, updated_at) VALUES (1, NOW(), NOW())');

        $this->pdo->prepare(
            'INSERT INTO category_translations (category_id, locale, slug, title, method_text)
             VALUES (1, :locale, :slug, :title, :method)'
        )->execute([
            'locale' => 'fr',
            'slug' => 'encres',
            'title' => 'Encres',
            'method' => '<p>Encre de Chine, papier de chanvre.</p>',
        ]);

        $ligne = $this->lire('SELECT method_text FROM category_translations WHERE category_id = 1');

        $this->assertSame('<p>Encre de Chine, papier de chanvre.</p>', $ligne['method_text']);
    }

    public function test_le_texte_de_methode_est_facultatif(): void
    {
        $this->pdo->exec('INSERT INTO categories (id, created_at, updated_at) VALUES (1, NOW(), NOW())');
        $this->pdo->exec(
            "INSERT INTO category_translations (category_id, locale, slug, title)
             VALUES (1, 'fr', 'encres', 'Encres')"
        );

        $ligne = $this->lire('SELECT method_text FROM category_translations WHERE category_id = 1');

        $this->assertNull($ligne['method_text']);
    }

    // --------------------------------------------------- nom d'origine (0003)

    public function test_un_media_conserve_le_nom_du_fichier_televerse(): void
    {
        // 06-securite §5.5 : le nom d'origine n'est JAMAIS employe pour nommer
        // le fichier sur le disque ; il est conserve comme metadonnee affichee,
        // echappee, pour que l'artiste retrouve son image.
        $this->pdo->prepare(
            'INSERT INTO media (storage_path, public_basename, mime, width, height, bytes, checksum,
                                original_name, created_at)
             VALUES (:path, :base, :mime, 2400, 3200, 1024, :checksum, :nom, NOW())'
        )->execute([
            'path' => 'storage/uploads/ab/cd/abcd.jpg',
            'base' => 'abcd',
            'mime' => 'image/jpeg',
            'checksum' => str_pad('a', 64, '0'),
            'nom' => 'Articulation — scan atelier.JPG',
        ]);

        $ligne = $this->lire('SELECT original_name FROM media LIMIT 1');

        $this->assertSame('Articulation — scan atelier.JPG', $ligne['original_name']);
    }

    // ------------------------------------------------------------- outils

    private function creerCompte(string $email): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, created_at, updated_at)
             VALUES (:email, :hash, :nom, NOW(), NOW())'
        )->execute(['email' => $email, 'hash' => 'peu-importe', 'nom' => 'Cédric Taldu']);

        return (int) $this->pdo->lastInsertId();
    }

    private function creerCodeDeSecours(int $utilisateur, string $graine): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_backup_codes (user_id, code_hash, created_at) VALUES (:id, :hash, NOW())'
        )->execute(['id' => $utilisateur, 'hash' => str_pad($graine, 64, '0')]);
    }

    private function tracer(int $utilisateur, string $action): void
    {
        $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, action, created_at) VALUES (:id, :action, NOW())'
        )->execute(['id' => $utilisateur, 'action' => $action]);
    }

    private function compter(string $table): int
    {
        // Nom de table litteral, jamais une entree utilisateur.
        $statement = $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function lire(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement);

        /** @var array<string, mixed>|false $ligne */
        $ligne = $statement->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($ligne);

        return $ligne;
    }
}
