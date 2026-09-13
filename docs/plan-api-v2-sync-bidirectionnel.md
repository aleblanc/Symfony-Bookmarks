# Plan — API v2 + synchronisation bidirectionnelle de l'extension Firefox

> Rédigé le 2026-09-13. Document de conception, non implémenté. À faire par
> paliers ; chaque palier reste livrable et testable indépendamment.

## Contexte

- L'extension Firefox (`extension/`) fait aujourd'hui un **pull un sens**
  (Symfony → Firefox), **sans propager les suppressions** : un lien supprimé
  côté Symfony reste dans Firefox après un sync.
- L'API `/api/v1/*` grossit et mélange deux styles : des endpoints hérités de la
  **compat Linkwarden** (enveloppe `{"response": …}`) et nos ajouts (`/tree`,
  `/dashboards`).
- **Contrainte levée** : on **n'a plus besoin de rester compatible Linkwarden**.
  → liberté totale sur le format de l'API. La v2 sera une API REST propre ;
  l'enveloppe `{"response": …}` et la forme Linkwarden de v1 pourront être
  **dépréciées puis retirées** une fois l'extension migrée.

## Décisions (2026-09-13)

- **Pull** : mode **revue** par défaut (liste cochable avant d'appliquer).
- **Conflit** (modifié des deux côtés) : **last-write-wins** — la modification la
  plus récente (via `updatedAt`) gagne.
- **Cases à cocher** : **cochées par défaut**… **sauf** les **ajouts au push**
  (voir ci-dessous), pour ne pas déverser tous les favoris perso dans Symfony.
- **Swagger** : exposé **aussi en prod** (derrière le htpasswd), pas seulement en dev.
- **Périmètre « géré » = le `guidMap`**, pas un dossier : est géré tout bookmark
  dont le GUID Firefox est dans le map. Ça permet l'**import à la racine** de
  Firefox sans jamais toucher aux favoris perso (jamais mappés).

- **Ajouts au push (racine) → option B (décidé)** : le push **liste tous** les
  bookmarks Firefox non mappés dont l'URL est absente de Symfony, mais **décochés
  par défaut** ; l'utilisateur coche ceux qu'il veut réellement envoyer. Pas de
  dossier dédié, pas de risque de déversement automatique.

## Objectifs

1. **Pull (recevoir)** : propager aussi les **suppressions** Symfony → Firefox.
2. **Push (envoyer)** : Firefox → Symfony, avec un **listing des changements**
   (à ajouter / modifier / supprimer) et une **case à cocher par ligne** pour
   choisir ce qu'on envoie.
3. **Deux boutons** dans l'extension : « ⬇️ Recevoir (pull) » et
   « ⬆️ Envoyer (push) ».
4. **API v2** propre (API Platform), **sans authentification** (le htpasswd
   protège déjà tout), avec `GET` (liste + item), `POST`, `PATCH`, `DELETE`, et
   **Swagger / OpenAPI** comme documentation.

---

## Partie A — API v2

### A.1 Choix technologique : **API Platform** (recommandé)

| Critère | API Platform | FOSRestBundle |
|---|---|---|
| CRUD auto depuis les entités | ✅ natif (attributs `#[ApiResource]`) | ❌ tout à la main |
| **Swagger / OpenAPI** | ✅ **fourni** (UI `/api/docs`, schéma `/api/docs.json`) | ❌ nécessite NelmioApiDocBundle en plus |
| Filtres, pagination, tri | ✅ natifs | ❌ à coder |
| Support Symfony 8.x-dev | ✅ actif, moderne | ⚠️ legacy, compat incertaine |
| Empreinte (RAM, deps) | moyenne (Serializer/Doctrine déjà là) | légère mais + Nelmio |
| Sérialisation par groupes | ✅ Symfony Serializer | ✅ (Serializer) |

**Décision : API Platform.** Il livre directement l'exigence Swagger + CRUD avec
le moins de code custom. FOSRestBundle imposerait de recoder la doc et le CRUD.

**Empreinte / Pi (≤ 200 Mo)** : acceptable — ORM et Serializer sont déjà des
dépendances. **Swagger reste activé en prod** (décidé — pratique derrière le
htpasswd). Mitigation légère : laisser API Platform en **JSON simple**
(désactiver JSON-LD/Hydra si on ne s'en sert pas) pour rester sobre.

### A.2 Périmètre v2

- **Base** : `/api/v2`.
- **Ressources** exposées : `Link`, `Collection`, `Tag`, `Dashboard`.
- **Opérations** :
  - `GET /api/v2/links` (collection : pagination + filtres + tri),
  - `GET /api/v2/links/{id}`,
  - `POST /api/v2/links`,
  - `PATCH /api/v2/links/{id}` (⚠️ content-type `application/merge-patch+json`),
  - `DELETE /api/v2/links/{id}`,
  - idem pour `collections` ; `tags` et `dashboards` en **lecture seule** au début.
- **Filtres utiles** (extension) :
  - par dashboard : `?collection.dashboard={id}`,
  - **delta** : `?updatedAfter={iso8601}` (voir A.4),
  - exclure « à trier » : filtre `collection.skipProcessing=false` (ou géré côté serveur par défaut).
- **Auth** : aucune (pas de firewall) — le **htpasswd** en amont protège. L'extension
  envoie `Authorization: Basic …` (déjà en place).
- **CORS** : l'extension a une host-permission → ses `fetch` ne sont pas bloqués
  par CORS. Si un usage navigateur direct l'exige plus tard, ajouter `nelmio/cors-bundle`.
- **Swagger UI** : `/api/v2/docs` (ou `/api/docs`), utile derrière le htpasswd.

### A.3 Impacts sur les entités

- **`Link.updatedAt`** (nouveau, `datetime_immutable`) + listener `PreUpdate` (ou
  `#[ORM\HasLifecycleCallbacks]`) — **indispensable** pour le delta et la
  résolution de conflits. Migration Doctrine à prévoir.
  - Idem `Collection.updatedAt` (utile mais optionnel au début).
- **Groupes de sérialisation** (`link:read`, `link:write`, `collection:read`, …)
  via `#[Groups]` sur les getters/propriétés, pour contrôler finement l'entrée/sortie
  (ne pas exposer les champs vault, `textContent` lourd optionnel, etc.).
- **Vault** : les collections chiffrées doivent être **exclues** de l'API (ou
  renvoyer des placeholders `[locked]`) — filtrer côté `ApiResource` / provider.
- **`skipProcessing`** : exposé (lecture) et exclu par défaut des listes de sync.

### A.4 Endpoint delta (sync efficace)

- Grâce à `updatedAt` : `GET /api/v2/links?updatedAfter=2026-09-10T12:00:00Z`
  renvoie ce qui a changé depuis le dernier sync → évite de tout re-scanner.
- **Suppressions** : un delta classique ne « voit » pas les lignes supprimées.
  Deux options :
  - **(simple)** l'extension garde la liste des `symfonyId` connus (déjà via
    `guidMap`) ; au pull, tout id mappé **absent de la réponse complète** =
    supprimé côté Symfony. Nécessite un pull **complet** (pas delta) pour les
    suppressions → garder `/tree` complet pour ça.
  - **(robuste, plus tard)** une **table de tombstones** (`deleted_links` :
    id + deletedAt) + `GET /api/v2/deletions?since=…`. Plus de travail.
