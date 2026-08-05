<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Fonction de mise à jour du plugin qtype_webwork.
 *
 * @param int $oldversion le numéro de version actuellement installé.
 */
function xmldb_qtype_webwork_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    $table = new xmldb_table('qtype_webwork_options');

    if ($oldversion < 2026072300) {
        $fieldsandspecs = [
            'showcorrectness'        => new xmldb_field('showcorrectness', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1'),
            'showhints'               => new xmldb_field('showhints', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            'showhintsafter'          => new xmldb_field('showhintsafter', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1'),
            'showsolutions'           => new xmldb_field('showsolutions', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            'showsolutionsafter'      => new xmldb_field('showsolutionsafter', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1'),
            'showsolutionsaftertest'  => new xmldb_field('showsolutionsaftertest', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
        ];

        foreach ($fieldsandspecs as $name => $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026072300, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072400) {
        $field = new xmldb_field('maxtries', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '30');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026072400, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072600) {
        $field = new xmldb_field('selfsignedcert', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026072600, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072800) {
        // serverurl/selfsignedcert/sharedsecret sont désormais des réglages
        // de SITE (voir settings.php), plus des champs du formulaire de
        // question -- on donne une valeur par défaut à serverurl (colonne
        // NOT NULL) puisqu'elle n'est plus fournie par le formulaire.
        $field = new xmldb_field('serverurl', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $dbman->change_field_default($table, $field);
        upgrade_plugin_savepoint(true, 2026072800, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072801) {
        // Pas de changement de schéma pour cette version (correctif du
        // préfixe de chemin dans le navigateur de bibliothèque, côté
        // formulaire uniquement) -- on avance simplement le savepoint
        // pour qu'il corresponde à version.php.
        upgrade_plugin_savepoint(true, 2026072801, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072802) {
        // Pas de changement de schéma -- correctif du navigateur de
        // bibliothèque (vérification de capacité au contexte système
        // remplacée par une simple vérification de connexion).
        upgrade_plugin_savepoint(true, 2026072802, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072803) {
        // Pas de changement de schéma -- correctif : require_once manquant
        // pour la classe \curl de Moodle (lib/filelib.php), qui causait
        // une erreur "Class curl not found" dans le navigateur de
        // bibliothèque.
        upgrade_plugin_savepoint(true, 2026072803, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072804) {
        // Pas de changement de schéma -- ajout du bouton "Créer / éditer un
        // problème" qui ouvre l'interface native du renderer dans une
        // fenêtre surgissante (aucune nouvelle table/colonne requise).
        upgrade_plugin_savepoint(true, 2026072804, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072805) {
        // Pas de changement de schéma -- précision du texte d'aide de la
        // fenêtre d'édition (utiliser "private/" pour créer un nouveau
        // problème, "Library/" étant refusé en écriture par le renderer).
        upgrade_plugin_savepoint(true, 2026072805, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072806) {
        // Pas de changement de schéma -- ajout du réglage "privateroot" et
        // séparation du bouton de navigation unique en deux boutons
        // distincts ("Banque de problèmes libres" / "Banque de problèmes
        // locaux"), chacun scruté vers son propre point de montage Caddy
        // (library-browse / private-browse -- voir la documentation du
        // plugin pour la configuration Caddyfile correspondante).
        upgrade_plugin_savepoint(true, 2026072806, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072807) {
        // Pas de changement de schéma -- correctif de sécurité : la
        // vérification de capacité sur ajax/browse.php se fait maintenant
        // au contexte RÉEL de la catégorie de questions (transmis par le
        // formulaire), et non plus seulement "connecté et pas invité".
        // Sans ce correctif, n'importe quel utilisateur connecté (y
        // compris un étudiant) pouvait parcourir le contenu des banques de
        // problèmes.
        upgrade_plugin_savepoint(true, 2026072807, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072808) {
        // Pas de changement de schéma -- le réglage "sharedsecret" est
        // maintenant réellement utilisé : transmis en en-tête HTTP
        // X-WebWork-Secret lors de la navigation en arborescence
        // uniquement (voir webwork_client::list_directory()). Nécessite
        // une configuration Caddy correspondante (voir aide du réglage).
        upgrade_plugin_savepoint(true, 2026072808, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072809) {
        // Pas de changement de schéma -- correctif : le script de câblage
        // des popovers (bouton "X" de fermeture) ne s'émet plus qu'une
        // seule fois par page (voir renderer.php::$popoverscriptoutput).
        // Auparavant, plusieurs questions WeBWorK sur une même page
        // (affichage "tout sur une page" ou page de relecture) empilaient
        // les écouteurs et le bouton "X" finissait câblé sur une instance
        // Bootstrap périmée.
        upgrade_plugin_savepoint(true, 2026072809, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072810) {
        // Pas de changement de schéma -- correctif : le bouton "X" de
        // fermeture du popover est maintenant cloné avant qu'on y attache
        // notre gestionnaire de clic, pour éliminer tout gestionnaire
        // préexistant (probablement fourni par le script JS propre à
        // WeBWorK) qui entrait en conflit avec le nôtre -- causant un
        // rendu MathJax cassé et une fermeture nécessitant deux clics.
        upgrade_plugin_savepoint(true, 2026072810, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072811) {
        // Pas de changement de schéma -- correctif : on retire
        // explicitement le focus de l'icône déclencheuse après la
        // fermeture du popover, pour empêcher un système d'affichage
        // distinct (focus/hover) propre au script feedback.js de WeBWorK
        // de rouvrir la bulle avec du contenu brut pas encore traité par
        // MathJax.
        upgrade_plugin_savepoint(true, 2026072811, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072812) {
        // Pas de changement de schéma -- correctif plus radical : l'icône
        // déclencheuse elle-même (pas seulement le bouton "X") est
        // maintenant clonée avant toute initialisation, pour éliminer
        // TOUT gestionnaire attaché par le script feedback.js propre à
        // WeBWorK (probablement basé sur la perte de focus), qui entrait
        // en conflit avec notre propre gestion du popover.
        upgrade_plugin_savepoint(true, 2026072812, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072813) {
        // Pas de changement de schéma -- ajout du réglage "language",
        // transmis au renderer (paramètre "language" du render-api) pour
        // traduire les messages propres à WeBWorK (Correct, Erroné,
        // Indice, etc.), à condition que le fichier .po correspondant
        // existe sur le renderer.
        upgrade_plugin_savepoint(true, 2026072813, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072814) {
        // Pas de changement de schéma -- deux correctifs : (1) ajout des
        // extensions TeX manquantes (color.js notamment, pour
        // \textcolor{...}{...}) à la configuration MathJax v2 du plugin ;
        // (2) leurre pour "window.frameElement" (le script "problem.js"
        // du renderer suppose être exécuté dans une iframe pour
        // communiquer avec la page hôte, et plantait avant d'atteindre le
        // code d'initialisation de MathQuill/MathView sur les champs de
        // réponse).
        upgrade_plugin_savepoint(true, 2026072814, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072815) {
        // Pas de changement de schéma -- correctif : élément factice pour
        // <form id="problemMainForm"> (retiré délibérément de notre HTML),
        // pour éviter un second plantage de "problem.js" à la ligne
        // suivante de la même fonction d'initialisation.
        upgrade_plugin_savepoint(true, 2026072815, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072816) {
        // Pas de changement de schéma -- correctif : le champ compagnon de
        // MathQuill ("MaThQuIlL_AnSwEr0001") garde désormais son préfixe
        // "MaThQuIlL_" au tout début du nom renommé, avec le préfixe
        // Moodle inséré juste après plutôt qu'avant. mqeditor.js retrouve
        // son champ visible via un simple retrait de ce préfixe en début
        // de chaîne ; le préfixer naïvement avec celui de Moodle (venant
        // toujours en premier) faisait échouer cette recherche
        // silencieusement, empêchant MathQuill/MathView de s'initialiser.
        upgrade_plugin_savepoint(true, 2026072816, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072817) {
        // Pas de changement de schéma -- correctifs CSS pour MathQuill :
        // z-index de sa barre d'outils flottante (masquée par le tiroir
        // de blocs de Moodle) et largeur minimale du champ de réponse.
        upgrade_plugin_savepoint(true, 2026072817, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072818) {
        // Pas de changement de schéma -- correctif important : "name" des
        // champs compagnons MathQuill suit désormais TOUJOURS le préfixage
        // standard de Moodle (comme tous les autres champs) ; seul l'"id"
        // garde "MaThQuIlL_" au tout début (via un idmap séparé), pour
        // satisfaire à la fois Moodle (qui exige son propre préfixe en
        // premier sur "name" pour conserver les réponses) ET mqeditor.js
        // (qui exige "MaThQuIlL_" en premier sur "id"). La version
        // précédente réordonnait aussi "name", ce qui faisait perdre la
        // réponse MathQuill d'un chargement de page à l'autre (bien que la
        // correction elle-même fonctionnait, puisque lue directement dans
        // les données POST brutes).
        upgrade_plugin_savepoint(true, 2026072818, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072819) {
        // Pas de changement de schéma -- correctif : l'éditeur visuel de
        // MathQuill (span.mq-edit), indépendant du <input> caché en
        // dessous, n'était plus verrouillé quand la question est
        // terminée/la solution affichée (le "disabled" sur le <input>
        // sous-jacent ne le concerne pas). On grise/bloque maintenant
        // aussi son span compagnon par CSS + attributs, dès qu'il apparaît
        // dans le DOM.
        upgrade_plugin_savepoint(true, 2026072819, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072820) {
        // Ajout du champ "entryassist" (MathQuill / MathView / None),
        // transmis au renderer via le paramètre "entryAssist" du
        // render-api -- nécessite le correctif patch_renderproblem.pl côté
        // renderer (voir le dépôt GitHub du projet) pour que "MathView"
        // fonctionne réellement ; sans ce correctif côté serveur, le
        // renderer se rabat silencieusement sur MathQuill quel que soit le
        // choix fait ici.
        $table = new xmldb_table('qtype_webwork_options');
        $field = new xmldb_field('entryassist', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'MathView', 'selfsignedcert');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026072820, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072821) {
        // Pas de changement de schéma -- correctif : les scripts/styles
        // renvoyés par le renderer (dont mv_locale_*.js de MathView, qui
        // déclare des variables de niveau module) ne sont désormais émis
        // qu'une seule fois par page, même si plusieurs questions WeBWorK
        // apparaissent sur la même page de quiz. Auparavant, une page à
        // questions multiples provoquait une erreur de syntaxe JS
        // ("mv_categories has already been declared") en rechargeant le
        // même script plusieurs fois.
        upgrade_plugin_savepoint(true, 2026072821, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072822) {
        // Pas de changement de schéma -- correctif : les images générées
        // dynamiquement par WeBWorK (graphiques TikZ/LaTeXImage, plots,
        // etc.) utilisent des chemins RELATIFS dans le HTML renvoyé par le
        // renderer (ex. "pg_files/tmp/images/xxx.svg"). Injecté
        // directement dans une page Moodle (sans iframe), ce chemin se
        // résolvait par rapport à l'URL de la page Moodle elle-même, pas
        // celle du renderer -- provoquant un 404 silencieux (image vide,
        // aucune erreur PG/Perl puisque le rendu côté serveur avait
        // réellement réussi). Les balises <img> sont maintenant réécrites
        // en URL absolues, comme c'était déjà le cas pour les scripts/styles.
        upgrade_plugin_savepoint(true, 2026072822, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072823) {
        // Pas de changement de schéma -- correctif : GraphTool (l'outil de
        // traçage de graphiques de WeBWorK) embarque un <script> qui
        // appelle window.graphTool(..., {htmlInputId: "AnSwEr0001", ...}),
        // avec le nom de champ WeBWorK ORIGINAL codé en dur (non préfixé
        // par Moodle). Le script fait ensuite
        // document.getElementById(htmlInputId) pour retrouver le champ
        // caché où stocker le graphique tracé -- cette recherche échouait
        // silencieusement (le champ ayant été renommé avec le préfixe de
        // Moodle), et GraphTool ne plantait qu'au moment de VÉRIFIER la
        // réponse ("Cannot read properties of null (reading 'value')"),
        // pas au chargement : le graphique s'affichait et se traçait
        // normalement, mais aucune réponse n'était jamais soumise. La
        // valeur "htmlInputId" est maintenant réécrite pour correspondre
        // au nom de champ renommé par Moodle.
        upgrade_plugin_savepoint(true, 2026072823, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072824) {
        // Pas de changement de schéma -- correctif : les navigateurs
        // n'exécutent jamais les balises <script> insérées via innerHTML
        // (ce que fait Bootstrap pour afficher le contenu d'un popover).
        // La prévisualisation de réponse GraphTool (mini-graphique
        // statique) embarque un tel <script> d'initialisation dans le
        // contenu du popover, qui ne s'exécutait donc jamais -- la bulle
        // d'aperçu restait vide, sans aucune erreur. Chaque <script>
        // trouvé dans le contenu d'un popover est maintenant recréé pour
        // forcer son exécution.
        upgrade_plugin_savepoint(true, 2026072824, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072825) {
        // Pas de changement de schéma -- correctif important : les id des
        // <div> conteneurs de GraphTool (ex. "AnSwEr0001_graphbox") ne sont
        // PAS des champs de formulaire et n'étaient donc jamais renommés
        // avec le préfixe de Moodle. Sur une page affichant PLUSIEURS
        // questions GraphTool à la fois (ex. la page de relecture d'une
        // tentative de quiz), ces id restaient identiques d'une question à
        // l'autre -- des id HTML DUPLIQUÉS, faisant que plusieurs
        // instances de GraphTool tentaient de contrôler le même graphique
        // en même temps. Observé causant un blocage complet du navigateur
        // (page "ne répond pas"). Tous les id commençant par un nom de
        // champ connu sont maintenant renommés de façon cohérente, ainsi
        // que les références correspondantes dans les <script> embarqués.
        upgrade_plugin_savepoint(true, 2026072825, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072826) {
        // Pas de changement de schéma -- correctif majeur : MathQuill
        // (mqeditor.js) et MathView (mathview.js) partagent EXACTEMENT la
        // même convention de classes CSS ("codeshard"/"latexentryfield")
        // sur les champs de réponse texte, et chacun scanne TOUTE LA PAGE
        // à son chargement pour s'y attacher -- conçu à l'origine pour une
        // page WeBWorK classique n'affichant qu'un seul problème (donc un
        // seul éditeur) à la fois. Notre choix d'éditeur PAR QUESTION
        // casse cette hypothèse : une page Moodle avec des questions aux
        // réglages "entryAssist" différents charge les deux bibliothèques
        // en même temps, chacune s'accrochant aveuglément aux champs de
        // TOUTES les questions -- éditeurs erronés affichés au mauvais
        // endroit, et ralentissement sévère pouvant bloquer le navigateur
        // (observé sur la page de relecture d'une tentative de quiz,
        // "la page ne répond pas"). Les champs des questions n'utilisant
        // pas MathView sont désormais marqués préventivement pour que son
        // scan les ignore (nécessite aussi patch_mathview_loop.pl côté
        // renderer, qui étend la vérification à ses boucles de scan
        // initial).
        upgrade_plugin_savepoint(true, 2026072826, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072827) {
        // Pas de changement de schéma -- correctif : MathJax v2 était
        // chargé depuis un CDN public (cdn.jsdelivr.net) plutôt que
        // localement. Un réseau lent/instable pouvait faire échouer ou
        // retarder ce chargement au-delà du budget de notre minuterie de
        // réessai, laissant le LaTeX brut affiché (ex. "\(4\)") au lieu
        // d'être rendu, aussi bien dans l'énoncé de la question que dans
        // les bulles de rétroaction -- sans aucune erreur visible dans la
        // console (échec silencieux, purement une question de délai
        // réseau). MathJax v2 est maintenant hébergé directement par le
        // renderer (voir Dockerfile.with-latex), éliminant cette
        // dépendance réseau externe.
        upgrade_plugin_savepoint(true, 2026072827, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072828) {
        // Pas de changement de schéma -- ajoute le réglage
        // "qtype_webwork/mathjaxsource" (administration du site),
        // permettant de choisir entre MathJax hébergé localement par le
        // renderer (par défaut, recommandé) et le CDN externe
        // (cdn.jsdelivr.net) -- utile si l'image du renderer n'a pas été
        // construite avec l'étape de téléchargement de MathJax.
        upgrade_plugin_savepoint(true, 2026072828, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072829) {
        // Pas de changement de schéma -- correctif : le renderer ne sert
        // PAS tout le dossier PG/htdocs en bloc, seulement des chemins
        // explicitement routés (voir
        // RenderApp/Controller/StaticFiles.pm -- "pg_files/..." mappe
        // vers PG/htdocs/..., mais un chemin RACINE comme "mathjax-v2/..."
        // n'est associé à AUCUNE route et répond 404, même si les
        // fichiers existent bel et bien sur le disque à cet endroit).
        // L'URL de MathJax local pointe maintenant vers
        // "pg_files/mathjax-v2/..." plutôt que "mathjax-v2/..." --
        // aucun changement requis côté Dockerfile, les fichiers étaient
        // déjà au bon endroit sur le disque.
        upgrade_plugin_savepoint(true, 2026072829, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072830) {
        // Pas de changement de schéma -- correctif : MathJax v2 ne traite
        // automatiquement la page qu'UNE SEULE FOIS, à son initialisation.
        // Après une soumission de réponse (rechargement avec rétroaction),
        // l'ordre d'arrivée du contenu WeBWorK et de l'initialisation de
        // MathJax varie : si MathJax terminait son passage automatique
        // AVANT que le contenu ne soit en place, plus rien ne relançait le
        // rendu et le LaTeX restait affiché brut (dans l'énoncé comme dans
        // les bulles de rétroaction), de façon aléatoire -- un
        // rechargement manuel de la page le "réparait" toujours, signe
        // classique d'une course. Un passage explicite est maintenant
        // déclenché sur les problèmes WeBWorK une fois la page et MathJax
        // prêts (avec réessais, plus un second passage au "load"), et la
        // fenêtre de réessai des bulles de rétroaction passe de 5 à 15
        // secondes.
        upgrade_plugin_savepoint(true, 2026072830, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072831) {
        // Pas de changement de schéma -- affinage du correctif précédent :
        // les réessais passent de 250 ms à 50 ms (on veut déclencher le
        // rendu dès que MathJax devient disponible, pas au prochain tick
        // d'une minuterie lente), et le second passage systématique au
        // "window.load" est remplacé par une vérification unique à 1,2 s
        // qui ne relance le rendu QUE si du LaTeX brut subsiste
        // réellement. La version précédente imposait à tout le monde un
        // délai visible (jusqu'à ~10 s sur un chargement lent) pour
        // couvrir un cas rare.
        upgrade_plugin_savepoint(true, 2026072831, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072832) {
        // Pas de changement de schéma -- ajoute un bloc de débogage
        // repliable sous chaque question, visible UNIQUEMENT pour qui a la
        // capacité mod/quiz:grade (enseignants, correcteurs) : graine
        // WeBWorK utilisée pour générer la question, chemin du fichier .pg,
        // et historique complet des réponses soumises avec horodatage et
        // note. Le couple fichier .pg + graine étant déterministe, cela
        // permet de reproduire exactement la version d'un problème vue par
        // un étudiant, pour déboguer une question dont le code comporte
        // une erreur.
        upgrade_plugin_savepoint(true, 2026072832, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072833) {
        // Pas de changement de schéma -- ajoute l'importation en lot de
        // problèmes WeBWorK depuis un dossier du renderer (import.php,
        // classes/bulk_importer.php, classes/bulk_import_form.php).
        // Crée une question par fichier .pg trouvé, en reproduisant
        // l'arborescence des sous-dossiers sous forme de sous-catégories
        // de la banque de questions. La déduplication se fait PAR
        // CATÉGORIE : le même fichier rangé dans deux dossiers différents
        // donne bien deux questions distinctes.
        upgrade_plugin_savepoint(true, 2026072833, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072834) {
        // Pas de changement de schéma -- corrige l'accès à l'importation :
        // import.php accepte maintenant AUSSI le paramètre "cat" de Moodle
        // (format "<categoryid>,<contextid>"), permettant de simplement
        // remplacer "edit.php" par "type/webwork/import.php" dans l'URL
        // courante, et affiche un message explicite au lieu de planter si
        // aucune catégorie n'est indiquée. Le lien dans la banque de
        // questions passe désormais par le hook officiel
        // before_footer_html_generation (le callback de navigation utilisé
        // précédemment n'existe pas dans Moodle 4.5).
        upgrade_plugin_savepoint(true, 2026072834, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072835) {
        // Pas de changement de schéma -- trois correctifs de l'importation :
        // (1) le dossier importé devient lui-même une catégorie (ex.
        //     importer "X/Algebre" crée une catégorie "Algebre"), au lieu
        //     de déverser les questions en vrac dans la catégorie courante ;
        // (2) le cours et le module sont DÉDUITS du contexte de la
        //     catégorie plutôt qu'exigés dans l'URL -- se fier aux
        //     paramètres transmis renvoyait vers le mauvais cours (ou vers
        //     le site) quand ils manquaient ;
        // (3) ajout d'un navigateur d'arborescence pour choisir le dossier,
        //     plutôt que de devoir taper le chemin de mémoire.
        upgrade_plugin_savepoint(true, 2026072835, 'qtype', 'webwork');
    }

    if ($oldversion < 2026072836) {
        // Pas de changement de schéma -- le point d'entrée vers
        // l'importation passe désormais par un plugin compagnon dédié
        // (qbank_webworkimport), Moodle 4.x réservant l'extension de la
        // banque de questions aux plugins de type "qbank". Le hook
        // before_footer_html_generation utilisé auparavant (qui injectait
        // un bouton via des sélecteurs CSS devinés) est retiré : il ne
        // trouvait aucun point d'ancrage fiable et aurait de toute façon
        // cassé à la première mise à jour de Moodle. import.php se replie
        // aussi sur la catégorie par défaut du contexte quand aucune
        // catégorie n'est explicitement fournie.
        upgrade_plugin_savepoint(true, 2026072836, 'qtype', 'webwork');
    }
}
