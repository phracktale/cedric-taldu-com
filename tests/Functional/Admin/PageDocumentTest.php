<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use App\Core\Csrf;
use App\Core\Response;
use Tests\Support\AdminTestCase;
use Tests\Support\Factory\UserFactory;

/**
 * Document PDF d'une page (retour client du 2026-09-29) : le livret se
 * télécharge. Le PDF est déposé en back-office, rangé hors du webroot et servi
 * à une adresse STABLE (/documents/{code}.pdf) qu'un bouton peut viser — elle
 * ne change pas quand le PDF est remplacé.
 */
final class PageDocumentTest extends AdminTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private int $livret;

    /** @var list<string> */
    private array $temporaires = [];

    protected function setUp(): void
    {
        parent::setUp();

        (new UserFactory($this->pdo))->withEmail('artiste@example.test')->create();
        $this->seConnecter('artiste@example.test');
        $this->livret = (int) $this->pdo->query("SELECT id FROM pages WHERE code = 'booklet'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaires as $fichier) {
            if (is_file($fichier)) {
                unlink($fichier);
            }
        }
        $chemin = $this->pdo->query("SELECT attachment_path FROM pages WHERE code = 'booklet'")->fetchColumn();
        if (is_string($chemin) && is_file($this->rootPath() . '/storage/' . $chemin)) {
            unlink($this->rootPath() . '/storage/' . $chemin);
        }

        parent::tearDown();
    }

    public function test_le_formulaire_de_la_page_accepte_un_pdf(): void
    {
        $corps = $this->requete('GET', '/cedric-taldu/admin/pages/' . $this->livret)->body;

        $this->assertStringContainsString('name="document"', $corps);
        $this->assertStringContainsString('accept="application/pdf"', $corps);
    }

    public function test_un_pdf_depose_se_telecharge_depuis_la_page(): void
    {
        $this->assertSame(302, $this->deposer(self::PDF, 'Livret 2026.pdf')->status);

        $chemin = (string) $this->pdo->query("SELECT attachment_path FROM pages WHERE code = 'booklet'")->fetchColumn();
        $this->assertMatchesRegularExpression('#^documents/[0-9a-f]{32}\.pdf$#', $chemin);
        $this->assertFileExists($this->rootPath() . '/storage/' . $chemin);

        $page = $this->requete('GET', '/cedric-taldu/fr/livret')->body;
        $this->assertMatchesRegularExpression('#href="/cedric-taldu/documents/booklet\.pdf"[^>]*>\s*Télécharger le livret \(PDF\)#', $page);

        $pdf = $this->requete('GET', '/cedric-taldu/documents/booklet.pdf');
        $this->assertSame(200, $pdf->status);
        $this->assertSame('application/pdf', $pdf->header('Content-Type'));
        $this->assertSame('inline; filename="livret.pdf"', $pdf->header('Content-Disposition'));
        $this->assertSame(self::PDF, $pdf->body);

        // L'adresse stable est donnée à l'artiste, pour un bouton de son choix.
        $this->assertStringContainsString('/cedric-taldu/documents/booklet.pdf', $this->requete('GET', '/cedric-taldu/admin/pages/' . $this->livret)->body);
    }

    public function test_un_fichier_qui_n_est_pas_un_pdf_est_refuse(): void
    {
        $reponse = $this->deposer("\x89PNG\r\n\x1a\n pas un pdf", 'livret.pdf');

        $this->assertSame(422, $reponse->status);
        $this->assertStringContainsString('Le document doit être un fichier PDF.', $reponse->body);
        $this->assertNull($this->pdo->query("SELECT attachment_path FROM pages WHERE code = 'booklet'")->fetchColumn() ?: null);
    }

    public function test_retirer_le_document(): void
    {
        $this->deposer(self::PDF, 'livret.pdf');
        $chemin = (string) $this->pdo->query("SELECT attachment_path FROM pages WHERE code = 'booklet'")->fetchColumn();

        $this->postAvecJeton('/cedric-taldu/admin/pages/' . $this->livret, [...$this->champs(), 'document_retirer' => '1']);

        $this->assertFileDoesNotExist($this->rootPath() . '/storage/' . $chemin);
        $this->assertSame(404, $this->requete('GET', '/cedric-taldu/documents/booklet.pdf')->status);
        $this->assertStringNotContainsString('documents/booklet.pdf', $this->requete('GET', '/cedric-taldu/fr/livret')->body);
    }

    public function test_une_page_sans_document_repond_404(): void
    {
        $this->assertSame(404, $this->requete('GET', '/cedric-taldu/documents/booklet.pdf')->status);
        $this->assertSame(404, $this->requete('GET', '/cedric-taldu/documents/inconnu.pdf')->status);
    }

    /**
     * @return array<string, string>
     */
    private function champs(): array
    {
        return ['titre_fr' => 'Livret', 'slug_fr' => 'livret', 'corps_fr' => '<p>Le livret.</p>'];
    }

    private function deposer(string $contenu, string $nom): Response
    {
        $fichier = tempnam(sys_get_temp_dir(), 'pdf');
        $this->assertIsString($fichier);
        file_put_contents($fichier, $contenu);
        $this->temporaires[] = $fichier;

        return $this->requete('POST', '/cedric-taldu/admin/pages/' . $this->livret, post: [
            Csrf::FIELD => $this->jetonCsrf(),
            ...$this->champs(),
        ], files: [
            'document' => [
                'name' => $nom,
                'tmp_name' => $fichier,
                'size' => strlen($contenu),
                'error' => UPLOAD_ERR_OK,
            ],
        ]);
    }
}
