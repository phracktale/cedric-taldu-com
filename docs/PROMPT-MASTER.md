# Prompt maître — construction du site cedrictaldu.com

Ce fichier contient **le prompt à coller** dans une session neuve pour lancer la
construction, et les prompts courts de reprise pour les lots suivants.

---

## A. Prompt de démarrage (lot 0)

> Copier tout le bloc ci-dessous.

```
Tu construis le site vitrine et boutique de l'artiste peintre Cédric Taldu.
Le dépôt contient déjà toute la documentation de conception. Tu ne pars pas d'une page
blanche : tu appliques des specs qui font autorité.

## Avant d'écrire la moindre ligne

Lis dans cet ordre, intégralement :
1. CLAUDE.md (racine) — stack, contraintes, règles de travail, Git, environnements
2. docs/ARCHITECTURE.md — couches, arborescence, flux, conventions
3. docs/specs/00-perimetre-et-lexique.md — vocabulaire et périmètre
4. docs/specs/01-modele-de-donnees.md — schéma SQL et invariants
5. docs/specs/06-securite.md — garde-fous obligatoires
6. docs/specs/07-tests-tdd.md et tests/CLAUDE.md — protocole de test
7. docs/specs/08-lots.md — ce que contient le lot 0
8. docs/specs/09-environnements-deploiement.md — préfixe de chemin, Docker, Thor
9. src/CLAUDE.md — conventions de code et interdits
10. Les trois maquettes de maquette/ — elles définissent le design system

Puis lis les specs du périmètre du lot en cours (02, 03, 04 ou 05 selon le lot).

## Ce que tu construis maintenant

Le **lot 0 — Fondations**, tel que défini dans docs/specs/08-lots.md. Rien de plus.
Tu ne commences aucun travail du lot 1 tant que le lot 0 n'est pas terminé et validé.

## Comment tu travailles

**TDD strict, sans exception.** Pour chaque unité de comportement :
1. Tu écris le test qui échoue.
2. Tu l'exécutes et tu me montres qu'il échoue, et pour la bonne raison.
3. Tu écris le minimum de code pour le faire passer.
4. Tu l'exécutes et tu me montres qu'il passe.
5. Tu refactorises sous couverture verte.

Tu ne présentes jamais du code applicatif écrit avant son test. Si tu t'aperçois que tu as
enfreint cette règle, tu le dis et tu reprends.

**Git.** Tu travailles sur une branche nommée selon la nature du travail
(`chore/lot-0-fondations` pour ce lot). Jamais de branche `claude/*`, jamais de commit
direct sur `main`. Commits en français, avec le commit `test(...)` avant le `feat(...)`
correspondant.

**Sécurité.** Chaque règle de docs/specs/06-securite.md a un test dans tests/Security/.
Une règle sans test n'est pas implémentée. Tu n'as pas le droit de considérer une
fonctionnalité terminée sans son test de sécurité.

**Préfixe de chemin.** Le site tourne sous /cedric-taldu en préproduction et à la racine en
production. Aucune URL en dur, jamais, nulle part. Tout passe par UrlGenerator et asset().

**Portée.** Tu n'ajoutes aucune dépendance au-delà de stripe/stripe-php et
phpmailer/phpmailer. Tu n'introduis ni framework, ni ORM, ni moteur de template, ni
bundler JS. Tu n'inventes pas de fonctionnalité absente des specs.

## Quand tu es bloqué ou en désaccord

Si une spec est ambiguë, contradictoire, ou si tu penses qu'une décision est mauvaise :
tu t'arrêtes et tu poses la question, avec ta recommandation et son motif. Tu ne choisis
pas silencieusement. Les points déjà identifiés comme ouverts sont marqués `@decision`
dans les specs — ne les tranche pas seul.

## Ce que tu me rends à la fin du lot

- La liste des fichiers créés, groupée par couche.
- La sortie de `composer test`, `composer stan`, `composer lint`.
- La démonstration que le lot est « fait » selon le critère écrit dans 08-lots.md.
- La liste des écarts éventuels avec les specs, et pourquoi.
- Les questions ouvertes pour le lot suivant.

Commence par me confirmer ta compréhension du lot 0 en dix lignes maximum, puis attaque.
```

---

## B. Prompt de reprise (lots 1 à 8)

```
Poursuis la construction du site Cédric Taldu.

Relis CLAUDE.md, tests/CLAUDE.md, src/CLAUDE.md, docs/specs/06-securite.md,
docs/specs/08-lots.md, ainsi que les specs du périmètre du lot <N> :
<liste des specs concernées>.

Vérifie d'abord que la suite complète est verte sur main et que le lot <N-1> est bien
terminé selon son critère. Puis crée la branche <type>/lot-<N>-<sujet> et construis le
lot <N>, en TDD strict, dans l'ordre : test unitaire du domaine, test d'intégration de la
persistance, test fonctionnel du parcours, test de sécurité de la surface ajoutée.

Arrête-toi et demande dès qu'une spec est ambiguë ou qu'un point `@decision` te bloque.
```

---

## C. Prompt de revue de fin de lot

```
Fais la revue du lot <N> avant fusion, sans rien modifier pour l'instant.

1. Parcours la spec du lot et liste, point par point, ce qui est implémenté, ce qui est
   partiel et ce qui manque.
2. Parcours docs/specs/06-securite.md et, pour chaque règle touchée par ce lot, indique le
   test qui la couvre. Signale toute règle sans test.
3. Cherche les écarts avec src/CLAUDE.md : SQL hors des dépôts, sortie non échappée, accès
   direct aux superglobales, calcul monétaire en flottant, URL en dur, secret dans le code.
4. Vérifie que chaque commit `feat` a son `test` correspondant.
5. Donne-moi une liste de correctifs classée par gravité, sans en appliquer aucun.
```

---

## D. Prompt de recette client (avant preprod)

```
Génère un plan de recette utilisateur pour le lot <N>, destiné à une personne non
technique (l'artiste). Un tableau : action à réaliser, résultat attendu, cas d'erreur à
tester. Couvre le parcours d'achat complet, la saisie d'une œuvre en back-office, et la
publication d'un article. Rédige-le en français simple, sans vocabulaire technique.
```

---

## E. Rappels à ressortir si la session dérive

Formules courtes, à envoyer telles quelles :

| Situation | Rappel |
|---|---|
| Du code arrive sans test | « Tu as écrit du code applicatif sans test préalable. Reprends : montre-moi d'abord le test rouge. » |
| Une dépendance apparaît | « Aucune dépendance hors stripe/stripe-php et phpmailer/phpmailer. Retire-la ou justifie et attends ma validation. » |
| Une URL en dur | « URL en dur détectée. Le site tourne sous /cedric-taldu en preprod. Passe par UrlGenerator. » |
| Du SQL hors dépôt | « SQL en dehors de src/Repository/. Voir src/CLAUDE.md. » |
| Une fonctionnalité non demandée | « Hors périmètre : ce n'est pas dans les specs du lot. Retire-le et note-le comme proposition. » |
| Un `@decision` tranché seul | « Tu as tranché un point marqué @decision. Annule ce choix et pose-moi la question. » |
| Le lot déborde | « Tu déborde sur le lot suivant. Termine et fais valider celui-ci d'abord. » |

---

## F. Ce que ce prompt suppose déjà en place dans le dépôt

- `CLAUDE.md` racine, `src/CLAUDE.md`, `tests/CLAUDE.md`
- `docs/ARCHITECTURE.md`
- `docs/specs/00` à `09`
- `maquette/index.html`, `maquette/boutique-encres.html`,
  `maquette/boutique-fiche-oeuvre.html`

Si l'un de ces fichiers manque, le prompt de démarrage n'a pas assez de contexte : ne pas
lancer la construction avant de l'avoir rétabli.
