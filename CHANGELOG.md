# Journal des modifications

## 2026101101 — correctifs Moodle 5.3 (`qtype_webwork` 2.0.1-moodle53)

Trouvés au premier essai sur une vraie instance Moodle 5.3 (Windows, Apache/PHP 8.3) reliée à un
vrai renderer (PG `4b3e564`, le commit sur lequel l'image est épinglée), dans un test interactif
avec des problèmes réels de `private/` et de `Library/`.

- **Aucune formule ne s'affichait** — PG écrit ses formules en `<script type="math/tex">`
  (`PGbasicmacros.pl`, mode « MathJax »), forme propre à MathJax v2. MathJax 4 ne lit plus ces
  balises : l'énoncé s'affichait sans ses formules (« un rayon de . »), sans aucune erreur. L'essai
  de la version 2026101100 utilisait du HTML déjà écrit avec `\(...\)` et ne pouvait pas le voir.
  `response_parser::transform()` convertit désormais ces balises en `\(...\)` et `\[...\]`
  (option `texdelimiters`, page de test et aperçu de la banque de questions). Le contenu des
  bulles de prévisualisation reste traité par `apply_fix_popover_content()`.
- **jQuery UI jamais installé** — le module AMD insérait les scripts du renderer pendant que
  RequireJS expose `define.amd` : jQuery UI (UMD) s'y déclarait en module anonyme, refusé
  (« Mismatched anonymous define() module »). `define.amd` devient un accesseur qui ne répond
  `undefined` qu'aux scripts insérés par le plugin (`document.currentScript` porte la marque
  `data-qtype-webwork-script`). Un premier essai qui masquait `define.amd` le temps du chargement a
  été écarté : les bibliothèques UMD de Moodle chargées en même temps (Mustache, pour
  `core/templates`) ne s'enregistraient plus (`prefetchTemplates` / `get_strings` indéfinis).
- `amd/build/` recompilé avec les réglages de `grunt amd` de Moodle 5.3 (Babel 7.17.5,
  `preset-env` 7.16.11, mêmes cibles, Terser) ; la même chaîne redonne à l'octet près le build de
  2026101100 à partir de son code source.
- **Fuite du résultat en mode différé avec le renderer francisé** — `strip_correctness()` ne
  retirait les paragraphes de score (« You received a score… », « …partial credit… ») qu'en
  anglais. Avec l'image `fr` et `language=fr-CA`, ils arrivent désormais en français (« Vous avez
  obtenu un résultat… », « …une partie des points… ») et restaient visibles avant la fin du test.
  Les deux langues sont maintenant reconnues.
- Note : la langue d'une question est figée dans sa tentative par le renderer (le `problemJWT`
  chiffré du `sessionJWT` reprend les paramètres du premier rendu). Changer le réglage
  `language` ne touche que les tentatives commencées ensuite.
- **LaTeX brut dans la bulle de prévisualisation des réponses textuelles** (choix « A » d'un menu,
  mots) — PG les prévisualise par `\verb␟A␟`, délimité par le caractère de contrôle U+001F, que
  libxml supprime dès `parse()` : MathJax recevait `\verbA`, un `\verb` jamais refermé. Les
  expressions algébriques n'utilisent pas `\verb`, d'où un défaut limité au texte.
  `response_parser::fix_verb_delimiters()` remplace ces délimiteurs par un caractère visible absent
  du texte (`\verb|A|`) avant toute analyse DOM.
- **Zoom au double-clic** — même principe qu'en 4.5 (`zoom: DoubleClick`), mais MathJax 4 exige en
  plus, par défaut, la touche Alt : le double-clic seul ne faisait plus rien. Aucune touche
  requise désormais (`alt`, `ctrl`, `cmd`, `shift` à `false`, sauf réglage contraire du filtre
  MathJax). Le zoom de MathJax 4 s'ouvre dans une fenêtre (« MathJax Zoomed Expression »).
