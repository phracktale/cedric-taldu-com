<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Catalog\ArtworkStatus;
use App\Domain\Locale;
use App\Domain\Money;
use App\Domain\Order\VatCategory;
use App\Domain\Shop\Cart;
use App\Domain\Shop\CartLine;
use App\Domain\Shop\ItemCatalogue;
use App\Domain\Shop\LineKind;
use App\Domain\Shop\ProcessingMode;
use App\Domain\Shop\PurchasableItem;
use DateTimeImmutable;
use PDO;

/**
 * Persistance du panier et instantane du catalogue achetable.
 *
 * Deux responsabilites dans le meme depot, volontairement : un panier ne vaut
 * rien sans les prix qui lui donnent un montant, et les charger ensemble evite
 * une requete par ligne sur la page la plus rechargee du tunnel.
 *
 * AUCUN PRIX N'EST ECRIT dans cart_items (01-modele §5). Le panier ne retient
 * qu'une identite et une quantite ; le figement n'a lieu qu'a la commande.
 */
final class CartRepository
{
    /** 32 octets aleatoires, en hexadecimal (03-boutique §2). */
    private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    // Le SQL vit dans des constantes et la liste de MARQUEURS passe par un
    // « %s » de sprintf. Concatener une variable a une chaine SQL est interdit
    // sans exception (src/CLAUDE.md), y compris quand la variable ne contient
    // que des marqueurs : la regle vaut par sa simplicite, et SqlLocationTest
    // la fait respecter mecaniquement.

    private const SELECT_ARTWORKS = <<<'SQL'
        SELECT a.id, a.price_cents, a.vat_category, a.weight_grams, a.status,
               a.reserved_until, a.is_published,
               COALESCE(t.title, r.title) AS title
        FROM artworks a
        LEFT JOIN artwork_translations t ON t.artwork_id = a.id AND t.locale = :locale
        LEFT JOIN artwork_translations r ON r.artwork_id = a.id AND r.locale = :reference
        WHERE a.id IN (%s)
        SQL;

    private const SELECT_VARIANTS = <<<'SQL'
        SELECT v.id, v.sku, v.size_label, v.price_cents, v.stock_qty, v.weight_grams,
               v.is_active, p.is_published AS product_published, p.kind, p.processing_mode,
               p.edition_size, p.editions_sold, p.vat_category, a.is_published AS artwork_published,
               COALESCE(t.title, r.title) AS title
        FROM product_variants v
        INNER JOIN products p ON p.id = v.product_id
        INNER JOIN artworks a ON a.id = p.artwork_id
        LEFT JOIN product_translations t ON t.product_id = p.id AND t.locale = :locale
        LEFT JOIN product_translations r ON r.product_id = p.id AND r.locale = :reference
        WHERE v.id IN (%s)
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Ouvre le panier du jeton, ou en cree un neuf.
     *
     * Un jeton inconnu, absent ou mal forme donne un panier NEUF, jamais une
     * erreur : le jeton vient d'un cookie, et repondre differemment selon qu'il
     * existe ou non confirmerait l'existence des paniers des autres.
     */
    public function open(?string $token, Locale $locale): Cart
    {
        $cart = $token !== null && preg_match(self::TOKEN_PATTERN, $token) === 1
            ? $this->findByToken($token)
            : null;

        return $cart ?? $this->create($locale);
    }

    /**
     * Nombre d'articles d'un panier, en LECTURE SEULE.
     *
     * La pastille de l'en-tete l'appelle a chaque page : contrairement a open(),
     * il ne cree jamais de panier — sinon le moindre robot en semerait un par
     * vue. Un jeton absent ou mal forme rend 0 sans toucher la base.
     */
    public function countByToken(?string $token): int
    {
        if ($token === null || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(ci.qty), 0)
               FROM cart_items ci
               JOIN carts c ON c.id = ci.cart_id
              WHERE c.token = :token'
        );
        $statement->execute(['token' => $token]);

