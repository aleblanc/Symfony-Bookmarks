"use strict";

/**
 * Tiny runtime i18n. Firefox's native `browser.i18n` follows the browser UI locale
 * and can't be switched from the app, so we roll a minimal string table with an
 * explicit, user-selectable language (options page → `lang` in config).
 *
 * Usage in a page: include api.js + i18n.js, then
 *   await SfbI18n.init();     // reads cfg.lang (or auto-detects)
 *   SfbI18n.apply();          // localizes [data-i18n] / [data-i18n-ph] / [data-i18n-title]
 *   SfbI18n.t("key", {var});  // for dynamic strings
 */
const SfbI18n = (() => {
  const MESSAGES = {
    en: {
      "common.openSettings": "Open settings",

      "dash.heading": "📚 Bookmarks",
      "dash.favorites": "⭐ Favorites",
      "dash.recentClicked": "👆 Recently clicked",
      "dash.mostClicked": "🔥 Most clicked",
      "dash.recentAdded": "🆕 Recently added",
      "dash.searchPlaceholder": "Search… (#tag)",
      "dash.home": "Home",
      "dash.subfolders": "Sub-folders",
      "dash.folderLinks": "Links",
      "dash.openFolder": "Open folder",
      "dash.googleTitle": "Search Google",
      "dash.saveTitle": "Save current page",
      "dash.refreshTitle": "Refresh",
      "dash.settingsTitle": "Settings",
      "dash.results": "Results",
      "dash.noMatch": "No match.",
      "dash.empty": "No bookmarks yet.",
      "dash.links": "links",
      "dash.cache": "Cache",
      "dash.refreshing": "Refreshing…",
      "dash.offline": "Offline — showing cache. {err}",
      "dash.loading": "Loading your bookmarks…",
      "dash.notConfigured": "Not configured yet. Set your server URL and credentials to load your bookmarks.",

      "popup.save": "➕ Save this page",
      "popup.openDashboard": "📚 Open dashboard",
      "popup.pull": "⬇️ Receive (pull)",
      "popup.push": "⬆️ Send (push)",
      "popup.unavailable": "Native bookmark sync needs the bookmarks API, which Firefox for Android does not provide. Use Firefox on desktop for sync — Save & dashboard work here.",
      "popup.lastSync": "Last sync: {date}",
      "popup.neverSynced": "Never synced yet",
      "popup.lastError": "Last error: {err}",

      "save.heading": "➕ Save bookmark",
      "save.url": "URL",
      "save.name": "Title",
      "save.folder": "Folder",
      "save.note": "Note",
      "save.optional": "(optional)",
      "save.close": "Close",
      "save.save": "Save",
      "save.saving": "Saving…",
      "save.saved": "Saved ✓",
      "save.defaultFolder": "— Default (À trier) —",
      "save.failed": "Failed: {err}",
      "save.notConfigured": "Not configured — open the extension settings first.",
      "save.loadFoldersFailed": "Could not load folders: {err}",

      "opt.subtitle": "Point the extension at your server. Everything is stored locally in the browser.",
      "opt.serverUrl": "Server URL",
      "opt.serverUrlHint": "Include the sub-path if the app runs under one (e.g. /bookmarks). No trailing slash needed.",
      "opt.user": "Basic-auth user",
      "opt.password": "Basic-auth password",
      "opt.passwordHint": "Sent as an Authorization: Basic header on every request — no password prompt. Stored unencrypted in the browser profile.",
      "opt.test": "Test connection",
      "opt.testHint": "Test first — it loads the dashboards and collections into the pickers below.",
      "opt.dashboard": "Dashboard to import",
      "opt.dashboardAll": "All dashboards",
      "opt.dashboardHint": "Choose one dashboard, or import them all. Refreshed after a successful Test connection.",
      "opt.importInto": "Import into",
      "opt.menu": "Bookmarks Menu",
      "opt.toolbar": "Bookmarks Toolbar",
      "opt.other": "Other Bookmarks",
      "opt.wrap": "Wrap everything in a “Symfony Bookmarks” folder",
      "opt.wrapHint": "Off = collections are created directly at the chosen root. When importing all dashboards, each keeps its own folder to avoid mixing.",
      "opt.pushFallback": "Fallback collection for root links (push)",
      "opt.pushDefault": "(Default collection)",
      "opt.pushFallbackHint": "On push, each link goes into the Symfony collection matching its Firefox folder. This fallback is used only for links sitting at the Firefox root.",
      "opt.language": "Language",
      "opt.langAuto": "Automatic",
      "opt.save": "Save",
      "opt.savePull": "Save & pull",
      "opt.saved": "Saved.",
      "opt.enterUrl": "Please enter the server URL.",
      "opt.enterUrlFirst": "Please enter the server URL first.",
      "opt.invalidUrl": "Invalid server URL.",
      "opt.testing": "Testing…",
      "opt.connOk": "Connection OK ✓ — dashboards and collections loaded.",
      "opt.testFailed": "Failed: {err}",

      "review.computing": "Computing changes…",
      "review.toAdd": "To add",
      "review.toUpdate": "To update",
      "review.tickAll": "Tick all",
      "review.untickAll": "Untick all",
      "review.apply": "Apply selection",
      "review.nothing": "Nothing.",
      "review.pullHeading": "⬇️ Receive from Symfony Bookmarks",
      "review.pullSubtitle": "Review the changes to apply to your Firefox bookmarks. Untick anything you want to skip.",
      "review.pullDelLabel": "🗑️ To remove from Firefox",
      "review.pushHeading": "⬆️ Send to Symfony Bookmarks",
      "review.pushSubtitle": "Firefox bookmarks not yet in Symfony. Each lands in the collection matching its folder (created if needed). Tick the ones to send (unticked by default).",
      "review.pushDelLabel": "🗑️ To delete in Symfony",
      "review.intoPull": "📁 into: ",
      "review.intoPush": "📁 in Firefox: ",
      "review.was": "was: ",
      "review.moved": "moved: {from} → {to}",
      "review.root": "(root)",
      "review.conflict": "⚠ conflict — Symfony also changed since last sync",
      "review.rootLinksTarget": " Root links → “{name}”.",
      "review.rootLinksDefault": " Root links → default collection.",
      "review.unavailableAndroid": "Firefox for Android does not provide the bookmarks API, so native sync isn't available here. Use Firefox on desktop for bookmark sync.",
      "review.noServer": "No server configured yet — open the extension options first.",
      "review.computeFailed": "Failed to compute changes: {err}",
      "review.applying": "Applying…",
      "review.done": "Done ✓ {created} added, {updated} updated, {deleted} removed{extra}.",
      "review.emptyRemoved": ", {n} empty folder(s) removed",
      "review.failed": "Failed: {err}",
    },
    fr: {
      "common.openSettings": "Ouvrir les réglages",

      "dash.heading": "📚 Marque-pages",
      "dash.favorites": "⭐ Favoris",
      "dash.recentClicked": "👆 Derniers liens cliqués",
      "dash.mostClicked": "🔥 Liens les plus cliqués",
      "dash.recentAdded": "🆕 Derniers liens ajoutés",
      "dash.searchPlaceholder": "Rechercher… (#tag)",
      "dash.home": "Accueil",
      "dash.subfolders": "Sous-dossiers",
      "dash.folderLinks": "Liens",
      "dash.openFolder": "Ouvrir le dossier",
      "dash.googleTitle": "Rechercher sur Google",
      "dash.saveTitle": "Enregistrer cette page",
      "dash.refreshTitle": "Actualiser",
      "dash.settingsTitle": "Réglages",
      "dash.results": "Résultats",
      "dash.noMatch": "Aucun résultat.",
      "dash.empty": "Aucun marque-page.",
      "dash.links": "liens",
      "dash.cache": "Cache",
      "dash.refreshing": "Actualisation…",
      "dash.offline": "Hors ligne — cache affiché. {err}",
      "dash.loading": "Chargement des marque-pages…",
      "dash.notConfigured": "Pas encore configuré. Renseignez l’URL du serveur et vos identifiants pour charger vos marque-pages.",

      "popup.save": "➕ Enregistrer cette page",
      "popup.openDashboard": "📚 Ouvrir le tableau de bord",
      "popup.pull": "⬇️ Recevoir (pull)",
      "popup.push": "⬆️ Envoyer (push)",
      "popup.unavailable": "La synchro native des marque-pages nécessite l’API bookmarks, absente sur Firefox Android. Utilisez Firefox sur ordinateur pour la synchro — l’enregistrement et le tableau de bord fonctionnent ici.",
      "popup.lastSync": "Dernière sync : {date}",
      "popup.neverSynced": "Jamais synchronisé",
      "popup.lastError": "Dernière erreur : {err}",

      "save.heading": "➕ Enregistrer le marque-page",
      "save.url": "URL",
      "save.name": "Titre",
      "save.folder": "Dossier",
      "save.note": "Note",
      "save.optional": "(optionnel)",
      "save.close": "Fermer",
      "save.save": "Enregistrer",
      "save.saving": "Enregistrement…",
      "save.saved": "Enregistré ✓",
      "save.defaultFolder": "— Par défaut (À trier) —",
      "save.failed": "Échec : {err}",
      "save.notConfigured": "Non configuré — ouvrez d’abord les réglages de l’extension.",
      "save.loadFoldersFailed": "Impossible de charger les dossiers : {err}",

      "opt.subtitle": "Pointez l’extension vers votre serveur. Tout est stocké localement dans le navigateur.",
      "opt.serverUrl": "URL du serveur",
      "opt.serverUrlHint": "Incluez le sous-chemin si l’app tourne sous un (ex. /bookmarks). Pas besoin de slash final.",
      "opt.user": "Utilisateur (basic-auth)",
      "opt.password": "Mot de passe (basic-auth)",
      "opt.passwordHint": "Envoyé dans un en-tête Authorization: Basic à chaque requête — aucune invite de mot de passe. Stocké en clair dans le profil du navigateur.",
      "opt.test": "Tester la connexion",
      "opt.testHint": "Testez d’abord — ça charge les tableaux de bord et collections dans les listes ci-dessous.",
      "opt.dashboard": "Tableau de bord à importer",
      "opt.dashboardAll": "Tous les tableaux de bord",
      "opt.dashboardHint": "Choisissez un tableau de bord, ou importez-les tous. Rafraîchi après un test de connexion réussi.",
      "opt.importInto": "Importer dans",
      "opt.menu": "Menu des marque-pages",
      "opt.toolbar": "Barre personnelle",
      "opt.other": "Autres marque-pages",
      "opt.wrap": "Tout regrouper dans un dossier « Symfony Bookmarks »",
      "opt.wrapHint": "Désactivé = les collections sont créées directement à la racine choisie. En important tous les tableaux de bord, chacun garde son dossier pour éviter les mélanges.",
      "opt.pushFallback": "Collection de repli pour les liens à la racine (push)",
      "opt.pushDefault": "(Collection par défaut)",
      "opt.pushFallbackHint": "Au push, chaque lien va dans la collection Symfony correspondant à son dossier Firefox. Ce repli ne sert qu’aux liens à la racine de Firefox.",
      "opt.language": "Langue",
      "opt.langAuto": "Automatique",
      "opt.save": "Enregistrer",
      "opt.savePull": "Enregistrer & recevoir",
      "opt.saved": "Enregistré.",
      "opt.enterUrl": "Veuillez saisir l’URL du serveur.",
      "opt.enterUrlFirst": "Veuillez d’abord saisir l’URL du serveur.",
      "opt.invalidUrl": "URL de serveur invalide.",
      "opt.testing": "Test en cours…",
      "opt.connOk": "Connexion OK ✓ — tableaux de bord et collections chargés.",
      "opt.testFailed": "Échec : {err}",

      "review.computing": "Calcul des changements…",
      "review.toAdd": "À ajouter",
      "review.toUpdate": "À mettre à jour",
      "review.tickAll": "Tout cocher",
      "review.untickAll": "Tout décocher",
      "review.apply": "Appliquer la sélection",
      "review.nothing": "Rien.",
      "review.pullHeading": "⬇️ Recevoir depuis Symfony Bookmarks",
      "review.pullSubtitle": "Vérifiez les changements à appliquer à vos marque-pages Firefox. Décochez ce que vous voulez ignorer.",
      "review.pullDelLabel": "🗑️ À supprimer de Firefox",
      "review.pushHeading": "⬆️ Envoyer vers Symfony Bookmarks",
      "review.pushSubtitle": "Marque-pages Firefox pas encore dans Symfony. Chacun ira dans la collection correspondant à son dossier (créée si besoin). Cochez ceux à envoyer (décochés par défaut).",
      "review.pushDelLabel": "🗑️ À supprimer dans Symfony",
      "review.intoPull": "📁 vers : ",
      "review.intoPush": "📁 dans Firefox : ",
      "review.was": "avant : ",
      "review.moved": "déplacé : {from} → {to}",
      "review.root": "(racine)",
      "review.conflict": "⚠ conflit — Symfony a aussi changé depuis la dernière sync",
      "review.rootLinksTarget": " Liens à la racine → « {name} ».",
      "review.rootLinksDefault": " Liens à la racine → collection par défaut.",
      "review.unavailableAndroid": "Firefox pour Android ne fournit pas l’API bookmarks, la synchro native n’est donc pas disponible ici. Utilisez Firefox sur ordinateur pour la synchro.",
      "review.noServer": "Aucun serveur configuré — ouvrez d’abord les réglages de l’extension.",
      "review.computeFailed": "Échec du calcul des changements : {err}",
      "review.applying": "Application…",
      "review.done": "Terminé ✓ {created} ajout(s), {updated} mise(s) à jour, {deleted} suppression(s){extra}.",
      "review.emptyRemoved": ", {n} dossier(s) vide(s) supprimé(s)",
      "review.failed": "Échec : {err}",
    },
  };

  let lang = "en";

  function detect() {
    try {
      const ui =
        browser.i18n && browser.i18n.getUILanguage ? browser.i18n.getUILanguage() : "en";
      return ui && ui.toLowerCase().startsWith("fr") ? "fr" : "en";
    } catch {
      return "en";
    }
  }

  /** Set the active language; '' / unknown → auto-detect from the browser locale. */
  function setLang(l) {
    lang = MESSAGES[l] ? l : detect();
  }

  async function init() {
    const cfg = await SfbApi.getConfig();
    setLang(cfg.lang || "");
    return lang;
  }

  function t(key, vars) {
    let s = (MESSAGES[lang] && MESSAGES[lang][key]) || MESSAGES.en[key] || key;
    if (vars) {
      for (const k of Object.keys(vars)) s = s.split("{" + k + "}").join(vars[k]);
    }
    return s;
  }

  /** Localize a DOM subtree via data-i18n / data-i18n-ph / data-i18n-title attributes. */
  function apply(root) {
    root = root || document;
    root.querySelectorAll("[data-i18n]").forEach((el) => {
      el.textContent = t(el.getAttribute("data-i18n"));
    });
    root.querySelectorAll("[data-i18n-ph]").forEach((el) => {
      el.setAttribute("placeholder", t(el.getAttribute("data-i18n-ph")));
    });
    root.querySelectorAll("[data-i18n-title]").forEach((el) => {
      el.setAttribute("title", t(el.getAttribute("data-i18n-title")));
    });
  }

  function current() {
    return lang;
  }

  return { init, setLang, t, apply, current, MESSAGES };
})();

if (typeof module !== "undefined") module.exports = SfbI18n;
