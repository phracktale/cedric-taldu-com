<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Exception\NotFoundException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Shop\ManagedReproductions;
use App\Repository\Admin\ProductAdminRepository;

/**
 * CRUD des reproductions et variantes en back-office (04-back-office, lot 3).
 *
 * Une reproduction est rattachee a une œuvre : sa gestion vit sous
 * /admin/oeuvres/{id}/reproductions. Le controleur reste mince — validation
 * legere, delegation au depot d'admin.
 *
 * Aucune valeur monetaire ne vient telle quelle du formulaire : le prix est
 * saisi en euros et converti en centiers ENTIERS ici.
 */
final class ProductController
{
    public function __construct(
        private readonly \App\Service\View\AdminChrome $chrome,
        private readonly ProductAdminRepository $products,
    ) {
    }

    public function index(Request $request): Response
    {
        $artworkId = self::id($request);

        if (!$this->products->artworkExists($artworkId)) {
            throw new NotFoundException('Œuvre introuvable.');
        }

        return $this->chrome->page($request, 'admin/reproductions/index', [
            'titre' => 'Reproductions',
            'artworkId' => $artworkId,
            'artworkTitle' => $this->products->artworkTitle($artworkId),
            'reproductions' => $this->products->findForArtwork($artworkId),
        ]);
    }

    /**
     * Ajoute automatiquement les tirages gérés (SKU Prodigi) à une œuvre.
     *
     * L'artiste ne saisit qu'un prix par taille : le SKU Prodigi, le cadrage, le
     * libellé de taille et le poids viennent du catalogue en dur. La reproduction
     * (produit standard) est créée à la volée si l'œuvre n'en a pas encore, sans
     * titre à saisir — il est repris de l'œuvre. Idempotent : une taille déjà
     * présente est ignorée par la contrainte d'unicité (createVariant → false).
     */
    public function store(Request $request): Response
    {
        $artworkId = self::id($request);

        if (!$this->products->artworkExists($artworkId)) {
            throw new NotFoundException('Œuvre introuvable.');
        }

        $title = $this->products->artworkTitle($artworkId);
        $title = $title === '' ? 'Tirage d’art' : $title;

        $now = $this->chrome->now();
        $productId = null;
        $created = 0;

        foreach (ManagedReproductions::all() as $tirage) {
            $price = self::eurosToCents($request->input($tirage['field']));

            // Pas de prix (ou prix nul) : la taille n'est pas proposée.
            if ($price === null || $price <= 0) {
                continue;
            }

            // Le produit n'est créé qu'au premier prix valable : pas de
            // reproduction vide si le formulaire est soumis à blanc.
            $productId ??= $this->products->standardProductFor($artworkId, $title, $now);

            $ajoutee = $this->products->createVariant(
                $productId,
                ManagedReproductions::shopSku($artworkId, $tirage['sku']),
                $tirage['size'],
                false,
                $price,
                ManagedReproductions::STOCK,
                $tirage['weight'],
                $tirage['sku'],
                ManagedReproductions::SIZING,
                $now,
            );

            if ($ajoutee) {
                $created++;
            }
        }

        if ($created > 0) {
            $this->audit($request, 'variant.create', $artworkId);
        }

        return $this->backToArtwork($request, $artworkId);
    }

    /**
     * Crée une édition limitée rehaussée pour une œuvre.
     *
     * L'artiste saisit un format, un prix et une taille d'édition — pas de titre
     * (repris de l'œuvre), pas de SKU technique. Le produit naît en circuit
     * manuel (rehaussé/signé/numéroté à l'atelier) : il ne part jamais en
     * impression automatique.
     */
    public function storeLimitedEdition(Request $request): Response
    {
        $artworkId = self::id($request);

        if (!$this->products->artworkExists($artworkId)) {
            throw new NotFoundException('Œuvre introuvable.');
        }

        $format = trim((string) $request->input('format'));
        $price = self::eurosToCents($request->input('prix'));
        $editionSize = self::intOrNull($request->input('taille_edition'));
        $weight = self::intOrNull($request->input('poids'));

        // Format, prix et taille d'édition (≥ 1) obligatoires : sans eux, l'offre
        // est incomplète (contrainte 01-modele ck_edition). On revient sans créer.
        if ($format === '' || $price === null || $price <= 0 || $editionSize === null || $editionSize < 1) {
            return $this->backToArtwork($request, $artworkId);
        }

        $title = $this->products->artworkTitle($artworkId);
        $title = $title === '' ? 'Tirage d’art' : $title;
        $sku = 'CT' . $artworkId . '-EL-' . strtoupper(bin2hex(random_bytes(4)));

        $productId = $this->products->createLimitedEdition(
            $artworkId,
            $title,
            $editionSize,
            $format,
            $price,
            max(0, $weight ?? 500),
            $sku,
            $this->chrome->now(),
        );

        if ($productId !== null) {
            $this->audit($request, 'product.create', $productId);
        }

        return $this->backToArtwork($request, $artworkId);
    }

