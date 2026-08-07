<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/webwork/classes/response_parser.php');

class qtype_webwork_renderer extends qtype_renderer {

    /** @var bool s'assure que MathJax v2 n'est chargé qu'une seule fois par page. */
    protected static $mathjaxloaded = false;

    /**
     * @var bool s'assure que le script de câblage des popovers Bootstrap
     * n'est émis qu'une seule fois par page, même si render() est appelé
     * plusieurs fois (plusieurs questions WeBWorK sur une même page de
     * quiz, ou page de relecture). Le script re-scanne TOUTES les bulles
     * de la page via querySelectorAll à chaque exécution -- l'émettre
     * plusieurs fois empilait les écouteurs "shown.bs.popover" (un par
     * question rendue), et le bouton "X" finissait câblé sur une instance
     * Bootstrap déjà périmée (disposée par un passage ultérieur) au lieu
     * de la dernière instance réellement active.
     */
    protected static $popoverscriptoutput = false;

    /**
     * @var bool s'assure que le leurre pour "window.frameElement" n'est
     * émis qu'une seule fois par page (voir formulation_and_controls()).
     */
    protected static $framelementshimoutput = false;

    /**
     * @var bool s'assure que le correctif CSS pour MathQuill (z-index de
     * sa barre d'outils, largeur du champ) n'est émis qu'une seule fois
     * par page.
     */
    protected static $mathquillcssoutput = false;

    /**
     * @var array URLs d'assets (JS/CSS) déjà émises sur la page en cours,
     * pour éviter de les charger/exécuter plusieurs fois sur une page à
     * questions multiples -- certains scripts de WeBWorK (ex. mv_locale_*.js
     * de MathView) déclarent des variables de niveau module ("let"/"const")
     * qui lèvent une erreur de syntaxe si le même script s'exécute deux
     * fois sur la même page.
     */
    protected static $emittedassets = [];

    /**
     * Charge MathJax v2 -- par défaut hébergé localement par le renderer
     * (voir Dockerfile.with-latex) plutôt que via un CDN public. Un réseau
     * lent/instable pouvait faire échouer le chargement depuis le CDN
     * avant que notre minuterie de réessai n'abandonne, laissant le LaTeX
     * brut affiché au lieu d'être rendu, sans aucune erreur visible dans
     * la console (échec silencieux). Réglable via l'administration du
     * site (qtype_webwork/mathjaxsource) pour revenir au CDN si l'image du
     * renderer n'a pas été construite avec MathJax inclus.
     */
    protected function get_mathjax_bootstrap(string $serverurl): string {
        $config = <<<'JS'
window.MathJax = window.MathJax || {
    tex2jax: {
        inlineMath: [['\\(', '\\)']],
        processEscapes: true
    },
    TeX: {
        // Extensions couramment utilisées dans les problèmes WeBWorK mais
        // absentes du module combiné "TeX-MML-AM_CHTML" par défaut --
        // notamment color.js, sans quoi \textcolor{...}{...} s'affichait
        // tel quel au lieu d'être interprété. noErrors/noUndefined évitent
        // qu'une commande future non prévue casse tout le rendu (affiche
        // un espace réservé plutôt que de planter silencieusement).
        extensions: ['color.js', 'cancel.js', 'enclose.js', 'noErrors.js', 'noUndefined.js']
    },
    messageStyle: 'none',
    menuSettings: {
        zoom: 'Double-Click',
        zscale: '300%'
    }
};
JS;
        $mathjaxsource = get_config('qtype_webwork', 'mathjaxsource') ?: 'local';
        $mathjaxsrc = $mathjaxsource === 'cdn'
            ? 'https://cdn.jsdelivr.net/npm/mathjax@2.7.9/MathJax.js?config=TeX-MML-AM_CHTML'
            : $this->resolve_asset_url($serverurl, 'pg_files/mathjax-v2/MathJax.js?config=TeX-MML-AM_CHTML');

        return html_writer::tag('script', $config)
            . html_writer::tag('script', '', [
                'src' => $mathjaxsrc,
            ])
            . html_writer::tag('script', self::MATHJAX_TYPESET_TRIGGER);
    }

