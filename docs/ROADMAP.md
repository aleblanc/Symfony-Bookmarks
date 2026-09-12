# Roadmap / Idées

Idées de fonctionnalités futures. Non priorisées, non engagées — juste consignées
pour ne pas les perdre. Ajouté le 2026-09-07.

## Réalisé (hors roadmap initiale)

Fonctionnalités livrées le 2026-09-12, en plus des idées ci-dessous :

- **Import par collage de liste** : sur la page Importer, un champ « Ou collez une
  liste de liens » qui extrait chaque URL http/https/ftp d'une liste libre
  (numérotée, à puces, CSV `id, url`, ou une par ligne) vers un dossier nommé
  (défaut `Import <date>`, réutilisé ou créé).
- **Actions groupées sur les résultats de recherche** : case à cocher par
  résultat (+ « tout sélectionner ») et barre d'opérations pour déplacer le lot
  dans un dossier ou le supprimer (avec confirmation).
- **Page « Liens morts / doublons »** : voir la section 1 ci-dessous.
- **Bannière du total de liens** en haut de la vue « Tous les liens ».
- **Timeout PDF configurable** (`APP_PDF_TIMEOUT`, défaut 10 s) pour éviter qu'une
  page lourde bloque tout un run d'indexation.
- **Compteur de liens en attente** affiché au début de `app:index-pending`.
- **Délais IA séparés et paramétrables** : `APP_AI_CALL_DELAY_MS` (tags, 2 s) et
  `APP_AI_SUMMARY_CALL_DELAY_MS` (résumés, 6 s).

## 1. Analyse de santé des liens (liens morts / 404)

> **Implémenté (2026-09-12)** : commande `app:check-links` + statut de santé par
> lien (`health = unknown | alive | dead | error`) avec date du dernier contrôle.
> Page **« Liens morts / doublons »** (`/links/dead`) qui liste :
> - les liens **morts** (404/410),
> - les liens **injoignables** (échec réseau : DNS, timeout, TLS, connexion
>   refusée), avec un nettoyage groupé « X liens pour domaine.tld → Tout
>   supprimer » dès qu'un domaine revient ≥ 2 fois,
> - les **doublons** : liens pointant vers la même URL une fois normalisée
>   (minuscules, `/` final et `www.` de tête ignorés), regroupés en volets
>   repliables montrant le dossier de chaque copie pour choisir laquelle
>   supprimer.
>
> L'archive locale (PDF / capture / single-file / texte lisible) reste accessible
> depuis chaque carte. **Reste à faire** : proposer de basculer automatiquement
> un lien mort vers sa version archivée.

Vérification **ponctuelle** (à la demande, ou périodique via cron) de l'état d'un
lien : requête HTTP, si le lien est HS (404, timeout, DNS, 5xx…) le marquer comme
« mort ».

- Nouveau statut de lien (ex. `health = ok | dead | unknown`) + date du dernier
  contrôle.
