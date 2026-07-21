# 03 — Boutique, panier et paiement

## 1. Ce qui est vendu

| Type de ligne | Source | Quantité | Stock |
|---|---|---|---|
| Œuvre originale | `artworks` avec `status = available` et `price_cents` non nul | toujours 1 | unique, par changement de statut |
| Reproduction | `product_variants` active d'un `product` publié | 1 à 5 | `stock_qty`, décrémenté au paiement |

Devise unique **EUR**. Les montants sont des entiers de centimes du début à la fin.

## 2. Panier

- Le panier vit **en base** (`carts` / `cart_items`), identifié par un jeton aléatoire de
  32 octets stocké dans un cookie `HttpOnly`, `Secure`, `SameSite=Lax`, durée 30 jours.
- **Aucun prix n'est stocké dans le panier.** L'affichage recalcule systématiquement depuis
  le catalogue. Un prix modifié en back-office se reflète immédiatement, et un panier ne
  peut pas contenir un prix périmé.
- Contrôles à chaque affichage et à chaque étape :
  - œuvre passée en `sold` → ligne retirée, message explicite « Cette œuvre a été acquise
    entre-temps » ;
  - variante désactivée ou en rupture → quantité ramenée au stock disponible ou ligne
    retirée, message explicite ;
  - quantité bornée à 5 par ligne pour les reproductions, à 1 pour les originaux.
- Une même œuvre originale ne peut figurer qu'une fois (contrainte d'unicité en base).
- Ajout au panier : POST avec jeton CSRF. En JS, requête `fetch` qui met à jour la pastille
  et affiche une confirmation ; sans JS, POST classique puis redirection vers le panier
  (motif POST-Redirect-GET).
- Purge des paniers inactifs depuis plus de 60 jours par la tâche cron.

## 3. Tunnel de commande

Trois étapes, chacune une URL propre, chacune revalidant tout ce qui précède.

### Étape 1 — `/{locale}/panier`
Récapitulatif éditable, sous-total, et rappel des conditions.

### Étape 2 — `/{locale}/commande`
Formulaire unique :
- Identité : nom, e-mail (confirmé par saisie unique, validé par `FILTER_VALIDATE_EMAIL`
  + rejet de tout CR/LF), téléphone facultatif.
- Mode de remise : **remise en main propre à Amiens** (0 €, sur rendez-vous) ou
  **expédition**.
- Adresse de livraison si expédition, adresse de facturation (« identique » cochée par
  défaut).
- Frais de port calculés côté serveur (§4).
- Case obligatoire d'acceptation des CGV, avec lien.
- Champ de note facultatif (500 caractères max).
- Protection anti-spam : honeypot + délai minimal + limitation par IP.

À la validation :
1. Revalidation complète du panier en transaction.
2. Recalcul du sous-total, du port, de la TVA et du total **depuis la base**.
3. Création de la commande en statut `pending` avec ses lignes figées (libellés, SKU,
   prix unitaires).
4. Pour chaque œuvre originale : passage `available → reserved` avec
   `reserved_until = maintenant + 30 minutes`, sous `SELECT ... FOR UPDATE`.
   Si le statut a changé entre-temps, la transaction est annulée et l'utilisateur revient
   au panier avec un message clair.
5. Création de la session Stripe Checkout, enregistrement de `stripe_session_id`,
   redirection 303 vers l'URL Stripe.

### Étape 3 — `/{locale}/commande/confirmation/{reference}?t={access_token}`
- Page de retour après paiement. Elle **n'accorde jamais rien** : elle lit l'état de la
  commande. Si le webhook n'est pas encore arrivé, elle affiche « paiement en cours de
  confirmation » et s'actualise (rafraîchissement discret toutes les 3 s, 10 essais max).
- `access_token` est comparé en temps constant. Aucune information de commande n'est
  accessible sans lui.

## 4. Frais de port

```
zone = zone dont le tableau countries contient le pays de livraison, sinon WORLD
poids = Σ (poids unitaire × quantité) + emballage forfaitaire (réglage, défaut 250 g)
tarif = premier shipping_rates de la zone dont max_weight_grams >= poids, trié croissant
si tarif.free_above_cents et sous-total >= free_above_cents  →  port = 0
remise en main propre  →  port = 0, adresse de livraison non requise
```
Si aucune tranche ne couvre le poids, la commande n'est pas bloquée : le site affiche
« devis d'expédition sur demande » et propose le formulaire de contact. Cas testé.

## 5. TVA

L'artiste démarre en **franchise en base** (art. 293 B du CGI) et **passera très
probablement à un régime taxé** — dépassement de seuil, option volontaire, ou changement
de statut. Le modèle est donc conçu pour que cette bascule soit un **changement de réglage
daté**, jamais une migration de données ni une reprise de code.

### 5.1 Deux réglages, une date

| Réglage | Valeurs | Effet |
|---|---|---|
| `vat.mode` | `exempt_293b` (défaut) \| `taxed` | Régime courant |
| `vat.taxable_from` | date, nulle par défaut | Date de bascule vers `taxed` |