        return (int) $statement->fetchColumn();
    }

    private function findByToken(string $token): ?Cart
    {
        $statement = $this->pdo->prepare('SELECT id, token, locale FROM carts WHERE token = :token');
        $statement->execute(['token' => $token]);

        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new Cart(
            (string) $row['token'],
            Locale::tryFrom((string) $row['locale']) ?? Locale::reference(),
            $this->linesOf((int) $row['id']),
        );
    }

    private function create(Locale $locale): Cart
    {
        $token = bin2hex(random_bytes(32));

        $statement = $this->pdo->prepare(
            'INSERT INTO carts (token, locale, created_at, updated_at) VALUES (:token, :locale, NOW(), NOW())'
        );
        $statement->execute(['token' => $token, 'locale' => $locale->value]);

        return Cart::empty($token, $locale);
    }

    /**
     * @return list<CartLine>
     */
    private function linesOf(int $cartId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT kind, artwork_id, variant_id, qty FROM cart_items WHERE cart_id = :cart ORDER BY id ASC'
        );
        $statement->execute(['cart' => $cartId]);

        $lines = [];

        foreach ($statement->fetchAll() as $row) {
            $kind = LineKind::tryFrom((string) $row['kind']);

            if ($kind === null) {
                continue;
            }

            $target = $kind === LineKind::Original ? $row['artwork_id'] : $row['variant_id'];

            if ($target === null) {
                continue;
            }

            $lines[] = new CartLine($kind, (int) $target, (int) $row['qty']);
        }

        return $lines;
    }

    /**
     * Ecrit l'etat du panier.
     *
     * Table rase puis reecriture, dans une transaction. Diffuser ligne a ligne
     * demanderait de comparer l'existant et l'attendu, et c'est exactement le
     * genre de code ou une ligne finit par survivre a son retrait. Un panier
     * compte au plus quelques lignes : le cout est nul.
     *
     * L'operation est donc IDEMPOTENTE — un double envoi de formulaire ou un
     * rechargement ne duplique rien.
     */
    public function save(Cart $cart): void
    {
        $own = !$this->pdo->inTransaction();

        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $id = $this->idOf($cart->token);

            if ($id === null) {
                if ($own) {
                    $this->pdo->commit();
                }

                return;
            }

            $delete = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cart');
            $delete->execute(['cart' => $id]);

            $insert = $this->pdo->prepare(
                'INSERT INTO cart_items (cart_id, kind, artwork_id, variant_id, qty, created_at)
                 VALUES (:cart, :kind, :artwork, :variant, :qty, NOW())'
            );

            foreach ($cart->lines as $line) {
                $insert->execute([
                    'cart' => $id,
                    'kind' => $line->kind->value,
                    'artwork' => $line->kind === LineKind::Original ? $line->targetId : null,
                    'variant' => $line->kind === LineKind::Reproduction ? $line->targetId : null,
                    'qty' => $line->quantity,
                ]);
            }

            // updated_at porte la purge a 60 jours : un panier qu'on vient de
            // modifier ne doit pas etre efface sous les pieds de son visiteur.
            $touch = $this->pdo->prepare('UPDATE carts SET updated_at = NOW() WHERE id = :cart');
            $touch->execute(['cart' => $id]);

            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function idOf(string $token): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM carts WHERE token = :token');
        $statement->execute(['token' => $token]);

        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Purge des paniers inactifs (03-boutique §2, 06-securite §9).
     *
     * Les lignes partent en cascade. Idempotente, comme toute tache appelee par
     * un cron non garanti.
     *
     * @return int Nombre de paniers effaces.
     */
    public function purgeInactiveSince(DateTimeImmutable $before): int
    {
        $statement = $this->pdo->prepare('DELETE FROM carts WHERE updated_at < :before');
        $statement->execute(['before' => $before->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }

    // --------------------------------------------- instantane du catalogue

    /**
     * Ce que le catalogue dit, MAINTENANT, des lignes de ce panier.
     *
     * Deux requetes au plus, jamais une par ligne. Les regles de disponibilite
     * sont evaluees en PHP a partir du DOMAINE — ArtworkStatus::effectiveAt() —
     * et non reecrites en SQL : une seconde version de la regle finirait par
     * diverger de la premiere, et c'est toujours la version oubliee qui vend
     * une piece deja vendue.
     */
    public function catalogueFor(Cart $cart, DateTimeImmutable $now): ItemCatalogue
    {
        $artworkIds = self::targetsOf($cart, LineKind::Original);
        $variantIds = self::targetsOf($cart, LineKind::Reproduction);

        return new ItemCatalogue(
            ...$this->artworkItems($artworkIds, $cart->locale, $now),
            ...$this->variantItems($variantIds, $cart->locale),
        );
    }

    /**
     * @return list<int>
     */
    private static function targetsOf(Cart $cart, LineKind $kind): array
    {
        $ids = [];

        foreach ($cart->lines as $line) {
            if ($line->kind === $kind) {
                $ids[] = $line->targetId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $ids
     * @return list<PurchasableItem>
     */
    private function artworkItems(array $ids, Locale $locale, DateTimeImmutable $now): array
    {
        if ($ids === []) {
            return [];
        }

        [$placeholders, $parameters] = self::inClause($ids);

        // La traduction de reference sert de repli : 05-i18n-seo §3 rend le
        // francais obligatoire et l'anglais facultatif.
        $statement = $this->pdo->prepare(sprintf(self::SELECT_ARTWORKS, $placeholders));
        $statement->execute($parameters + [
            'locale' => $locale->value,
            'reference' => Locale::reference()->value,
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            $status = ArtworkStatus::tryFrom((string) $row['status']) ?? ArtworkStatus::Draft;
            $reservedUntil = $row['reserved_until'] === null
                ? null
                : new DateTimeImmutable((string) $row['reserved_until']);

            $price = $row['price_cents'];

            $items[] = new PurchasableItem(
                kind: LineKind::Original,
                targetId: (int) $row['id'],
                label: (string) ($row['title'] ?? ''),
                sku: null,
                // Une œuvre sans prix n'est pas vendable ; le zero n'est la que
                // pour construire l'objet, et isSellable le neutralise.
                unitPrice: Money::fromCents($price === null ? 0 : (int) $price),
                vatCategory: VatCategory::tryFrom((string) $row['vat_category'])
                    ?? VatCategory::defaultForArtwork(),
                weightGrams: $row['weight_grams'] === null ? null : (int) $row['weight_grams'],
                isSellable: (int) $row['is_published'] === 1
                    && $price !== null
                    && $status->effectiveAt($reservedUntil, $now)->isPurchasable(),
                stockQty: null,
                editionsRemaining: null,
            );
        }

        return $items;
    }

    /**
     * @param list<int> $ids
     * @return list<PurchasableItem>
     */
    private function variantItems(array $ids, Locale $locale): array
    {
        if ($ids === []) {
            return [];
        }

        [$placeholders, $parameters] = self::inClause($ids);

        $statement = $this->pdo->prepare(sprintf(self::SELECT_VARIANTS, $placeholders));
        $statement->execute($parameters + [
            'locale' => $locale->value,
            'reference' => Locale::reference()->value,
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            $editionSize = $row['edition_size'];

            $items[] = new PurchasableItem(
                kind: LineKind::Reproduction,
                targetId: (int) $row['id'],
                // Ce libelle sera FIGE dans order_items : il doit suffire a
                // identifier ce qui a ete vendu des annees plus tard, meme si
                // le catalogue a change entre-temps.
                label: trim(((string) ($row['title'] ?? '')) . ' — ' . (string) $row['size_label'], ' —'),
                sku: (string) $row['sku'],
                unitPrice: Money::fromCents((int) $row['price_cents']),
                vatCategory: VatCategory::tryFrom((string) $row['vat_category'])
                    ?? VatCategory::defaultForProduct(),
                weightGrams: (int) $row['weight_grams'],
                // Vendre la reproduction d'une œuvre que le public ne voit pas
                // reviendrait a publier l'œuvre par la bande.
                isSellable: (int) $row['is_active'] === 1
                    && (int) $row['product_published'] === 1
                    && (int) $row['artwork_published'] === 1,
                stockQty: (int) $row['stock_qty'],
                editionsRemaining: $editionSize === null
                    ? null
                    : max(0, (int) $editionSize - (int) $row['editions_sold']),
                // Circuit : décide du mode d'expédition (Prodigi ou atelier).
                processingMode: ProcessingMode::tryFrom((string) $row['processing_mode'])
                    ?? ProcessingMode::ProdigiAuto,
            );
        }

        return $items;
    }

    /**
     * Liste de MARQUEURS engendree a partir d'un nombre d'elements. Seuls les
     * marqueurs sont interpoles ; les identifiants restent lies.
     *
     * @param list<int> $ids
     * @return array{string, array<string, int>}
     */
    private static function inClause(array $ids): array
    {
        $placeholders = [];
        $parameters = [];

        foreach ($ids as $index => $id) {
            $name = 'id' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        return [implode(', ', $placeholders), $parameters];
    }
}