    /**
     * Déclenche explicitement un passage de MathJax sur les problèmes
     * WeBWorK une fois la page ET MathJax prêts.
     *
     * MathJax v2 traite automatiquement la page une seule fois, à son
     * initialisation. Sur un chargement normal, cela suffit. Mais après
     * une soumission de réponse (rechargement avec rétroaction), l'ordre
     * d'arrivée exact du contenu WeBWorK et de l'initialisation de MathJax
     * varie -- si MathJax termine son passage automatique AVANT que le
     * contenu ne soit en place, plus rien ne relance le rendu, et le LaTeX
     * reste affiché brut. C'est une course, d'où le caractère aléatoire du
     * problème (un rechargement manuel de la page le "réparait" toujours).
     *
     * On force donc un passage supplémentaire, avec réessais espacés pour
     * couvrir les cas où du contenu arrive encore un peu après (ex.
     * bulles de rétroaction insérées dynamiquement). Un passage de plus
     * sur du contenu déjà rendu est sans effet (MathJax saute les
     * éléments déjà traités), donc c'est sans risque.
     */
    protected const MATHJAX_TYPESET_TRIGGER = <<<'JS'
(function() {
    // Réessais RAPIDES (50 ms) plutôt que lents : on veut déclencher le
    // rendu dès la milliseconde où MathJax devient disponible, pas
    // attendre le prochain "tick" d'une minuterie lente. Plafond à ~10 s
    // au total, mais en pratique on sort de la boucle en quelques
    // dizaines de millisecondes.
    var attempts = 0;
    var maxattempts = 200;
    function ready() {
        return window.MathJax && MathJax.Hub && MathJax.Hub.Queue;
    }
    function typesetProblems() {
        var targets = document.querySelectorAll('.webwork-problem');
        if (!ready() || !targets.length) {
            if (++attempts < maxattempts) { setTimeout(typesetProblems, 50); }
            return;
        }
        targets.forEach(function(el) {
            MathJax.Hub.Queue(['Typeset', MathJax.Hub, el]);
        });
        // Filet de sécurité : une seule vérification tardive, et
        // UNIQUEMENT si du LaTeX brut subsiste réellement (contenu arrivé
        // après notre passage). Évite d'imposer un délai à tout le monde
        // pour couvrir un cas rare.
        setTimeout(function() {
            var leftover = document.querySelectorAll('.webwork-problem script[type^="math/tex"]:not(.qtype-webwork-done)');
            if (!leftover.length || !ready()) { return; }
            leftover.forEach(function(s) { s.classList.add('qtype-webwork-done'); });
            document.querySelectorAll('.webwork-problem').forEach(function(el) {
                MathJax.Hub.Queue(['Typeset', MathJax.Hub, el]);
            });
        }, 1200);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', typesetProblems);
    } else {
        typesetProblems();
    }
})();
JS;

    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        $question = $qa->get_question();
        /** @var qtype_webwork_question $question */

        $output = html_writer::tag('div', $question->format_questiontext($qa), ['class' => 'qtext']);

        // On charge nous-mêmes MathJax v2 (une seule fois par page, même si
        // plusieurs questions WeBWorK apparaissent sur la même page) --
        // entièrement autonome, sans dépendre d'un réglage global du site
        // (HTML additionnel) qui ne serait pas portable lors du déploiement
        // du plugin sur une autre installation Moodle. On utilise
        // volontairement MathJax v2 (pas la v3 fournie par WeBWorK) : dans
        // ce contexte d'intégration (un fragment de page, pas une page
        // WeBWorK complète avec sa balise <base>), l'initialisation interne
        // de MathJax v3 (chargement dynamique de composants) s'est avérée
        // trop fragile à fiabiliser -- v2 est plus simple et plus tolérant.
        if (!self::$mathjaxloaded) {
            self::$mathjaxloaded = true;
            $output .= $this->get_mathjax_bootstrap($question->serverurl);
        }

        // Force l'ouverture/fermeture des indices/solutions à fonctionner via
        // la sémantique native <details>/<summary> (gérée par le navigateur
        // sans JS), indépendamment du fait que le JS de Bootstrap (qui gère
        // normalement la classe "collapse") soit correctement chargé ou non
        // dans ce contexte d'intégration.
        $output .= html_writer::tag('style',
            '.webwork-problem details.accordion-item > .accordion-collapse { display: none; }'
            . '.webwork-problem details.accordion-item[open] > .accordion-collapse { display: block; }'
        );

        // IMPORTANT : on n'utilise PAS $this->page->requires->css()/js() ici.
        // Ces méthodes ne peuvent être appelées qu'AVANT que le <head> de la
        // page ne soit envoyé au navigateur -- or le rendu d'une question
        // (formulation_and_controls) se produit plus tard dans le cycle de
        // la page (après l'en-tête), ce qui déclenche une coding_exception
        // ("Cannot require a CSS file after <head> has been printed").
        // On insère donc directement des balises <link>/<script> dans le
        // HTML retourné -- ce n'est pas strictement conforme aux bonnes
        // pratiques HTML (elles atterrissent dans le <body>), mais tous les
        // navigateurs modernes les traitent correctement.