- **Décision** : commencer par l'option simple (pull complet pour les
  suppressions), garder les tombstones en amélioration future.

### A.5 Coexistence v1 / dépréciation

- v1 **reste** le temps de migrer l'extension, puis :
  - migrer l'extension sur v2,
  - **retirer** les endpoints Linkwarden de v1 (plus de contrainte de compat),
  - garder éventuellement `/api/v2/tree` (export complet pratique pour l'import
    initial et la détection de suppressions).
- Mettre à jour `CLAUDE.md` : **supprimer** la mention « compatible Linkwarden »
  comme objectif (caduque).

### A.6 Étapes d'implémentation (Partie A)

1. `composer require api-platform/core`, config `config/packages/api_platform.yaml`
   (préfixe `/api/v2`, formats, pagination, Swagger).
2. `#[ApiResource]` sur `Link` en **lecture seule** (`GET` collection + item),
   groupes de lecture → **valider Swagger** et les filtres.
3. Ajouter `POST`/`PATCH`/`DELETE` + groupes d'écriture + validation (réutiliser
   les `#[Assert]` de l'entité).
4. Ajouter `updatedAt` (+ migration + listener) et le filtre `updatedAfter`.
5. Exposer `Collection` (CRUD) ; `Tag`, `Dashboard` (lecture).
6. Exclure vault + `skipProcessing` (provider/filtre) et écrire un smoke test API.

