# Roadmap / Idées

Tâches **restantes** uniquement (le livré a été retiré). Non priorisées, non
engagées — consignées pour ne pas les perdre. Mis à jour le 2026-10-04.

## 1. Analyse de santé des liens — reste à faire

> Le gros est livré (commande `app:check-links`, statut de santé par lien, page
> « Liens morts / doublons » `/links/dead` avec nettoyage groupé par domaine et
> détection des doublons par URL normalisée).

**Reste** : proposer de **basculer automatiquement un lien mort vers sa version
archivée** (PDF / capture / single-file / texte lisible déjà stockés), pour que le
contenu reste consultable même quand la source a disparu.

## 2. File « À trier » automatique + catégorisation IA — reste à faire

> Le rangement en masse d'un dossier existant est livré (bouton 🪄🤖 « Ranger avec
> l'IA », agents `organizer_proposer` / `organizer_assigner`, `FolderOrganizer`,
> `CollectionOrganizeController`).

**Reste** : la **file « À trier » automatique** pour les liens sans collection
pertinente (ou tout juste ajoutés via l'extension), avec une **IA qui propose une
collection existante** où ranger chaque lien.

- L'IA reçoit titre + texte lisible + **liste des collections existantes** et renvoie
  la meilleure cible (ou « nouvelle catégorie : … »).
- Réutilise l'infra IA (`symfony/ai-bundle`, agent dédié `categorizer`, commande
  `app:ai-categorize-pending`, statut par lien).
- UI : file « À trier » avec la suggestion IA + boutons Accepter / Choisir un autre
  dossier.

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
voir remonter en suggestions les marque-pages stockés dans l'app.

- Piste principale : **extension WebExtension** utilisant l'API `omnibox` (un
  mot-clé déclencheur, ex. `bm <recherche>`, qui interroge l'API
  `/api/v1/*` et propose les résultats dans la barre d'adresse).
- Alternative sans extension : un **moteur de recherche personnalisé** (OpenSearch
  / mot-clé de recherche Firefox) pointant sur une route de recherche de l'app.
- À creuser : réutiliser le token Bearer existant pour l'auth, et une route API
  de recherche légère renvoyant titre + URL.

## 6. Synchronisation avec les marque-pages Firefox — reste à faire

> Le gros est livré (voir [`plan-api-v2-sync-bidirectionnel.md`](plan-api-v2-sync-bidirectionnel.md)) :
> **API v2** (API Platform, CRUD `Link`/`Collection` + lecture `Tag`/`Dashboard`,
> Swagger `/api/v2/docs`, `Link.updatedAt`, sans auth htpasswd, vault + « à trier »
> exclus) et la **synchro bidirectionnelle desktop** (pull sélectif avec revue +
> suppressions, push ajouts/màj/suppressions, conflits last-write-wins, sélecteur de
> collection cible au push).