        // On utilise le HTML *corrigé* (via check_answers()) dès qu'une
        // réponse évaluable existe, indépendamment du réglage de relecture --
        // on décide plus loin si les indicateurs de correction doivent être
        // révélés ou masqués (voir strip_correctness()).
        $lastresponse = $qa->get_last_qt_data();
        $showgraded = !empty($lastresponse) && $question->is_gradable_response($lastresponse);

        if ($showgraded) {
            $render = $question->get_graded_render_data($lastresponse);
            $html = $render['html']; // Les valeurs soumises sont déjà intégrées par le renderer.
            $values = null;
        } else {
            $render = $question->get_render_data();
            $html = $render['html'];
            $values = [];
            foreach ($render['fieldnames'] as $name) {
                $values[$name] = $qa->get_last_qt_var($name, '');
            }
        }

        // Le mode de notation est déduit du comportement réglé au niveau du
        // TEST, pas configuré par question. On le resynchronise ici parce
        // que make_behaviour() n'est pas systématiquement appelé avant le
        // rendu (notamment en prévisualisation de question).
        //
        // question_attempt n'expose pas directement le comportement
        // "préféré" choisi sur le test ; on relit donc celui que
        // make_behaviour() a mémorisé sur la question, avec repli sur le
        // mode interactif (le défaut voulu pour tout comportement autre que
        // « à posteriori »).
        if (empty($question->gradingmode)
                || !in_array($question->gradingmode, ['deferred', 'interactive'], true)) {
            $question->gradingmode = 'interactive';
        }

        // Les indices et solutions constituent de la rétroaction : ils
        // suivent donc le réglage "Rétroaction générale" du test, en plus de
        // leur propre case à cocher dans la question. Si l'enseignant a
        // masqué la rétroaction générale (typiquement : test à posteriori
        // sans rétroaction pendant la tentative), rien de tout cela ne
        // s'affiche, quelles que soient les cases cochées sur la question.
        $feedbackallowed = $options->generalfeedback != question_display_options::HIDDEN;

        // Indices : basés sur le nombre de tentatives, dans les deux modes
        // (voir question.php::tries_so_far() -- compte les étapes réellement
        // soumises, y compris en mode différé où notre comportement
        // personnalisé enregistre chaque "Vérifier" sans les noter).
        $tries = $question->tries_so_far($qa);
        $showhintsnow = $feedbackallowed && $question->showhints && ($tries >= $question->showhintsafter);

        // Solutions : en mode différé, uniquement une fois le test terminé
        // (jamais avant, peu importe le nombre de tentatives) -- les
        // réglages "après N tentatives"/"après la fin du test" propres au
        // mode interactif n'ont pas leur place ici. En mode interactif, le
        // comportement existant (basé sur les tentatives) s'applique.
        if ($question->gradingmode === 'deferred') {
            $showsolutionsnow = $feedbackallowed && $question->showsolutions
                && $qa->get_state()->is_finished();
        } else {
            $showsolutionsnow = $feedbackallowed && $question->showsolutions && (
                ($tries >= $question->showsolutionsafter)
                || ($question->showsolutionsaftertest && $qa->get_state()->is_finished())
            );
        }

        // Révélation de la correction (couleurs, résumé de score) : en mode
        // différé, JAMAIS avant que le test lui-même ne soit terminé, peu
        // importe le nombre de clics sur "Vérifier" entre-temps -- c'est ce
        // qui corrige le bug où changer de question affichait déjà bon/
        // mauvais avant la fin du test. En mode interactif, la révélation
        // suit uniquement le réglage de la question (déjà immédiate par
        // nature).
        $revealcorrectness = $question->showcorrectness;
        if ($question->gradingmode === 'deferred') {
            $revealcorrectness = $revealcorrectness && $qa->get_state()->is_finished();
        }