---

## Partie B — Synchronisation bidirectionnelle (extension)

### B.1 Modèle de réconciliation

- **`guidMap`** (déjà en place) : `symfonyLinkId ↔ firefoxBookmarkGuid`, dans
  `storage.local`.
- **Normalisation d'URL** (déjà en place) : minuscules, `www.` de tête et `/`
  final ignorés — clé de matching.
- **Snapshot du dernier sync** (nouveau) : mémoriser pour chaque lien mappé son
  état connu (`{url, title}`) au dernier sync, pour détecter **ce qui a changé
  côté Firefox** (titre/url) et **ce qui a été supprimé** des deux côtés.
- **Scope géré** : uniquement les bookmarks sous le dossier/racine choisi et/ou
  présents dans `guidMap`. **Ne jamais toucher** aux bookmarks perso hors scope.

### B.2 Pull (recevoir) — Symfony → Firefox, **avec prévisualisation sélective**

Symétrique au push : le pull calcule un **diff** et (mode « revue ») **affiche la
liste avant d'appliquer**, avec une case à cocher par ligne.

1. Récupérer l'arbre complet (`GET /api/v2/tree` ou liste complète).
2. Calculer le **diff** vs l'état Firefox + le snapshot :
   - **➕ Nouveaux** : lien Symfony dont l'URL normalisée est **absente** de
     Firefox → à créer.
   - **✏️ Mis à jour** : lien **mappé** dont le titre/URL a changé **côté
     Symfony** → à mettre à jour dans Firefox.
   - **🗑️ Supprimés** : entrée `guidMap` dont le `symfonyId` **n'apparaît plus**
     → bookmark Firefox à supprimer (**scope géré uniquement**, jamais un favori
     perso hors scope).
3. **Prévisualisation cochable** (voir B.4) : 3 groupes, une case par ligne
   **cochées par défaut** (les suppressions Firefox sont peu risquées : ce ne
   sont que des favoris locaux, Symfony fait foi), bouton « Appliquer la sélection ».
4. Appliquer la sélection dans Firefox, puis mettre à jour `guidMap` + snapshot.
5. Sécurité : jamais de suppression d'un bookmark non mappé / hors scope.

**Deux modes** :
- **Revue** (défaut recommandé) : montre la liste, l'utilisateur coche/décoche
  avant d'appliquer.
- **Auto** (option, sync rapide) : applique sans revue (ajouts + màj cochés,
  suppressions selon le réglage « décochées par défaut »).

### B.3 Push (envoyer) — Firefox → Symfony, **sélectif**

1. Scanner les bookmarks Firefox **du scope géré**.
2. **Diff** contre l'état Symfony + le snapshot :
   - **À ajouter** : bookmark Firefox **sans mapping** et dont l'URL normalisée
     est **absente** de Symfony → `POST /api/v2/links`.
   - **À modifier** : bookmark **mappé** dont `title`/`url` diffère de l'état
     Symfony → `PATCH /api/v2/links/{id}`.
   - **À supprimer** : entrée `guidMap` dont le **bookmark Firefox n'existe plus**
     mais **existe encore côté Symfony** → `DELETE /api/v2/links/{id}`.
3. **UI de sélection** (voir B.4) : trois groupes, une **case à cocher par
   ligne**, bouton « Envoyer la sélection ».
4. Appliquer la sélection via l'API v2, puis **mettre à jour `guidMap`** + snapshot.

### B.4 UI de l'extension