- **Rendu plus net des formules** — en mode « local », sortie CHTML (`tex-mml-chtml.js`, vraies
  polices woff2) au lieu du SVG, plus pâle et un peu flou à la taille du texte. Retenue
  seulement si la vérification CORS du module AMD réussit (les polices l'exigent) ; sinon repli
  sur le SVG, comme avant.
- **Aperçu vide dans l'éditeur MathView** — en mode PGML (celui de PG), MathView écrit l'aperçu
  sous ses boutons en AsciiMath, entre accents graves. Le plugin 4.x chargeait
  `TeX-MML-AM_CHTML` (« AM » = AsciiMath) ; les composants `tex-mml-*` de MathJax 4 n'incluent
  pas cette entrée. `input/asciimath` est maintenant chargé, avec les accents graves pour
  délimiteurs (sauf configuration contraire du filtre MathJax).
- 12 tests autonomes ajoutés (58/58).

## 2026101100 — version Moodle 5.3 (`v2.0-moodle53-beta`, branche `MOODLE_503_STABLE`)

Les trois plugins passent à `requires = 2026100500` (Moodle 5.3). Aucun changement de base de
données ; les API PHP utilisées (moteur de question, comportements, banque de questions) sont
identiques entre 4.5 et 5.3 — vérifié sur le code source des deux versions. Tout ce qui change
se passe dans le navigateur :

- **MathJax 4** — le filtre MathJax de Moodle 5 charge MathJax 4 et remplace `window.MathJax` par
  sa propre configuration : le MathJax v2 que le plugin chargeait sous 4.x aurait été écrasé.
  Le plugin obtient désormais MathJax par le chargeur du filtre (`filter_mathjaxloader/loader`),
  une seule instance par page, partagée avec les formules de Moodle (`classes/mathjax.php`).
  « Hébergé localement » désigne la copie du renderer (`pg_files/mathjax-v4/`, image v1.1+), en
  **sortie SVG** : ses glyphes arrivent en scripts, sans polices web que le navigateur refuserait
  de charger d'une autre origine sans CORS.
- **Synthèse vocale de MathJax et CORS** — essai réel (Edge, MathJax 4.1.3, renderer simulé sur
  une autre origine) : les dictionnaires de la synthèse vocale sont chargés par `fetch()`, bloqué
  sans en-tête CORS, et ce blocage empêche le rendu de **toutes** les formules. Le renderer
  amont envoie `Access-Control-Allow-Origin: *` par défaut (`CORS_ORIGIN` de
  `render_app.conf`) ; le module AMD le vérifie (requête `HEAD` sur un dictionnaire) avant de
  charger MathJax et, s'il manque, désactive l'enrichissement sémantique et active le MathML
  d'assistance (lu directement par les lecteurs d'écran). Aucun réglage à faire. L'aperçu en
  iframe, outil d'enseignant, utilise toujours cette configuration prudente.