        // Renomme tous les champs avec le préfixe propre à la tentative
        // Moodle ($qa->get_qt_field_name()) -- ÉTAPE CRITIQUE : sans ce
        // préfixe, Moodle ne reconnaît pas ces champs comme appartenant à la
        // question lors de la soumission, et traite chaque "Vérifier" comme
        // une réponse vide/inchangée (aucune persistance, aucune tentative
        // comptabilisée).
        //
        // IMPORTANT : "name" doit TOUJOURS suivre le préfixage standard de
        // Moodle (get_qt_field_name), sans exception -- Moodle ne
        // reconnaît et ne conserve un champ soumis que si son "name"
        // commence par SON PROPRE préfixe. C'est l'attribut "id" des
        // champs compagnons MathQuill qui a besoin d'un traitement
        // spécial (voir $idmap plus bas et response_parser::apply_rename_fields()),
        // PAS le "name" -- une tentative précédente de réordonner "name"
        // avait cassé la conservation des réponses par Moodle d'un
        // chargement de page à l'autre.
        $namemap = [];
        $idmap = [];
        foreach ($render['allfieldnames'] as $name) {
            $namemap[$name] = $qa->get_qt_field_name($name);
            if (strpos($name, 'MaThQuIlL_') === 0) {
                $suffix = substr($name, strlen('MaThQuIlL_'));
                $idmap[$name] = 'MaThQuIlL_' . $qa->get_qt_field_name($suffix);
            }
        }

        // Toutes les transformations HTML en une seule passe DOM (voir
        // response_parser::transform()) : des passes séparées répétées
        // risquaient de progressivement corrompre les attributs HTML
        // complexes des bulles de prévisualisation de WeBWorK.
        $html = \qtype_webwork\response_parser::transform($html, [
            'values' => $values,
            'showhints' => $showhintsnow,
            'showsolutions' => $showsolutionsnow,
            'stripcorrectness' => $showgraded && !$revealcorrectness,
            'disable' => $options->readonly || $showsolutionsnow,
            'namemap' => $namemap,
            'idmap' => $idmap,
            'serverurl' => $question->serverurl,
            'entryassist' => $question->entryassist,
        ]);

        // Correctifs CSS pour l'éditeur MathQuill dans notre contexte
        // d'intégration : (1) sa barre d'outils flottante se retrouvait
        // cachée sous le tiroir de blocs de Moodle (conflit de z-index) ;
        // (2) le champ de réponse devient visuellement plus étroit une
        // fois MathQuill actif (il masque le champ texte natif derrière
        // son propre affichage). Émis une seule fois par page.
        if (!self::$mathquillcssoutput) {
            self::$mathquillcssoutput = true;
            $output .= html_writer::tag('style',
                '.quill-toolbar { z-index: 100000 !important; }'
                . '.mq-edit { min-width: 6em; }'
                . '.mq-edit.qtype-webwork-disabled { pointer-events: none; opacity: .65; }'
            );

            // MathQuill affiche son propre éditeur visuel (span.mq-edit),
            // indépendant du <input> caché en dessous -- mettre "disabled"
            // sur ce dernier (voir apply_disable_inputs()) ne bloque donc
            // plus visuellement/fonctionnellement l'éditeur MathQuill.
            // N'ayant pas accès à l'API interne de MathQuill pour appeler
            // sa propre méthode de verrouillage, on grise/bloque par CSS +
            // attributs génériques dès que son span compagnon apparaît
            // (créé par mqeditor.js après le chargement -- on réessaie
            // donc quelques fois, comme pour le rendu MathJax).
            $output .= html_writer::tag('script',
                "(function retryLockMathQuill(attemptsleft) {"
                . "var found = false;"
                . "document.querySelectorAll('.webwork-problem input[disabled], .webwork-problem textarea[disabled]').forEach(function(inp) {"
                . "var span = document.getElementById('mq-answer-' + inp.id);"
                . "if (span) {"
                . "found = true;"
                . "span.classList.add('qtype-webwork-disabled');"
                . "span.setAttribute('tabindex', '-1');"
                . "span.querySelectorAll('textarea, input').forEach(function(f) {"
                . "f.setAttribute('disabled', 'disabled');"
                . "f.setAttribute('tabindex', '-1');"
                . "});"
                . "}"
                . "});"
                . "if (!found && attemptsleft > 0) {"
                . "setTimeout(function() { retryLockMathQuill(attemptsleft - 1); }, 250);"
                . "}"
                . "})(20);"
            );
        }

        // Injection des feuilles de style renvoyées par le renderer, sous
        // forme de balises <link> directement dans le HTML (voir note
        // ci-dessus sur pourquoi $PAGE->requires->css() ne convient pas ici).
        foreach ($render['css'] as $css) {
            if (in_array($css, self::$emittedassets, true)) {
                continue;
            }
            self::$emittedassets[] = $css;
            $url = $this->resolve_asset_url($question->serverurl, $css);
            $output .= html_writer::empty_tag('link', ['rel' => 'stylesheet', 'href' => $url]);
        }