**Reste** :
- **Viewer Android** (la synchro native `browser.bookmarks` est desktop-only ;
  l'Android passe par le dashboard — cf. item 8).
- **Dépréciation effective de l'API v1** (envelope `{response:…}` legacy).
- ⚠️ **Valider la logique `browser.bookmarks` dans un vrai navigateur** (jamais
  testée en env de dev faute de Firefox) **avant de se fier aux suppressions**.

## 7. Cache des pages invalidé à chaque changement de contenu

Mettre en place un **cache** (HTTP et/ou fragments Twig) pour accélérer les vues
lourdes sur le Pi (listes de liens, recherche, tableau de bord), avec une
**expiration déclenchée dès qu'un contenu change** plutôt qu'un simple TTL.

- **Ce qui doit invalider le cache** : ajout / modification / suppression d'un
  lien ou d'une collection, fin d'indexation (nouveau PDF / capture / texte
  lisible), fin de **tagging IA**, fin de **résumé IA**. Autrement dit, chaque
  transition de `status` / `aiStatus` / `summaryStatus` vers `done`, et chaque
  écriture d'entité.
- **Mécanisme** : une **clé de version** (ex. `content.version`, un entier ou un
  timestamp stocké en base ou dans un petit cache) bumpée par un **listener
  Doctrine** (`postPersist`/`postUpdate`/`postRemove` sur `Link`, `Collection`,
  `Tag`, `ArchiveAsset`) et par les commandes cron à la fin d'un run qui a
  produit du nouveau contenu. La clé entre dans la **cache key** des fragments →
  toute mutation périme le cache sans purge explicite.
  - Granularité possible : une version **par dashboard** (Perso / Pro) et par
    collection, pour ne pas tout invalider à chaque ajout.
- **Portée** : garder les pages **vault** hors cache (contenu déchiffré en
  session), et exclure ou scoper par session tout ce qui dépend du dashboard
  courant.
- **Pistes techniques** : `Cache-Control`/`ETag` côté HTTP (l'ETag dérive de la
  clé de version), le **HttpCache** de Symfony ou le cache de fragments Twig
  (`{% cache %}` via `symfony/cache`), le tout sur un adaptateur léger
  (filesystem ou APCu) — pas de service externe à installer, cohérent avec la
  contrainte « pas de Docker, ≤ 200 Mo de RAM ».

## 8. Vue « aesthetic » façon AestheticTab (affichage par dossier)

Proposer une **vue visuelle** de type page d'accueil/nouvel onglet inspirée de
[AestheticTab](https://aesthetictab.com/) — design soigné (fond, tuiles, typo) —
pour parcourir ses marque-pages de façon agréable.

- **Idée clé** : afficher les liens **regroupés par dossier / collection**
  (une section ou un « volet » par collection, tuiles cliquables avec
  favicon/aperçu), plutôt qu'une simple liste.
- Réutilise les données déjà là : collections imbriquées + liens (via l'API
  `/api/v2/tree` ou une route Twig dédiée), favicons et aperçus (`ArchiveAsset`)
  déjà stockés.
- **Où l'exposer** (au choix / cumulables) :
  - une **route web** dans l'app (ex. `/board`) — vue Twig, sélecteur de
    dashboard, repli par collection ;
  - le **viewer de l'extension** (page « Mes favoris », cf. item 6) — même
    design, alimenté par l'API, donc compatible **Android** ;
  - éventuellement une **surcharge du nouvel onglet** Firefox via l'extension
    (`chrome_url_overrides.newtab`) pour coller vraiment à l'expérience
    AestheticTab.
- À soigner : responsive (mobile/desktop), fond configurable, recherche rapide,
  repli/dépli des collections, et rester léger (contrainte Pi).

## 9. Historique de navigation partagé entre appareils

> **Analyse de faisabilité faite (2026-10-04)** — non implémenté. « Partagé » =
> **mono-utilisateur, multi-appareils** (mon Android + mes PC/Mac, un seul serveur) :
> un historique de navigation **synchronisé entre mes machines**, affiché comme
> section du dashboard. Reste mono-utilisateur (pas d'entité `User`).

Capturer chaque page visitée sur tous mes appareils, l'envoyer au serveur, et
l'afficher dans une section **« Historique de navigation »** du dashboard.

- **Déblocage clé** : l'API `webNavigation` est dispo **sur Android ET desktop**
  (vérifié dans l'arbre Firefox `release` : `history` et `bookmarks` sont
  desktop-only, mais `webNavigation`, `tabs`, `storage` sont dispo sur Android).
  → **Un seul mécanisme de capture** : `webNavigation.onCompleted` (frame
  principale, http/https) dans `background.js`, identique partout, actif **même
  quand le dashboard n'est pas ouvert**. L'API `history` desktop (`history.search()`)
  ne sert qu'à un **import ponctuel optionnel** de l'historique existant (phase 2).
- **S'appuie sur l'existant** : copie du patron `registerClick` (extension
  `lib/api.js` fire-and-forget `keepalive` → `LinksController::click` →
  `LinkRepository::registerClick` en UPDATE brut qui bypass le chiffrement).
- **À construire** :
  - entité **`Visit`** (url, title, visitedAt, device/source ?) + migration + index
    sur `visitedAt` (aucun concept de visite aujourd'hui, seulement `clickCount` /
    `lastClickedAt` sur `Link`) ;
  - endpoints `POST /api/v1/history` et `GET /api/v1/history?limit=` (même moule
    `AbstractApiController`, envelope `{response:…}`, auth `ApiTokenSubscriber`) ;
  - `background.js` : hook `webNavigation.onCompleted` → filtrage/debounce → POST ;
  - permission manifest **`webNavigation`** (`<all_urls>` + `storage` déjà accordés) ;
  - section dashboard « Historique » via le patron `stripEl` — ⚠️ **ne pas** passer
    par le cache `/tree` (24 h) : c'est un flux, il lui faut son **propre fetch** ;
  - purge/rétention via `simple-cron-scheduler`.
- **Contraintes « navigation complète » (indispensables sur le Pi, ≤ 200 Mo)** :
  rétention + purge cron (ex. 90 j) ; **dedup/debounce** ; **file offline** sur
  mobile (buffer `storage.local`, rejeu au retour réseau).
- **Capture off par défaut** (activée sciemment).

### 9.1 Exclusion des URL confidentielles (le point sensible)

Pas de filtre parfait → **défense en couches**, appliquée **côté extension AVANT
l'envoi** (les secrets ne doivent jamais quitter l'appareil ni entrer dans les logs
serveur). Du plus fiable au plus heuristique :

1. **Contexte (jeter l'événement entier)** : mode privé (`tab.incognito`) ; schémas
   non-`http(s)`, `localhost`, IP LAN, URL du serveur Symfony, `moz-extension:` /
   `about:` ; via `webNavigation.onCommitted`, ignorer les transitions
   `form_submit` (logins / paiements / recherches avec données).
2. **Assainir l'URL (déterministe)** : **supprimer systématiquement le fragment
   `#…`** (tue l'OAuth implicit `#access_token=`) ; retirer le query string — soit
   tout jeter par défaut, soit denylist de paramètres sensibles (`token,
   access_token, id_token, code, auth, apikey, api_key, key, secret, password, pwd,
   sessionid, sid, sig, signature, otp, jwt, bearer, ticket, saml, assertion,
   hash`) ; retirer le `user:pass@` d'une URL basic-auth. Résultat souvent réduit à
   `scheme://host/path`.
3. **Domaines (liste)** : denylist utilisateur (« ne jamais logger ce domaine ») +
   quelques défauts (banques, `*.paypal.com`, Stripe checkout…). Complément, pas
   une garantie.
4. **Heuristique d'entropie (secrets dans le *chemin*)** : seul recours pour les
   magic links / reset mot de passe (`/reset/<token>`) / liens de partage
   non-devinables. Si un segment ressemble à un token (hex/base64 long, forte
   entropie) → jeter l'entrée. Rate quelques cas, faux positifs possibles.
5. **Vault** : exclure les URL des collections chiffrées (sinon on casse le at-rest).
6. **Contrôle utilisateur & serveur** : capture off par défaut ; UI « supprimer
   cette entrée » / « purger ce domaine » ; rétention courte ; **s'assurer que
   nginx/monolog ne loggent pas l'URL** (sinon le secret fuit dans `access.log`).

**Résidu honnête** : les liens de partage non-devinables sans token reconnaissable
ne sont détectables que par l'heuristique d'entropie — jamais à 100 %. D'où
l'importance du « off par défaut » + suppression facile + rétention courte.

> Si on construit : **chantier architectural** (nouvelle entité + endpoints +
> capture + UI + migration) → repartir sur une vraie spec.
