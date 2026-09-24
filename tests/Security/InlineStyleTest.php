<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScanner;

/**
 * Aucun attribut `style` dans les gabarits servis sous CSP (revue du 2026-09-24).
 *
 * La CSP (`style-src 'self' 'nonce-…'`) bloque les attributs style : écrits
 * dans un gabarit, ils ne s'appliquent JAMAIS dans le navigateur, sans erreur
 * visible côté serveur. C'est ainsi que le rapport d'aspect des visuels a été
 * ignoré en silence. Les styles passent par site.css/admin.css, ou par le
 * <style> à nonce de la mise en page. Les e-mails, lus hors du site, sont exclus.
 */
final class InlineStyleTest extends TestCase
{
    public function test_aucun_gabarit_web_ne_porte_d_attribut_style(): void
    {
        $fautifs = [];

        foreach (SourceScanner::files('templates') as $chemin => $source) {
            if (str_starts_with($chemin, 'templates/emails/') || $chemin === 'templates/layouts/email.php') {
                continue;
            }

            if (preg_match('/\sstyle\s*=\s*["\']/i', $source) === 1) {
                $fautifs[] = $chemin;
            }
        }

        $this->assertNotSame([], SourceScanner::files('templates'), 'Aucun gabarit trouvé : le test ne vérifierait rien.');

        $this->assertSame([], $fautifs, 'Attribut style bloqué par la CSP dans : ' . implode(', ', $fautifs));
    }
}