        $output .= $this->render_tries_warning($qa, $question, $tries, $feedbackallowed);
        $output .= html_writer::tag('div', $html, ['class' => 'webwork-problem']);

        // Leurre pour "window.frameElement" : le script "problem.js" du
        // renderer suppose être exécuté à l'intérieur d'une iframe (pour
        // communiquer avec la page hôte via postMessage -- mécanisme
        // "webwork.interaction.*"). Chez nous, le HTML du renderer est
        // injecté DIRECTEMENT dans la page Moodle (pas d'iframe), donc
        // "window.frameElement" vaut null, et sa toute première ligne
        // ("window.frameElement.id") plante avant même d'atteindre le code
        // plus loin dans le même fichier qui initialise MathQuill/MathView
        // sur les champs de réponse -- ce qui les empêchait de fonctionner
        // silencieusement. On fournit un objet minimal à la place, une
        // seule fois par page, AVANT que "problem.js" ne s'exécute.
        if (!self::$framelementshimoutput) {
            self::$framelementshimoutput = true;
            $output .= html_writer::tag('script',
                "if (!window.frameElement) {"
                . "try {"
                . "Object.defineProperty(window, 'frameElement', {"
                . "value: { id: 'qtype-webwork', dataset: { id: 'qtype-webwork' } },"
                . "configurable: true"
                . "});"
                . "} catch (e) { /* navigateur refusant la redéfinition : tant pis, on laisse faire */ }"
                . "}"
                // "problem.js" cherche aussi l'élément <form id="problemMainForm">
                // de WeBWorK (pour y attacher ses propres écouteurs
                // d'événements) -- un élément qu'on retire délibérément de
                // notre HTML (voir response_parser::parse()), puisqu'un
                // <form> imbriqué dans le vrai <form> de Moodle serait
                // invalide et casserait la soumission de la page. On
                // fournit ici un élément invisible portant le même id,
                // uniquement pour éviter que "problem.js" plante en
                // cherchant à s'y attacher -- ses fonctionnalités propres
                // (rapport d'interaction via postMessage à une page hôte
                // en iframe) ne nous concernent de toute façon pas, cette
                // intégration n'utilisant pas d'iframe.
                . "if (!document.getElementById('problemMainForm')) {"
                . "var shimform = document.createElement('div');"
                . "shimform.id = 'problemMainForm';"
                . "shimform.style.display = 'none';"
                . "document.body.appendChild(shimform);"
                . "}"
            );
        }

        // Scripts requis (JS spécifiques du problème, popovers Bootstrap,
        // ...), en balises <script> inline plutôt que via
        // $PAGE->requires->js(), pour la même raison de timing. MathJax v3
        // de WeBWorK (tex-svg.js/mathjax-config.js) est volontairement
        // exclu de cette liste (voir response_parser::parse()) -- le plugin
        // charge sa propre installation de MathJax v2 séparément (voir
        // get_mathjax_bootstrap()).
        foreach ($render['js'] as $js) {
            if (in_array($js, self::$emittedassets, true)) {
                continue;
            }
            self::$emittedassets[] = $js;
            $url = $this->resolve_asset_url($question->serverurl, $js);
            $output .= html_writer::tag('script', '', ['src' => $url]);
        }