- Commande `app:check-link-health` (cron optionnel, en plus de l'archivage).
- **Section « Archivage »** dans l'UI : lister les liens morts, avec accès à
  l'archive locale (PDF / capture / single-file / texte lisible déjà stockés) —
  le contenu reste consultable même si la source a disparu.
- Idée liée : proposer de basculer automatiquement un lien mort vers sa version
  archivée.

## 2. Section « À trier » + catégorisation IA

> **Partiellement implémenté (2026-09-09)** : rangement en masse d'un dossier
> existant via le bouton 🪄🤖 « Ranger avec l'IA » (agents `organizer_proposer`
> / `organizer_assigner`, service `FolderOrganizer`, contrôleur
> `CollectionOrganizeController`). Page unique : propositions de sous-dossiers en
> haut (réessayables sans relancer l'analyse), liens proposés + application en
> dessous. Reste à faire : la file « À trier » automatique pour les liens sans
> collection.

Un **dossier / statut spécial « À trier »** pour les liens sans collection
pertinente (ou tout juste ajoutés via l'extension), avec une **IA qui tourne et
propose un nom de catégorie existante** (une collection déjà créée) où ranger le
lien.

- L'IA reçoit le titre + texte lisible + la **liste des collections existantes**
  et renvoie la meilleure collection cible (ou « nouvelle catégorie : … »).
- Réutilise l'infra IA existante (`symfony/ai-bundle`, agent dédié type
  `categorizer`, commande `app:ai-categorize-pending`, statut par lien).
- UI : file d'attente « À trier » avec la suggestion IA + boutons Accepter /
  Choisir un autre dossier.

## 3. Section « Observer les nouveaux articles » (veille / alertes)

Un **statut « à surveiller »** sur certains liens : on **observe la page** et on
**alerte** en cas de changement (nouvel article, nouvel épisode, nouvelle actu…).

- Snapshot périodique (hash du contenu lisible / d'un sélecteur, flux RSS si
  dispo) et comparaison avec le dernier état.
- Commande `app:watch-pending` (cron) qui détecte les changements et lève une
  alerte (badge dans l'UI, log canal dédié, éventuellement notification).
- Section « Veille » listant les liens surveillés + leur dernier changement.
- Cas d'usage : pages de séries (nouvel épisode), blogs, pages produit, listes.

## 4. Téléchargement vidéo via yt-dlp

Quand un lien pointe vers une vidéo (YouTube, site pour adultes, etc.), **proposer
de télécharger la vidéo via `yt-dlp`**.

- Détection du domaine → si supporté par yt-dlp, afficher un bouton
  « Télécharger la vidéo ».
- Archiver dédié (façon `ScreenshotArchiver`/`PdfArchiver`) : détection du binaire
  `yt-dlp` (comme `ChromeDetector`), lancement en tâche de fond, stockage du
  fichier dans `var/archives/<id>/` comme un `ArchiveAsset` (nouveau `KIND_VIDEO`),
  servi par la route d'archive existante.
- Attention : usage perso / légalité selon les sources ; garder ça opt-in et local.

## 5. Accès aux liens depuis la barre d'adresse de Firefox

Trouver une solution pour **incorporer les liens de Symfony-Bookmarks dans la
barre d'adresse de Firefox** — pouvoir taper quelques lettres dans l'URL bar et
voir remonter en suggestions les marque-pages stockés dans l'app (par exemple
via une extension dédiée).

- Piste principale : **extension WebExtension** utilisant l'API `omnibox` (un
  mot-clé déclencheur, ex. `bm <recherche>`, qui interroge l'API
  `/api/v1/*` et propose les résultats dans la barre d'adresse).
- Alternative sans extension : un **moteur de recherche personnalisé** (OpenSearch
  / mot-clé de recherche Firefox) pointant sur une route de recherche de l'app.
- À creuser : réutiliser le token Bearer existant pour l'auth, et une route API
  de recherche légère renvoyant titre + URL.

## 6. Synchronisation avec les marque-pages Firefox

Une **extension WebExtension** qui synchronise les marque-pages du navigateur avec
la base de Symfony-Bookmarks : elle utilise l'**API `bookmarks` de Firefox** d'un
côté et l'**API `/api/v1/*`** (Bearer) de l'autre.

- **Ne pas** toucher `places.sqlite` directement : les extensions sont
  sandboxées (pas d'accès fichier), et écrire dans ce fichier pendant que Firefox
  tourne risque de corrompre le profil. L'API `bookmarks`
  (`getTree`, `create`, `update`, `move`, `remove` + événements
  `onCreated/onRemoved/onChanged/onMoved`) est l'abstraction propre à utiliser.
- **Vrai défi = la réconciliation**, pas la lecture :
  - identité : table de correspondance `firefoxGuid ↔ symfonyLinkId` dans
    `storage.local`, complétée par un match sur **URL normalisée** (réutiliser la
    normalisation des doublons : minuscules, `/` final et `www.` ignorés) ;
  - arbre de dossiers Firefox ↔ **collections imbriquées**, avec choix du
    **dashboard** cible (Perso / Pro) ;
  - suppressions et déplacements (propager ou détacher ?), conflits (« dernier
    gagne » via timestamp, ou Symfony fait autorité) ;
  - **exclure les collections chiffrées (vault)** de la synchro.
- **Approche par paliers** : (1) miroir un sens périodique (via `alarms`) sans
  propager les suppressions ; (2) l'autre sens (Symfony → Firefox dans un dossier
  dédié) ; (3) temps réel via les événements + suppressions + conflits.
- Côté Symfony (surtout déjà là) : exposer un **timestamp de modification** par
  lien dans l'API et, à terme, un **endpoint de delta** (« ce qui a changé depuis
  tel instant ») pour éviter de tout re-scanner à chaque passage.
