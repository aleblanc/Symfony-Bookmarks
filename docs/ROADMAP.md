# Roadmap / Idées

Idées de fonctionnalités futures. Non priorisées, non engagées — juste consignées
pour ne pas les perdre. Ajouté le 2026-09-07.

## 1. Analyse de santé des liens (liens morts / 404)

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