        // Les bulles de prévisualisation de WeBWorK (icônes vert/rouge,
        // "Preview of Your Answer") utilisent les popovers Bootstrap, qui ne
        // s'initialisent pas automatiquement dans notre contexte
        // d'intégration (contrairement à une page WeBWorK complète, qui a
        // son propre script d'initialisation). On les active nous-mêmes une
        // fois le DOM et le script Bootstrap chargés.
        // On utilise getOrCreateInstance plutôt que "new Popover()" : si
        // WeBWorK (ou nous-mêmes lors d'un rendu précédent) a déjà créé une
        // instance sur cet élément, on la réutilise au lieu d'en créer une
        // deuxième en conflit ; sinon, on en crée une nouvelle -- dans les
        // deux cas, la bulle s'ouvre correctement.
        // "sanitize: false" est nécessaire : le nettoyeur HTML intégré de
        // Bootstrap retire par défaut toute balise <script> du contenu des
        // popovers (protection anti-XSS générique), ce qui effaçait
        // entièrement le texte mathématique de WeBWorK
        // (<script type="math/tex">...</script>) avant même que MathJax
        // ait la chance de le traiter. Comme ce contenu provient de notre
        // propre serveur WeBWorK (pas d'une saisie arbitraire d'utilisateur),
        // désactiver ce nettoyage ici est un compromis raisonnable.
        if (!self::$popoverscriptoutput) {
            self::$popoverscriptoutput = true;
            $output .= html_writer::tag('script',
                "document.addEventListener('DOMContentLoaded', function() {"
                . "if (!(window.bootstrap && window.bootstrap.Popover)) { return; }"
                . "document.querySelectorAll('.webwork-problem [data-bs-toggle=\"popover\"]').forEach(function(original) {"
                // On clone le déclencheur (l'icône verte/rouge/grise)
                // lui-même, pas seulement le bouton "X" -- le script propre
                // à WeBWorK (feedback.*.min.js) initialise sa PROPRE
                // gestion du popover sur ce même élément (avec, semble-t-il,
                // une logique basée sur la perte de focus, indépendante de
                // notre "click"), et cette logique entrait en conflit avec
                // la nôtre : fermeture correcte au premier clic, puis
                // réouverture immédiate avec du contenu brut non traité par
                // MathJax. cloneNode(true) préserve tous les attributs
                // data-bs-* (contenu, titre) mais aucun écouteur JS -- en
                // remplaçant l'élément original par son clone AVANT toute
                // initialisation, on repart d'un élément vierge que seul
                // notre propre code gère.
                . "var el = original.cloneNode(true);"
                . "original.parentNode.replaceChild(el, original);"
                // On détruit toute instance existante avant d'en recréer une :
                // une instance déjà créée garderait son ancienne configuration
                // (notamment "sanitize: true" par défaut).
                . "var existing = bootstrap.Popover.getInstance(el);"
                . "if (existing) { existing.dispose(); }"
                // Trigger par défaut ("click", déjà présent via data-bs-trigger
                // sur l'élément) : fermeture en recliquant sur l'icône ou via le
                // bouton "X" natif de WeBWorK (visible depuis qu'on désactive le
                // nettoyage HTML de Bootstrap ci-dessous).
                . "var instance = new bootstrap.Popover(el, {sanitize: false});"
                . "el.addEventListener('shown.bs.popover', function() {"
                . "var tipId = el.getAttribute('aria-describedby');"
                . "var tip = tipId ? document.getElementById(tipId) : null;"
                . "if (!tip) { return; }"
                // Les navigateurs n'exécutent JAMAIS les balises <script>
                // insérées via innerHTML (ce que fait Bootstrap pour
                // afficher le contenu d'un popover) -- une restriction du
                // DOM, indépendante de Bootstrap ou de notre propre code.
                // Certaines prévisualisations de réponse (ex. GraphTool,
                // qui affiche une mini-image statique du graphique tracé)
                // embarquent un <script> d'initialisation dans le contenu
                // du popover, qui ne s'exécuterait donc jamais sans ce
                // correctif. On recrée chaque <script> trouvé (en copiant
                // son contenu) pour forcer son exécution.
                . "tip.querySelectorAll('script').forEach(function(oldscript) {"
                . "var newscript = document.createElement('script');"
                . "if (oldscript.src) { newscript.src = oldscript.src; }"
                . "else { newscript.textContent = oldscript.textContent; }"
                . "oldscript.parentNode.replaceChild(newscript, oldscript);"
                . "});"
                // Le bouton "X" fourni par WeBWorK dans le contenu du popover
                // utilise l'ancien attribut Bootstrap 3/4 "data-dismiss" (ou la
                // classe "close"), que Bootstrap 5 ne reconnaît plus (renommé
                // "data-bs-dismiss"). Sans ce correctif, cliquer sur ce bouton
                // ne fait rien -- il faut recliquer sur l'icône déclencheur
                // pour fermer. On câble donc nous-mêmes ce bouton vers notre
                // instance Bootstrap 5.
                . "var closebtn = tip.querySelector('[data-dismiss=\"popover\"], [data-bs-dismiss=\"popover\"], .close, .btn-close');"
                . "if (closebtn && !closebtn.dataset.webworkCloseWired) {"
                // On clone le bouton avant d'y attacher notre gestionnaire :
                // le contenu du popover provient du HTML brut de WeBWorK, qui
                // peut déjà avoir câblé son propre gestionnaire sur ce bouton
                // (via son propre script JS, chargé juste avant). Les deux
                // gestionnaires se disputaient alors le même clic -- premier
                // clic : conflit visuel (MathJax cassé), la bulle restait
                // ouverte ; second clic : notre hide() finissait par
                // l'emporter. cloneNode(true) copie la structure du bouton
                // mais JAMAIS ses écouteurs JS -- en le remplaçant par son
                // clone avant d'attacher notre propre écouteur, on repart
                // d'un bouton "vierge" sans aucun conflit possible.
                . "var freshbtn = closebtn.cloneNode(true);"
                . "freshbtn.dataset.webworkCloseWired = '1';"
                . "closebtn.parentNode.replaceChild(freshbtn, closebtn);"
                . "freshbtn.addEventListener('click', function(e) {"
                . "e.preventDefault();"
                . "e.stopPropagation();"
                . "instance.hide();"
                // Le focus qui revient sur l'icône déclencheuse après la
                // fermeture peut réactiver un système d'affichage distinct
                // du nôtre, propre au script JS de WeBWorK (feedback.js),
                // configuré en déclenchement focus/hover -- invisible et
                // non désactivable depuis notre code (module JS séparé).
                // On retire donc explicitement le focus, immédiatement ET
                // après un court délai (si Bootstrap le restaure lui-même
                // une fois sa transition de fermeture terminée), pour
                // empêcher toute réouverture accidentelle avec du contenu
                // brut pas encore traité par MathJax.
                . "if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }"
                . "el.blur();"
                . "setTimeout(function() { el.blur(); }, 300);"
                . "});"
                . "}"
            // La prévisualisation contient des délimiteurs \(...\) -- on
            // s'appuie sur MathJax v2 (chargé par le plugin, voir
            // get_mathjax_bootstrap()) et son API Hub.Queue. MathJax v2 peut
            // ne pas avoir terminé son propre chargement asynchrone au tout
            // premier clic -- on réessaie automatiquement pendant quelques
            // secondes plutôt que d'échouer silencieusement une seule fois.
                . "(function typesetWhenReady(attemptsleft) {"
                . "if (window.MathJax && window.MathJax.Hub && window.MathJax.Hub.Queue) {"
                . "MathJax.Hub.Queue(['Typeset', MathJax.Hub, tip]);"
                . "} else if (attemptsleft > 0) {"
                . "setTimeout(function() { typesetWhenReady(attemptsleft - 1); }, 50);"
                . "}"
                . "})(200);"
                . "});"
                . "});"
                . "});"
            );
        }