- **Popup** : deux boutons, **chacun ouvre une page de revue** (même composant,
  seule la direction change) —
  - **⬇️ Recevoir (pull)** → page de revue des changements **Symfony → Firefox**.
  - **⬆️ Envoyer (push)** → page de revue des changements **Firefox → Symfony**.
  - (option **Auto** : appliquer sans passer par la revue, cf. B.2 / B.3.)
- **Page de revue** (façon page d'options, **partagée** pull/push) — trois groupes,
  une case par ligne :
  ```
  RECEVOIR DE SYMFONY BOOKMARKS      (ou : ENVOYER VERS SYMFONY BOOKMARKS)

  ➕ À ajouter (3)
    [x] Titre A        https://a.tld/...
    [x] Titre B        https://b.tld/...
    [ ] Titre C        https://c.tld/...
  ✏️ À modifier (1)
    [x] Titre D (titre changé)   https://d.tld/...
  🗑️ À supprimer (2)
    [ ] Titre E        https://e.tld/...
    [x] Titre F        https://f.tld/...

           [ Tout cocher ] [ Tout décocher ]   [ Appliquer la sélection ]
  ```
  - **Cochées par défaut**, **sauf les ajouts au push** (décochés — sinon on
    risque d'envoyer tous les favoris perso vers Symfony ; cf. « Reste à
    confirmer » en tête de doc). Les suppressions **côté pull** (favoris locaux)
    restent cochées ; les suppressions **côté push** (dans Symfony) aussi, mais
    c'est plus sensible — à surveiller.
  - Le même composant sert dans les **deux sens** ; côté **pull** « Appliquer »
    écrit dans Firefox, côté **push** il appelle l'API v2.
  - Choix du **dashboard / collection cible** pour les ajouts (réutilise la config
    existante).

### B.5 Conflits & cas limites

- **Modifié des deux côtés** (même lien changé côté FF **et** côté SF depuis le
  dernier sync) : **last-write-wins** (décidé) — la version dont l'`updatedAt`
  (ou l'horodatage FF `dateGroupModified`) est le plus récent l'emporte. On peut
  tout de même **marquer la ligne « conflit »** dans la revue pour information.
- **Vault** : exclu de la synchro (déjà).
- **`skipProcessing` (« à trier »)** : exclu de l'export (déjà) → ni pull ni push.
- **Bookmarks Firefox hors scope géré** : ignorés au push (ne pas aspirer toute
  la barre de favoris de l'utilisateur sans qu'il le demande).
- **Idempotence** : un re-run sans changement ne doit rien créer/supprimer.

### B.6 Phasage (Partie B)

- **Phase 2a** — Pull **avec diff + prévisualisation cochable** (nouveaux / màj /
  supprimés) et application sélective (B.2 + le composant de revue B.4). Petit,
  gros gain immédiat, et **construit la page de revue réutilisée par le push**.
- **Phase 2b** — Push **ajouts seulement**, réutilisant la page de revue (B.3
  partiel + B.4).
- **Phase 2c** — Push **modifications + suppressions + conflits** (B.3 complet + B.5).

---

## Dépendances entre les parties

- La **Partie B** (push) a besoin des écritures **`POST`/`PATCH`/`DELETE`** de la
  **Partie A**. Donc : livrer A.6 étapes 1-3 **avant** la Phase 2b.
- La **Phase 2a** (pull + suppressions) ne dépend que de la lecture existante →
  peut être faite **tout de suite**, avant même l'API v2.

## Ordre de livraison recommandé

1. **Phase 2a** (pull + suppressions) — sur l'API actuelle, gain immédiat.
2. **API v2 lecture + Swagger** (A.6.1-2).
3. **API v2 écriture** (A.6.3) + `updatedAt` (A.6.4).
4. **Phase 2b** (push ajouts sélectifs) sur v2.
5. **Phase 2c** (push màj/suppr/conflits).
6. Migration extension entièrement sur v2, **dépréciation de v1** + mise à jour
   `CLAUDE.md`.

## Points à trancher avec l'utilisateur

Tous les choix sont **actés** (voir « Décisions » en tête). Le plan est prêt à
être implémenté par paliers, en commençant par la Phase 2a.