    public function togglePublication(Request $request): Response
    {
        $productId = self::id($request);
        $artworkId = $this->products->artworkIdOf($productId);

        if ($artworkId === null) {
            throw new NotFoundException('Reproduction introuvable.');
        }

        $this->products->togglePublication($productId, $this->chrome->now());
        $this->audit($request, 'product.publish', $productId);

        return $this->backToArtwork($request, $artworkId);
    }

    public function delete(Request $request): Response
    {
        $productId = self::id($request);
        $artworkId = $this->products->artworkIdOf($productId);

        if ($artworkId === null) {
            throw new NotFoundException('Reproduction introuvable.');
        }

        $this->products->deleteProduct($productId);
        $this->audit($request, 'product.delete', $productId);

        return $this->backToArtwork($request, $artworkId);
    }

    public function storeVariant(Request $request): Response
    {
        $productId = self::id($request);
        $artworkId = $this->products->artworkIdOf($productId);

        if ($artworkId === null) {
            throw new NotFoundException('Reproduction introuvable.');
        }

        $variant = self::variantInput($request);

        if ($variant !== null) {
            $this->products->createVariant(
                $productId,
                $variant['sku'],
                $variant['size'],
                $variant['framed'],
                $variant['price'],
                $variant['stock'],
                $variant['weight'],
                $variant['prodigi_sku'],
                $variant['prodigi_sizing'],
                $this->chrome->now(),
            );
            $this->audit($request, 'variant.create', $productId);
        }

        return $this->backToArtwork($request, $artworkId);
    }

    public function updateVariant(Request $request): Response
    {
        $variantId = self::id($request);
        $productId = $this->products->productIdOfVariant($variantId);
        $artworkId = $productId === null ? null : $this->products->artworkIdOf($productId);

        if ($artworkId === null) {
            throw new NotFoundException('Variante introuvable.');
        }

        $variant = self::variantInput($request);

        if ($variant !== null) {
            $this->products->updateVariant(
                $variantId,
                $variant['sku'],
                $variant['size'],
                $variant['framed'],
                $variant['price'],
                $variant['stock'],
                $variant['weight'],
                $variant['prodigi_sku'],
                $variant['prodigi_sizing'],
                $this->chrome->now(),
            );
            $this->audit($request, 'variant.update', $variantId);
        }

        return $this->backToArtwork($request, $artworkId);
    }

    public function deleteVariant(Request $request): Response
    {
        $variantId = self::id($request);
        $productId = $this->products->productIdOfVariant($variantId);
        $artworkId = $productId === null ? null : $this->products->artworkIdOf($productId);

        if ($artworkId === null) {
            throw new NotFoundException('Variante introuvable.');
        }

        $this->products->deleteVariant($variantId);
        $this->audit($request, 'variant.delete', $variantId);

        return $this->backToArtwork($request, $artworkId);
    }

    // ------------------------------------------------------------ assistance

    /**
     * @return array{sku: string, size: string, framed: bool, price: int, stock: int, weight: int, prodigi_sku: string|null, prodigi_sizing: string}|null
     */
    private static function variantInput(Request $request): ?array
    {
        $sku = trim((string) $request->input('sku'));
        $size = trim((string) $request->input('taille'));
        $price = self::eurosToCents($request->input('prix'));
        $stock = self::intOrNull($request->input('stock'));
        $weight = self::intOrNull($request->input('poids'));

        if ($sku === '' || $size === '' || $price === null || $stock === null || $weight === null) {
            return null;
        }

        $prodigiSku = trim((string) $request->input('prodigi_sku'));

        return [
            'sku' => $sku,
            'size' => $size,
            'framed' => $request->input('encadre') !== null,
            'price' => $price,
            'stock' => max(0, $stock),
            'weight' => max(0, $weight),
            'prodigi_sku' => $prodigiSku === '' ? null : $prodigiSku,
            'prodigi_sizing' => self::prodigiSizing($request->input('prodigi_sizing')),
        ];
    }

    /** Modes de mise à l'échelle Prodigi. Liste close : une valeur inventée serait rejetée par l'API. */
    private const PRODIGI_SIZINGS = ['fillPrintArea', 'fitPrintArea', 'stretchToPrintArea'];

    private static function prodigiSizing(?string $value): string
    {
        $value = $value === null ? '' : trim($value);

        return in_array($value, self::PRODIGI_SIZINGS, true) ? $value : 'fillPrintArea';
    }

    /**
     * Convertit un prix saisi en euros (« 60 » ou « 60,50 ») en centimes
     * entiers. Aucun flottant : on separe la partie entiere des centimes.
     */
    private static function eurosToCents(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], trim($value));

        if (preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }

        $units = (int) $m[1];
        $cents = isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0;

        return $units * 100 + $cents;
    }

    private static function intOrNull(?string $value): ?int
    {
        if ($value === null || preg_match('/^[0-9]+$/', trim($value)) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function backToArtwork(Request $request, int $artworkId): Response
    {
        return RedirectResponse::to($request->basePath . '/admin/oeuvres/' . $artworkId . '/reproductions');
    }

    private function audit(Request $request, string $action, int $entityId): void
    {
        $this->chrome->audit()->record(
            $this->chrome->currentUserId(),
            $action,
            $request,
            'product',
            $entityId,
        );
    }

    private static function id(Request $request): int
    {
        return (int) ($request->attribute('id') ?? '0');
    }
}
