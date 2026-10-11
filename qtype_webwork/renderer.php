<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/webwork/classes/response_parser.php');

class qtype_webwork_renderer extends qtype_renderer {

    /**
     * Attribut qui remplace data-bs-toggle="popover" dans le HTML du
     * renderer (voir response_parser::apply_rename_popover_toggle() et
     * amd/src/question.js).
     */
    const POPOVER_TOGGLE_ATTRIBUTE = 'data-qtype-webwork-toggle';

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

    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        $question = $qa->get_question();
        /** @var qtype_webwork_question $question */

        $output = html_writer::tag('div', $question->format_questiontext($qa), ['class' => 'qtext']);

        // MathJax n'est plus chargé ici : sous Moodle 5, le module AMD
        // qtype_webwork/question (voir la fin de cette méthode) obtient
        // MathJax 4 par le chargeur du filtre de Moodle, une seule instance
        // pour toute la page. Sous Moodle 4.x, le plugin chargeait MathJax
        // v2 lui-même, ce que le MathJax 4 du filtre de Moodle 5 rendrait
        // inutilisable (voir classes/mathjax.php).

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
            'popovertoggle' => self::POPOVER_TOGGLE_ATTRIBUTE,
            'texdelimiters' => true,
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

            // Le verrouillage de l'éditeur MathQuill des champs désactivés
            // (classe qtype-webwork-disabled) est posé par amd/src/question.js.
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

        // Scripts du renderer (MathQuill, MathView, GraphTool, rétroaction...).
        // Sous Moodle 5, ils ne sont plus émis en balises <script> : le
        // module AMD qtype_webwork/question les charge APRÈS avoir prêté le
        // Bootstrap de Moodle à WeBWorK et obtenu MathJax 4 (voir
        // amd/src/question.js). $PAGE->requires->js_call_amd() reste
        // utilisable à ce stade de la page, contrairement à ->css()/->js() :
        // les appels AMD sont émis en pied de page.
        //
        // Deux scripts du renderer sont écartés :
        // - MathJax v3 de WeBWorK (tex-svg.js/mathjax-config.js), déjà retiré
        //   par response_parser::parse() ;
        // - bootstrap.bundle : une seconde copie de Bootstrap 5 sur la page
        //   ferait ouvrir puis refermer aussitôt les menus de Moodle 5.
        $scripts = [];
        foreach ($render['js'] as $js) {
            if (preg_match('#/bootstrap\.bundle(\.min)?\.js#i', strtok($js, '?'))) {
                continue;
            }
            if (in_array($js, self::$emittedassets, true)) {
                continue;
            }
            self::$emittedassets[] = $js;
            $scripts[] = $this->resolve_asset_url($question->serverurl, $js);
        }
        $this->page->requires->js_call_amd('qtype_webwork/question', 'init', [[
            'scripts' => $scripts,
            'mathjax' => \qtype_webwork\mathjax::loader_params($question->serverurl),
        ]]);

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
     * Le décompte porte sur ce qui VERROUILLE RÉELLEMENT la question pour
     * cet étudiant (voir qbehaviour_webwork::process_submit) :
     *
     * - le nombre maximal de tentatives verrouille toujours, quelle que
     *   soit la visibilité de la rétroaction -- c'est une contrainte
     *   mécanique, et l'étudiant doit être averti avant de se retrouver
     *   bloqué sans explication ;
     * - le nombre de tentatives avant révélation de la solution ne compte
     *   QUE si la rétroaction générale est visible : sinon la solution ne
     *   sera de toute façon jamais montrée, et décompter dessus
     *   annoncerait un blocage qui n'aura pas lieu.
     *
     * Quand les deux s'appliquent, c'est la plus petite valeur qui gouverne.
     */
    protected function render_tries_warning(question_attempt $qa, $question,
            int $tries, bool $feedbackallowed): string {
        if ($question->gradingmode !== 'interactive') {
            return '';
        }
        if ($qa->get_state()->is_finished()) {
            return '';
        }

        $limits = [];
        if (!empty($question->maxtries)) {
            $limits[] = (int) $question->maxtries;
        }
        if ($feedbackallowed && !empty($question->showsolutions)
                && !empty($question->showsolutionsafter)) {
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