- **Essai de bout en bout** (Edge sans interface) : module AMD compilé, chargeur MathJax du filtre
  de Moodle 5.3 compilé depuis ses sources, Bootstrap 5.3.8, MathJax 4.1.3 et `feedback.js` /
  `details-accordion.js` de PG 2.19 servis par un renderer simulé, avec et sans CORS : formules,
  bulle de rétroaction (ouverture, prévisualisation rendue, fermeture par le X et par l'icône) et
  indice fonctionnels, un seul MathJax chargé, aucune erreur JavaScript.
- **Bootstrap 5 partagé** — Moodle 5 utilise lui aussi Bootstrap 5 : la copie du renderer
  (`bootstrap.bundle`) n'est plus chargée dans les pages de Moodle, où deux copies traiteraient
  chacune les clics sur les `data-bs-toggle` (menus ouverts puis refermés aussitôt). Celle de
  Moodle est prêtée aux scripts de WeBWorK (`window.bootstrap`).
- **Bulles de rétroaction** — le thème Boost de Moodle 5 initialise lui-même tout
  `[data-bs-toggle="popover"]` (déclenchement au focus, contenu assaini) et rouvre une bulle
  cliquée depuis un écouteur global. `response_parser` remplace cet attribut par
  `data-qtype-webwork-toggle` sur les icônes de WeBWorK (option `popovertoggle`), qui restent
  gérées par le plugin seul, comme en 4.5.
- **Module AMD `qtype_webwork/question`** — remplace les scripts en ligne de 4.x (popovers,
  verrouillage de MathQuill, déclenchement de MathJax). Il charge les scripts du renderer dans
  l'ordre (`async = false`) après MathJax : les correctifs `patch_mathview.pl` /
  `patch_graphtool.pl` testent `MathJax.startup` dès leur exécution.
- **Aperçu en iframe** (`ajax/preview.php`) — MathJax 4 aussi, même configuration.
- **Banques de questions** (`import.php`) — depuis Moodle 5.0, une banque est une activité
  (`cmid`) ; une adresse ne portant que `courseid` mène à la gestion des banques du cours, comme
  dans le noyau.
- Classes Bootstrap 4 restantes (`ml-2`, `mr-2`) remplacées par leurs équivalents Bootstrap 5.
- Tests autonomes : substitut de `get_string()` (les tests s'arrêtaient sur `strip_correctness()`
  depuis la version 2026072842) et cinq tests de l'option `popovertoggle`.

**Limites connues** : non validée sur une vraie instance Moodle 5.3 (d'où l'étiquette bêta) ;
le support des applets (`ww_applet_support.js`, qui attend `DOMContentLoaded`) ne s'initialise
pas, les scripts de WeBWorK étant chargés après la page.

## qtype_webwork + qbank_webworkimport 2026072850 (aperçu dans la banque de questions)

Nouvelle colonne **« Aperçu WeBWorK »** dans la banque de questions : les questions WeBWorK ont un
champ « texte de la question » vide (leur énoncé vit dans le `.pg`), la colonne native n'affichait
donc rien pour elles.

- **Chargement à la demande**, question par question : charger d'emblée toute une catégorie
  exigerait un appel au renderer par question à chaque affichage de page. Rien n'est mis en cache,
  chaque ouverture reflète l'état réel du fichier `.pg`.
- **Rendu en iframe** : un problème WeBWorK s'accompagne de feuilles de style et de scripts
  (MathJax, GraphTool, MathView, accordéon des indices). Un premier essai injectait le seul HTML
  dans la page — résultat inerte : formules non rendues, indices sans effet au clic, GraphTool
  absent. L'iframe isole chaque aperçu et charge ses propres ressources, sans conflit avec celles
  de Moodle.
- **Dimensions réglables** par l'administrateur (hauteur 350 px, largeur 500 px par défaut). La
  hauteur est fixe et la fenêtre déborde de sa colonne — deux choix délibérés, expliqués dans le
  README : le redimensionnement automatique faisait sauter les lignes et laissait un vide après
  avoir replié une solution ; élargir la colonne repousserait toutes les suivantes pour rien.

## qtype_webwork 2026072842-2026072843 + qbehaviour_webwork 2026072842

- **Correctif de fuite d'information (mode différé)** — l'icône de rétroaction était bien
  neutralisée, mais le TITRE de la bulle de prévisualisation affichait toujours
  « Correct »/« Erroné », révélant la correction avant la fin de la tentative. Le traitement
  précédent ne remplaçait que les libellés anglais exacts, sans couvrir les traductions du
  renderer francisé. Le titre entier est désormais remplacé par un libellé neutre, sur tous les
  attributs concernés (`data-bs-title`, `title`, `aria-label`).
- **Message de tentatives restantes : logique corrigée** — le message était masqué en même temps
  que la rétroaction générale, alors que le nombre maximal de tentatives est une contrainte
  mécanique : la question se bloquait quand même, sans avertissement. Il s'affiche maintenant dès
  qu'un `maxtries` est configuré, quelle que soit la visibilité de la rétroaction.
- **Verrouillage « solution atteinte » corrigé** (qbehaviour_webwork) — le comportement bloquait
  la question au seuil `showsolutionsafter` même quand la rétroaction générale était masquée,
  donc même quand la solution ne serait jamais montrée : l'étudiant se retrouvait bloqué sans
  explication. Ce verrou ne s'applique désormais que si la solution sera réellement affichée,
  ce qui rend le décompte annoncé et le blocage effectif cohérents.

## qtype_webwork 2026072837-2026072841

- **Mode de notation déduit du test** — le champ « mode de notation » disparaît du formulaire de
  question : « Rétroaction à posteriori » donne le mode différé, tout autre comportement donne
  l'interactif. Le mode est figé dans l'état de la tentative, donc stable même si l'enseignant
  change le réglage du test en cours de route. La colonne `gradingmode` est conservée en base
  mais ignorée (réversible, et n'invalide pas les anciennes sauvegardes).
- **Indices et solutions liés à la rétroaction générale** — masqués si l'enseignant a masqué la
  rétroaction générale dans les options de relecture, quelles que soient les cases cochées sur
  la question.
- **Avertissement « Il vous reste XX tentatives »** — en rouge au-dessus de la question, en mode
  interactif seulement, calculé sur le plus petit des deux verrous (tentatives maximales, ou
  nombre d'essais avant révélation de la solution).
- **Nouvelles valeurs par défaut** — pénalité 0, indices après 2 tentatives (cochés), solutions
  après 5 (cochées), maximum 5 tentatives. N'affecte que les nouvelles questions.
- **Dépôt de fichiers .pg par archive ZIP** (administrateurs de site uniquement, désactivé par
  défaut) — envoie des `.pg` dans la banque privée du renderer en préservant l'arborescence,
  avec navigateur de dossiers, détection des doublons, et validation stricte des chemins côté
  serveur. Voir le README pour la justification du niveau d'accès retenu.

## qtype_webwork 2026072836 + qbank_webworkimport 1.0 (onglet d'importation)

Nouveau plugin compagnon **`qbank_webworkimport`** : ajoute l'onglet « Importer WeBWorK » à la
banque de questions, à côté des onglets natifs. Moodle 4.x réserve l'extension de la banque de
questions aux plugins de type `qbank` — un plugin `qtype` n'a aucun point d'extension officiel
pour cela. Les tentatives précédentes (callback de navigation inexistant, puis injection d'un
bouton par sélecteurs CSS devinés via le hook de pied de page) ont été retirées : la première ne
s'exécutait jamais, la seconde ne trouvait aucun point d'ancrage fiable et aurait cassé à la
première mise à jour de Moodle.

Corrige aussi trois défauts de l'importation :
- le dossier importé devient lui-même une catégorie (importer `X/Algebre` crée une catégorie
  `Algebre`) — auparavant les questions étaient déversées en vrac dans la catégorie courante,
  ce qui provoquait aussi des doublons apparents
- le cours et le module sont déduits du contexte de la catégorie plutôt qu'exigés dans l'URL —
  le retour après importation menait sinon vers le mauvais cours, voire vers le site
- ajout d'un navigateur d'arborescence pour choisir le dossier, plutôt que de devoir taper le
  chemin de mémoire

## qtype_webwork 2026072833 (importation en lot)

Ajoute une importation en lot de problèmes WeBWorK depuis un dossier du renderer, accessible
depuis une banque de questions (« Importer depuis WeBWorK », ou directement via
`import.php?categoryid=<id>`) :

- une question par fichier `.pg` trouvé, nommée d'après le fichier
- les sous-dossiers deviennent des sous-catégories, reproduisant l'arborescence
- réglages par défaut choisis une fois pour tout le lot (aide à la saisie, notation, graine,
  indices/solutions)
- déduplication **par catégorie** : le même fichier dans deux dossiers différents donne bien deux
  questions distinctes, mais relancer l'importation d'un même dossier ne crée pas de doublons
- garde-fous : 2000 fichiers et 10 niveaux de profondeur maximum par importation
- rapport détaillé en fin d'importation (créées / ignorées / échecs)

## qtype_webwork 2026072832 (bloc de débogage enseignant)

Ajoute, sous chaque question corrigée, un bloc repliable **« Informations de débogage
(enseignants seulement) »**, visible uniquement pour qui possède la capacité `mod/quiz:grade` :
graine WeBWorK (`problemSeed`) réellement utilisée, chemin du fichier `.pg`, et historique
complet des réponses soumises (horodatage, contenu de chaque champ, note). Le couple fichier
`.pg` + graine étant déterministe, cela permet de reproduire exactement la version d'un problème
vue par un étudiant précis, pour déboguer un code qui échoue seulement sur certaines valeurs
aléatoires. Inspiré du tableau d'historique de l'ancien plugin `qtype_webwork_opaque`.

## qtype_webwork 2026072830-2026072831 (synchronisation du rendu MathJax)

MathJax v2 ne traite automatiquement la page qu'une seule fois, à son initialisation. Après une
soumission de réponse, l'ordre d'arrivée du contenu WeBWorK et de l'initialisation de MathJax
varie : si MathJax terminait son passage avant que le contenu ne soit en place, plus rien ne
relançait le rendu et le LaTeX restait affiché brut, de façon aléatoire (un rechargement manuel
le « réparait » toujours — signe classique d'une course). Un passage explicite est maintenant
déclenché sur les problèmes WeBWorK dès que MathJax et le contenu sont prêts, avec réessais
rapides (50 ms) plutôt que lents, plus une vérification tardive unique qui ne relance le rendu
que si du LaTeX brut subsiste réellement.

## qtype_webwork 2026072829 (correctif de chemin MathJax local)

Le renderer ne sert pas tout le dossier `PG/htdocs/` en bloc -- seulement des chemins
explicitement routés (voir `lib/RenderApp/Controller/StaticFiles.pm`). Le préfixe d'URL
`pg_files/...` correspond à `PG/htdocs/...` sur le disque, mais un chemin racine comme
`mathjax-v2/...` (sans ce préfixe) n'est associé à aucune route et répond 404, même si les
fichiers existent bel et bien à cet endroit -- symptôme observé après l'ajout de MathJax local
(2026072828) : téléchargement réussi côté Docker (confirmé sur le disque), mais chargement 404
côté navigateur. Le plugin référence maintenant cette copie via
`pg_files/mathjax-v2/MathJax.js`, aucun changement requis côté Dockerfile.

## qtype_webwork 2026072828 (identifiants GraphTool, boucle infinie MathView, MathJax local)

- **GraphTool** : les id des `<div>` conteneurs (ex. `AnSwEr0001_graphbox`) ne sont pas des champs
  de formulaire et n'étaient donc jamais renommés avec le préfixe Moodle -- sur une page affichant
  plusieurs questions GraphTool à la fois (page de relecture de quiz), ces id restaient identiques
  d'une question à l'autre, faisant que plusieurs instances de GraphTool tentaient de contrôler le
  même graphique en même temps. Tous les id commençant par un nom de champ connu sont maintenant
  renommés de façon cohérente (`response_parser::apply_fix_graphtool_htmlinputid`, étendu).
- **Boucle infinie MathView** : le garde-fou anti-réinitialisation (`mvInitialized`) du
  `MutationObserver` de `mathview.js` ne protégeait que l'élément directement ajouté au DOM, pas
  les éléments retrouvés via `querySelectorAll` -- bloquait complètement le navigateur sur une
  page à questions multiples. Corrigé dans `patch_mathview_loop.pl` (étend la vérification aux
  boucles de scan initial et aux branches `querySelectorAll` du `MutationObserver`), combiné à un
  marquage préventif côté plugin Moodle (`apply_mark_foreign_entryassist`) pour les champs des
  questions n'utilisant pas MathView, quand une page mélange plusieurs réglages `entryAssist`.
- **MathJax hébergé localement** : MathJax v2 était chargé depuis un CDN public
  (`cdn.jsdelivr.net`) -- un réseau lent/instable pouvait faire échouer ce chargement avant
  l'abandon de la minuterie de réessai, laissant le LaTeX brut affiché sans aucune erreur visible.
  MathJax est maintenant hébergé directement par le renderer par défaut (voir
  `renderer/README.md`), avec un nouveau réglage `qtype_webwork/mathjaxsource` pour revenir au CDN
  si désiré.

## Documentation — certificats HTTPS (renderer/README.md)

Ajout d'une section complète sur la gestion des certificats HTTPS du renderer, avec deux
approches selon le contexte :
- **Développement/test local** : mini autorité de certification (CA) maison, à installer une
  seule fois par poste ; certificats signés valides 10 ans, survivent aux reconstructions
  Docker
- **Production avec de vrais étudiants** : Let's Encrypt via validation DNS-01, sans aucune
  installation sur aucun appareil, renouvellement automatique, compatible avec un serveur
  fermé au reste d'internet (aucune exposition publique requise contrairement à la validation
  HTTP-01 par défaut)

## qtype_webwork 2026072824 (correctifs images relatives, GraphTool, popovers)

- **Images générées dynamiquement (TikZ, LaTeXImage, plots)** : leurs URL relatives
  (ex. `pg_files/tmp/images/xxx.svg`) se résolvaient par rapport à l'URL de la page Moodle
  elle-même plutôt que celle du renderer (le HTML étant injecté directement dans la page, sans
  iframe) -- 404 silencieux, image vide, aucune erreur PG/Perl puisque le rendu côté serveur
  avait réellement réussi. Les balises `<img>` sont maintenant réécrites en URL absolues.
- **GraphTool** (outil de traçage de graphiques) :
  - Son `<script>` embarqué référence le champ de réponse caché via un `htmlInputId` codé en
    dur avec le nom WeBWorK ORIGINAL (non préfixé par Moodle) -- la réponse tracée n'était
    jamais soumise (le graphique se traçait normalement, mais `document.getElementById`
    échouait silencieusement au moment de VÉRIFIER). Cette valeur est maintenant réécrite pour
    correspondre au nom de champ renommé par Moodle (`patch_graphtool.pl` n'est requis que
    côté plugin pour ce correctif précis -- voir `apply_fix_graphtool_htmlinputid`).
  - Même incompatibilité MathJax v2/v3 que MathView (`window.MathJax &&` vérifie l'existence de
    l'objet mais accède ensuite directement à `.startup.promise`, propre à la v3) -- causait un
    plantage bloquant l'exécution d'autres scripts de la page (dont le rendu MathJax de
    l'énoncé de la question). Corrigé dans `patch_graphtool.pl`, avec traduction complète de
    son interface (boutons, info-bulles, messages d'aide contextuelle).
- **Popovers de prévisualisation** : les navigateurs n'exécutent jamais les balises `<script>`
  insérées via `innerHTML` (ce que fait Bootstrap pour afficher le contenu d'un popover). La
  prévisualisation GraphTool (mini-graphique statique) embarque un tel script d'initialisation,
  qui ne s'exécutait donc jamais -- bulle vide, sans aucune erreur. Chaque `<script>` trouvé
  dans le contenu d'un popover est maintenant recréé pour forcer son exécution.

## qtype_webwork 2026072821 / renderer (correctifs MathJax/MathView/CofIdaho)

Correctifs supplémentaires suite à des tests approfondis en conditions réelles :

- **MathJax** : ajout des extensions manquantes (`color.js` notamment, pour `\textcolor{...}{...}`)
  à la configuration MathJax v2 du plugin
- **Aide à la saisie des réponses** : nouveau réglage par question (MathQuill / MathView / aucun),
  par défaut MathView — nécessite `patch_renderproblem.pl` côté renderer (ajout du calcul manquant
  de `useMathView`, déjà pleinement supporté par `PG.pm` mais jamais transmis par
  `RenderProblem.pm`)
- **MathView** : trois correctifs additionnels côté renderer (compatibilité MathJax v2,
  assainissement d'identifiant pour éviter un deux-points invalide dans un sélecteur CSS,
  conversion de déclarations `const`→`var` pour éviter une redéclaration sur page à questions
  multiples) et traduction française complète avec notation d'intervalle québécoise
- **Correctifs `problem.js`** : leurre pour `window.frameElement` et élément factice pour
  `<form id="problemMainForm">`, tous deux supposés par le script du renderer mais absents de
  notre intégration (HTML injecté directement, sans iframe)
- **Champ compagnon MathQuill** : correction du nommage pour préserver à la fois la reconnaissance
  par Moodle (`name`) et par `mqeditor.js` (`id`) — une tentative précédente avait cassé la
  persistance des réponses MathQuill d'un chargement de page à l'autre
- **Verrouillage visuel de MathQuill** : l'éditeur visuel (indépendant du `<input>` cache) est
  maintenant grisé et bloqué quand la question est terminée/la solution affichée
- **Déduplication des scripts/styles** : les assets ne sont émis qu'une seule fois par page, même
  à travers plusieurs questions WeBWorK
- **Traduction incrémentale des fichiers macro** : `patch_cofidaho.pl` (`CofIdaho_macros.pl`) et
  `patch_answerchecker.pl` (message dynamique "Your answer isn't X (it looks like Y)", construit
  à partir de `cmp_class()`/`showClass()` sans passer par le mécanisme `$context->{error}{msg}`),
  politique adoptée pour les futurs messages non traduits rencontrés en usage réel

## qtype_webwork 2026072813 / qbehaviour_webwork 2026072700

Version initiale publiée sur ce dépôt. Fonctionnalités principales :

- Rendu de questions WeBWorK (.pg) dans Moodle 4.5, via un Standalone Renderer externe
- Modes de notation différé et interactif (`qbehaviour_webwork`)
- Indices, solutions, tentatives multiples, graine aléatoire (random/fixe/par utilisateur)
- MathJax v2 intégré, popover de prévisualisation des réponses
- Navigateur en arborescence pour deux banques : bibliothèque libre (OPL) et banque locale
- Fenêtre d'édition intégrée (créer/modifier un problème `.pg` sans quitter Moodle)
- Sécurité en couches : capacité Moodle au bon contexte, secret partagé, restriction réseau, HTTPS
- Réglage de langue (`language`) transmis au renderer pour la traduction des messages
- Renderer : correctifs TeX Live (graphiques TikZ) et francisation complète (fr-CA)

### Correctifs notables durant le développement

- Sauvegarde de l'upgrade Moodle (savepoint manquant après changement de version)
- Vérification de capacité au contexte système → contexte réel de la catégorie de questions
  (faille de sécurité : n'importe quel utilisateur connecté pouvait parcourir les banques de problèmes)
- `require_once` manquant pour la classe `\curl` de Moodle dans le navigateur de bibliothèque
- Bouton de fermeture du popover : conflit avec le script `feedback.js` propre à WeBWorK
  (focus/hover), résolu en clonant l'élément déclencheur avant initialisation
- Script de câblage des popovers dupliqué sur les pages à questions multiples, résolu par un
  drapeau statique (une seule émission par page)
- TeX Live absent de l'image officielle du renderer → ajouté via `Dockerfile.with-latex`
- Encodage UTF-8 dans les correctifs Perl (`use utf8;` requis pour éviter le double encodage)
- Piège hypnotoad + Docker : le rechargement à chaud du conteneur en production casse le
  service même quand le nouveau code fonctionne correctement — toujours reconstruire l'image
  et recréer le conteneur au complet

Voir l'historique des commits et les README de chaque dossier pour le détail complet.
