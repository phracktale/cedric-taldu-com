# 10 — Paiement modulaire (proposition, lot futur)

> **Statut : proposition d'architecture, non implémentée.** Décision du
> 2026-07-25 : on documente maintenant, on construira quand un second
> fournisseur sera réellement voulu (YAGNI). Le lot 3 a livré Stripe seul,
> derrière une interface déjà prévue pour cette extension.

Objectif : permettre à l'artiste de **choisir son module de paiement** (Stripe,
PayPal, Paybox, la solution de sa banque…) sans toucher au code, chaque
fournisseur étant un module interchangeable.

## 1. Ce qui existe déjà (la couture est en place)

Tout le tunnel passe par une seule interface :

```php
interface PaymentGateway
{
    public function createCheckout(/* référence, lignes, montants, devise, e-mail, URLs, expiration */): CheckoutSession;
    public function verifyWebhook(string $rawBody, string $signatureHeader): WebhookEvent;
}
```

- `StripeCheckoutGateway` en est l'implémentation réelle ; `FakeGateway` le double de test.
- Le tunnel (calcul serveur des montants, réservation, machine à états, décrément
  de stock, e-mails) ne connaît QUE `PaymentGateway`. Ajouter un fournisseur =
  écrire une classe de plus, sans rien changer d'autre.
- `CheckoutSession` (URL de redirection) et `WebhookEvent` (événement normalisé)
  sont déjà des abstractions neutres : Stripe, PayPal et Paybox font tous du
  **checkout hébergé + redirection + notification serveur**, ce modèle convient.

Autrement dit : le point d'extension du présent document est déjà ouvert.

## 2. Ce qu'il manque pour « l'artiste choisit »

1. **Registre de passerelles** — `PaymentGatewayRegistry` associant un code de
   fournisseur (`stripe`, `paypal`, `paybox`…) à son instance de `PaymentGateway`.
2. **Fournisseur actif** — une entrée `settings` (`payment.provider`) désignant le
   module en service, modifiable au back-office. La sélection est une **liste
   blanche** de codes connus, jamais une valeur libre.
3. **Config par fournisseur** — variables `.env` préfixées par module
   (`STRIPE_*`, `PAYPAL_*`, `PAYBOX_*`), chacune résolue et validée au démarrage
   comme l'est déjà `StripeConfig` (garde-fou test/prod, cohérence des clés).
4. **Webhooks par fournisseur** — une route `POST /webhooks/{provider}` (ou une
   route dédiée par module), chacune vérifiée par le `verifyWebhook` de SA
   passerelle. La signature diffère d'un fournisseur à l'autre (HMAC Stripe,
   vérification d'IPN/webhook PayPal, HMAC Paybox) : cette différence est
   **encapsulée** dans chaque implémentation et n'affecte pas le gestionnaire
   commun, qui ne voit qu'un `WebhookEvent`.
5. **Écran back-office** — choix du fournisseur actif + état de configuration de
   chaque module (clés présentes ? mode test/prod ?), sans jamais afficher de secret.

## 3. Esquisse de conception

```
PaymentGatewayRegistry
  ├─ 'stripe' → StripeCheckoutGateway (StripeConfig)
  ├─ 'paypal' → PaypalCheckoutGateway (PaypalConfig)
  └─ 'paybox' → PayboxCheckoutGateway (PayboxConfig)

ActiveGateway = registry->get( settings['payment.provider'] )   // défaut : 'stripe'

Tunnel (CheckoutService)  ──uses──▶  PaymentGateway (l'actif)
Webhook /webhooks/{provider} ──uses──▶  registry->get(provider)->verifyWebhook(...)
```

- Le `CheckoutService` reçoit la passerelle ACTIVE (résolue depuis le réglage),
  pas un fournisseur en dur.
- Chaque module a son `*Config` de démarrage (sur le modèle de `StripeConfig`) :
  sélection test/prod, refus des clés de production hors production, cohérence.
- Le routage webhook par `{provider}` permet à plusieurs modules de coexister
  (utile pendant une bascule d'un fournisseur à l'autre).

## 4. Invariants de sécurité — inchangés, par module

Chaque module respecte, sans exception, les règles de `06-securite.md` §7 :

- Aucune donnée de carte ne touche le serveur (checkout **hébergé** obligatoire).
- Montants **recalculés côté serveur** à la création de la session.
- Statut `paid` atteignable **uniquement** par le webhook signé du module.
- Idempotence des événements (clé primaire par identifiant d'événement).
- Secrets dans `.env`, jamais dans le dépôt ; garde-fou test/prod au démarrage.
- Un module **non hébergé** (formulaire de carte sur le site) serait refusé :
  il ferait entrer le site dans le périmètre PCI-DSS, hors sujet ici.

## 5. Chemin d'implémentation incrémental

1. Extraire `PaymentGatewayRegistry` + le réglage `payment.provider` (Stripe reste
   le seul enregistré → aucun changement de comportement, tests inchangés).
2. Généraliser la route webhook en `/webhooks/{provider}` (Stripe d'abord).
3. Ajouter l'écran de sélection au back-office.
4. Implémenter un second module (PayPal ou Paybox) : nouvelle classe
   `PaymentGateway` + `*Config` + tests + double, sans toucher au tunnel.

Chaque étape est livrable seule et laisse la suite verte.