        return $output;
    }

    /**
     * Avertissement en rouge indiquant combien de tentatives il reste avant
     * que la question ne se verrouille.
     *
     * Uniquement en mode interactif : en mode différé, l'étudiant peut
     * cliquer « Vérifier » autant de fois qu'il veut sans conséquence (rien
     * n'est noté avant la fin du test), la notion de tentative restante n'a
     * donc pas de sens.
     *
     * La limite effective est le PLUS PETIT de deux réglages, parce que
     * l'un comme l'autre verrouille la question (voir
     * qbehaviour_webwork::process_submit) : le nombre maximal de tentatives,
     * et le nombre de tentatives après lequel la solution est révélée.
     *
     * Masqué si la rétroaction générale l'est aussi : dans ce cas
     * l'étudiant ne verra de toute façon ni solution ni correction, et
     * annoncer un décompte n'apporterait rien.
     */
    protected function render_tries_warning(question_attempt $qa, $question,
            int $tries, bool $feedbackallowed): string {
        if ($question->gradingmode !== 'interactive' || !$feedbackallowed) {
            return '';
        }
        if ($qa->get_state()->is_finished()) {
            return '';
        }

        $limits = [];
        if (!empty($question->maxtries)) {
            $limits[] = (int) $question->maxtries;
        }
        if (!empty($question->showsolutions) && !empty($question->showsolutionsafter)) {
            $limits[] = (int) $question->showsolutionsafter;
        }
        if (empty($limits)) {
            return '';
        }

        $remaining = min($limits) - $tries;
        if ($remaining <= 0) {
            return '';
        }

        $message = ($remaining === 1)
            ? get_string('triesremainingone', 'qtype_webwork')
            : get_string('triesremaining', 'qtype_webwork', $remaining);

        return html_writer::tag('div', $message, [
            'class' => 'qtype-webwork-tries alert alert-danger py-2 my-2',
            'role' => 'status',
        ]);
    }

    /**
     * Résout une URL d'asset renvoyée par le renderer (souvent relative à sa
     * propre racine) en URL absolue.
     */
    protected function resolve_asset_url(string $serverurl, string $asset): string {
        if (preg_match('#^https?://#', $asset)) {
            return $asset;
        }
        return rtrim($serverurl, '/') . '/' . ltrim($asset, '/');
    }

    /**
     * Tableau de débogage réservé aux enseignants : graine WeBWorK utilisée
     * pour générer la question, plus l'historique complet des réponses
     * soumises. Permet de reproduire exactement la version d'un problème
     * qu'un étudiant a vue (le couple fichier .pg + graine est
     * déterministe), pour aller déboguer une question dont le code
     * comporte une erreur.
     *
     * Visible uniquement pour qui a la capacité mod/quiz:grade dans le
     * contexte courant (enseignants, correcteurs) -- jamais pour les
     * étudiants, qui ne doivent pas pouvoir prévisualiser une question via
     * sa graine.
     */
    protected function render_teacher_debug_info(question_attempt $qa): string {
        global $PAGE;

        if (!has_capability('mod/quiz:grade', $PAGE->context)) {
            return '';
        }

        $question = $qa->get_question();

        // La graine est figée dans la toute première étape (voir
        // qtype_webwork_question::apply_attempt_state) -- on la relit
        // depuis l'historique plutôt que de rappeler effective_seed(),
        // qui pourrait donner une valeur différente en mode "random".
        $seed = null;
        foreach ($qa->get_step_iterator() as $step) {
            $stored = $step->get_qt_var('_seed');
            if ($stored !== null) {
                $seed = $stored;
                break;
            }
        }
        if ($seed === null && isset($question->problemseed)) {
            $seed = $question->problemseed;
        }

        $rows = '';
        $stepnumber = 0;
        foreach ($qa->get_step_iterator() as $step) {
            $submitted = [];
            foreach ($step->get_qt_data() as $name => $value) {
                // On ignore les champs internes (_seed, etc.), les champs
                // compagnons de MathQuill, le jeton de session WeBWorK, et
                // les champs "previous_*" que WeBWorK utilise pour son
                // propre suivi -- on ne garde que les vraies réponses.
                if (strpos($name, '_') === 0
                        || strpos($name, 'MaThQuIlL_') === 0
                        || strpos($name, 'previous_') !== false
                        || strpos($name, 'sessionJWT') !== false) {
                    continue;
                }
                if ($value !== '' && $value !== null) {
                    $submitted[] = s($name) . ' = <code>' . s($value) . '</code>';
                }
            }
            if (empty($submitted)) {
                continue;
            }
            $stepnumber++;
            $fraction = $step->get_fraction();
            $rows .= html_writer::tag('tr',
                html_writer::tag('td', $stepnumber)
                . html_writer::tag('td', userdate($step->get_timecreated(), get_string('strftimedatetimeshort')))
                . html_writer::tag('td', implode('<br>', $submitted))
                . html_writer::tag('td', $fraction === null ? '—' : format_float($fraction, 2))
            );
        }

        $content = html_writer::tag('p',
            html_writer::tag('strong', get_string('debugseed', 'qtype_webwork') . ' : ')
            . html_writer::tag('code', s((string) $seed))
            . ' &nbsp; '
            . html_writer::tag('span', s($question->sourcefilepath ?? ''), ['class' => 'text-muted'])
        );

        if ($rows !== '') {
            $header = html_writer::tag('tr',
                html_writer::tag('th', '#')
                . html_writer::tag('th', get_string('debugwhen', 'qtype_webwork'))
                . html_writer::tag('th', get_string('debugresponse', 'qtype_webwork'))
                . html_writer::tag('th', get_string('debugfraction', 'qtype_webwork'))
            );
            $content .= html_writer::tag('table',
                html_writer::tag('thead', $header) . html_writer::tag('tbody', $rows),
                ['class' => 'generaltable table-sm']
            );
        } else {
            $content .= html_writer::tag('p',
                get_string('debugnoresponses', 'qtype_webwork'),
                ['class' => 'text-muted']
            );
        }

        return html_writer::tag('details',
            html_writer::tag('summary',
                get_string('debuginfo', 'qtype_webwork'),
                ['class' => 'text-primary fw-bold']
            ) . html_writer::tag('div', $content, ['class' => 'p-2']),
            ['class' => 'qtype-webwork-debug border rounded my-2 p-2 bg-light']
        );
    }

    public function specific_feedback(question_attempt $qa) {
        return $this->render_teacher_debug_info($qa);
    }

    public function correct_response(question_attempt $qa) {
        return '';
    }
}
