# Journal des modifications

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