Une commande passée **avant** `vat.taxable_from` reste en franchise pour toujours ; une
commande passée après est taxée. La détermination se fait à la date de création de la
commande, et le résultat est **figé** dans `orders.vat_mode`. Rejouer une facture de 2026
en 2028 doit produire exactement le même document. Test dédié.

En franchise : `vat_cents = 0` et mention obligatoire sur la commande, la facture et le
récapitulatif : **« TVA non applicable, article 293 B du CGI »**.

### 5.2 Catégories de TVA

Chaque objet vendable porte une `vat_category`. Elle est saisie en back-office, avec une
valeur par défaut déduite du type d'objet.

| Catégorie | Taux | Ce que c'est |
|---|---|---|
| `original_artwork` | **5,5 %** | Œuvre originale entièrement exécutée à la main, vendue par son auteur (art. 278-0 bis I du CGI, taux généralisé au 1ᵉʳ janvier 2025 par l'art. 83 de la LF 2024) |
| `original_print` | **5,5 %** | Estampe, gravure ou lithographie tirée d'une planche **entièrement exécutée à la main par l'artiste**, à l'exclusion de tout procédé mécanique ou photomécanique (art. 98 A ann. III CGI). N'existe pas aujourd'hui au catalogue — la catégorie est prévue pour le jour où l'artiste produit de vraies estampes |
| `standard_goods` | **20 %** | Tout le reste : tirages giclée et impressions numériques, **même signés, numérotés et rehaussés à la main**, encadrement, emballage |

> **Le point à retenir :** un tirage d'art numéroté avec rehaut reste une reproduction
> photomécanique au sens fiscal. Par défaut, les deux types de reproductions du catalogue
> (`limited` et `standard`) sont donc en `standard_goods` à 20 %. Ce choix est
> **discutable pour le rehaut** et doit être confirmé par le comptable de l'artiste ; il se
> corrige par un simple changement de catégorie en back-office, sans développement.

### 5.3 Table des taux, historisée

Les taux ne sont **jamais** en dur dans le code. Ils vivent dans `vat_rates`, avec une
période de validité (voir `01-modele-de-donnees.md` §5). Un changement de taux légal se
traduit par une nouvelle ligne, pas par la modification de l'existante : les commandes
passées gardent le taux qui s'appliquait à leur date.

### 5.4 Prix stockés TTC

`artworks.price_cents` et `product_variants.price_cents` sont le **prix payé par le
client**, toutes taxes comprises. Le HT est dérivé :

```
ht_cents  = round(ttc_cents * 10000 / (10000 + rate_bps))     // arrondi au centime
vat_cents = ttc_cents - ht_cents
```

Conséquence voulue : le passage en régime taxé **ne modifie aucun prix affiché**. L'artiste
décide séparément s'il augmente ses tarifs pour absorber la taxe. L'inverse (stocker du HT)
ferait bouger toutes les étiquettes le jour de la bascule, ce qui est inacceptable pour une
boutique grand public.

Les taux sont exprimés en **points de base entiers** (`rate_bps` : 550, 2000) — jamais en
flottant, jamais en pourcentage décimal.

### 5.5 Frais de port

Les frais de port accessoires suivent le sort des biens transportés. En présence de taux
mixtes dans une même commande, ils sont **ventilés au prorata du HT de chaque ligne**, et
la TVA de chaque fraction est calculée au taux de la ligne correspondante. Les centimes
d'arrondi de la ventilation sont affectés à la ligne au montant le plus élevé, de sorte
que la somme des fractions égale exactement le port total. Test dédié.

### 5.6 Ce qui est figé sur la commande

`orders` : `vat_mode`, `vat_cents`, `total_cents`.
`order_items` : `vat_category`, `vat_rate_bps`, `ht_cents`, `vat_cents`.

Aucun de ces montants n'est jamais recalculé après coup. Une modification ultérieure d'un
taux, d'une catégorie ou du régime n'a **aucun effet rétroactif**.

### 5.7 Pas de surveillance de seuil — décision explicite

Le site **ne suit pas** le seuil de franchise en base et n'alerte pas sur son dépassement.

Motif : la boutique n'est qu'une source de revenus parmi d'autres — ventes en atelier, en
salon, cessions de droits. Un compteur alimenté par les seules commandes du site
afficherait un montant systématiquement inférieur à la réalité et donnerait une **fausse
assurance**. Un indicateur faux est plus dangereux qu'une absence d'indicateur.

Le suivi du seuil relève de la comptabilité de l'artiste, seule à avoir la vue complète.
Le passage en régime taxé est donc **toujours une action manuelle**, décidée hors du site
et appliquée via `vat.mode` + `vat.taxable_from`. Aucune logique du site ne tente de le
déduire, de le suggérer ou de le déclencher.

### 5.8 Implémentation

Tout le calcul est isolé dans `Domain\Order\VatPolicy`, qui reçoit le régime, la date, la
table des taux et les lignes, et retourne la ventilation. Aucun taux et aucune mention
légale n'existe ailleurs dans le code. Cas de test obligatoires :

- franchise : TVA nulle, mention présente, total inchangé ;
- régime taxé, panier à taux unique 5,5 % ; puis à taux unique 20 % ;
- **panier mixte** original 5,5 % + tirage 20 % + port ventilé ;
- commande antérieure à `vat.taxable_from` après bascule : reste en franchise ;
- changement de taux légal : une commande d'avant garde son ancien taux ;
- arrondis : somme des lignes = total, au centime près, sur 1 000 combinaisons générées.

## 6. Stripe

### Intégration
- **Stripe Checkout hébergé**, mode `payment`. Aucune donnée de carte ne touche le serveur.
- Toute la couche passe par `Service\Payment\PaymentGateway` :
  ```php
  interface PaymentGateway {
      public function createCheckout(Order $order, string $successUrl, string $cancelUrl): CheckoutSession;
      public function verifyWebhook(string $rawBody, string $signatureHeader): WebhookEvent;
      public function fetchSession(string $sessionId): RemoteSession;
  }
  ```
  `StripeCheckoutGateway` en production, `FakeGateway` en test. **Aucun test n'appelle le
  réseau.**
- La session Stripe est construite à partir des **montants de la commande en base**, ligne
  par ligne, plus une ligne de port. `client_reference_id = orders.reference`. Les
  métadonnées portent `order_id` et `order_reference`.
- `expires_at` de la session aligné sur `reserved_until` (30 minutes).

### Webhook — `POST /webhooks/stripe`
Route **exemptée de CSRF et de localisation**, jamais mise en cache.

1. Lecture du **corps brut** (`php://input`), avant toute normalisation.
2. Vérification de la signature avec le secret du webhook. Signature invalide → **400**,
   aucun effet, événement journalisé.
3. Insertion dans `stripe_events` (`event_id` en clé primaire). Si la ligne existe déjà avec
   `processed_at` non nul → **200** immédiat, aucun retraitement.
4. Traitement en transaction, selon le type :

| Événement | Effet |
|---|---|
| `checkout.session.completed` avec `payment_status = paid` | Commande `pending → paid`, `paid_at` ; œuvres `reserved → sold` ; `stock_qty` décrémenté ; numéros d'édition attribués ; `editions_sold` incrémenté ; e-mails envoyés |
| `checkout.session.expired` | Commande `pending → cancelled` ; œuvres `reserved → available` |
| `payment_intent.payment_failed` | Commande `pending → failed` ; libération des réservations |
| `charge.refunded` | Commande `→ refunded` ; **aucune** réintégration automatique de stock (décision de l'artiste, faite en back-office) |

5. `processed_at` renseigné, transaction validée, réponse **200**.
6. Toute exception pendant le traitement → transaction annulée, `processed_at` laissé nul,
   réponse **500** pour que Stripe réessaie. L'opération doit rester idempotente au rejeu.

### Attribution des numéros d'édition
Sous verrou de ligne sur `products` :
```sql
UPDATE products SET editions_sold = editions_sold + :q
WHERE id = :id AND editions_sold + :q <= edition_size;
```
Si zéro ligne affectée → édition épuisée : la commande est marquée `paid` malgré tout (le
client a payé), la ligne est signalée en anomalie dans le back-office et un e-mail
d'alerte part vers l'artiste. **On ne perd jamais un paiement encaissé.** Les numéros
attribués sont `editions_sold_avant + 1 … + q`.

## 7. Après la commande

- **E-mail client** (langue de la commande) : récapitulatif, numéro de commande, lien de
  consultation signé, délais, mentions de rétractation.
- **E-mail artiste** : détail de la commande, adresse, lien direct vers la fiche en
  back-office.
- Envoi **SMTP authentifié** via `MailerInterface`, jamais `mail()`. En-têtes construits par
  la bibliothèque ; toute valeur venant de l'utilisateur est nettoyée de ses CR/LF.
- Si l'envoi échoue, la commande **reste payée** : l'échec est journalisé et rejouable
  depuis le back-office. Un e-mail n'est jamais une condition de validité d'une commande.
- Expédition : l'artiste saisit transporteur et numéro de suivi → statut `shipped`,
  `shipped_at`, e-mail au client.

## 8. Règles anti-fraude et intégrité — chacune testée

1. Le client ne transmet **jamais** de prix, de montant, de frais de port ou de total.
   Seuls des identifiants et des quantités transitent.
2. Le total envoyé à Stripe est recalculé au moment de la création de la session.
3. Le statut `paid` ne peut être atteint **que** par le webhook signé. Ni la page de retour,
   ni un paramètre d'URL, ni le back-office ne peuvent le déclencher.
4. Les transitions de statut de commande sont contrôlées par une machine à états explicite ;
   toute transition non prévue lève `InvalidOrderTransition`.
   ```
   pending → paid | failed | cancelled
   paid    → shipped | refunded
   shipped → refunded
   failed | cancelled | refunded → (terminal)
   ```
5. Deux acheteurs qui paient la même œuvre à quelques secondes d'intervalle : le second
   webhook trouve l'œuvre en `sold`, marque la commande `paid` **et** la signale en anomalie
   pour remboursement manuel. Scénario couvert par un test d'intégration concurrent.
6. Les URL de retour Stripe sont construites côté serveur à partir de la configuration,
   jamais depuis une entrée utilisateur.
