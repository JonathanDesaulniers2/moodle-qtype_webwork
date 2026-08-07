<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/webwork/classes/webwork_client.php');
require_once($CFG->dirroot . '/question/type/webwork/classes/response_parser.php');

/**
 * Représente une instance de question WeBWorK pendant une tentative.
 *
 * Principe : le rendu HTML et la notation sont délégués à un serveur
 * WeBWorK Standalone Renderer externe (voir classes/webwork_client.php).
 * Le nom des champs de réponse générés par PG (AnSwEr0001, ...) est dynamique :
 * on le découvre en interrogeant le renderer, puis on le met en cache
 * (le couple fichier .pg + seed produit toujours les mêmes champs).
 */
class qtype_webwork_question extends question_graded_automatically {

    public $serverurl;
    public $sourcefilepath;
    public $seedmode;
    public $problemseed;
    public $sharedsecret;
    public $gradingmode;
    public $showcorrectness;
    public $entryassist;
    public $showhints;
    public $showhintsafter;
    public $showsolutions;
    public $showsolutionsafter;
    public $showsolutionsaftertest;
    public $maxtries;
    public $selfsignedcert;

    /**
     * Nombre de tentatives déjà soumises pour cette tentative de question
     * (nombre d'étapes portant une réponse évaluable soumise jusqu'ici,
     * qu'elles aient été "finalisées" ou non par le comportement).
     * Utilisé pour déterminer si les indices/solutions doivent apparaître
     * (options "après N tentatives").
     *
     * Approche volontairement simple et indépendante du comportement
     * (behaviour) utilisé : on compte les étapes où une réponse évaluable a
     * été soumise, plutôt que de dépendre du score final (qui, en mode
     * interactif, n'est souvent enregistré qu'une fois la question
     * "terminée" -- correcte ou tentatives épuisées -- pas à chaque essai
     * intermédiaire).
     */
    public function tries_so_far(question_attempt $qa): int {
        $tries = 0;
        foreach ($qa->get_step_iterator() as $step) {
            $data = $step->get_qt_data();
            if (!empty($data) && $this->is_gradable_response($data)) {
                $tries++;
            }
        }
        return $tries;
    }

    /**
     * Force le comportement (behaviour) de CETTE question, sans tenir compte
     * du réglage global "Comportement des questions" choisi au niveau du
     * test Moodle. On utilise notre propre comportement qbehaviour_webwork
     * pour les DEUX modes de notation (pas seulement l'interactif) : en
     * mode différé, on veut quand même un bouton "Vérifier" qui affiche un
     * aperçu neutre de la réponse (sans révéler si elle est correcte) --
     * une fonctionnalité que le comportement natif "deferredfeedback" de
     * Moodle n'offre pas du tout (il n'a aucune notion de bouton "Vérifier"
     * intermédiaire). Voir qbehaviour_webwork::process_submit() pour le
     * détail du traitement différent selon $this->question->gradingmode.
     *
     * En revanche, le MODE de notation (différé ou interactif) est déduit
     * du réglage du test, et non plus configuré par question : c'est
     * l'enseignant qui décide au niveau du test, comme pour tous les autres
     * types de question de Moodle. Voir resolve_gradingmode().
     */
    public function make_behaviour(question_attempt $qa, $preferredbehaviour) {
        // Mémorisé sur la question : le rendu et start_attempt() en ont
        // besoin, et cette méthode est le seul endroit où Moodle nous
        // transmet le comportement choisi au niveau du test.
        $this->gradingmode = self::resolve_gradingmode($preferredbehaviour);
        return question_engine::make_behaviour('webwork', $qa, $preferredbehaviour);
    }

    /**
     * Traduit le comportement choisi au niveau du test en mode de notation
     * WeBWorK.
     *
     * Seul "deferredfeedback" (« Rétroaction à posteriori ») donne le mode
     * différé : la réponse est enregistrée sans être corrigée avant la fin
     * de la tentative. TOUT le reste -- interactif, adaptatif, notation
     * manuelle, CBM, etc. -- donne le mode interactif, où l'étudiant reçoit
     * une correction immédiate à chaque « Vérifier ».
     *
     * Ce choix par défaut vers l'interactif est volontaire : un enseignant
     * qui choisit un comportement autre que « à posteriori » veut de la
     * rétroaction pendant la tentative, ce qui est précisément ce que le
     * mode interactif fournit.
     *
     * @param string|null $preferredbehaviour le comportement réglé sur le test
     * @return string 'deferred' ou 'interactive'
     */
    public static function resolve_gradingmode($preferredbehaviour): string {
        return ($preferredbehaviour === 'deferredfeedback') ? 'deferred' : 'interactive';
    }

    /** @var array|null cache mémoire du dernier rendu (html, fieldnames, css, js) */
    protected $renderdata = null;

    /**
     * Seed effectif pour l'utilisateur courant.
     */
    public function effective_seed(): int {
        if ($this->seedmode === 'peruser') {
            global $USER;
            // Dérive un entier stable et non prévisible à partir de l'id utilisateur + id question.
            return (abs(crc32($USER->id . ':' . $this->id)) % 100000) + 1;
        }
        if ($this->seedmode === 'random') {
            // Nouveau seed aléatoire à chaque appel -- n'est en pratique
            // calculé qu'une seule fois par tentative/aperçu, au moment de
            // start_attempt(), puis figé pour toute la durée de cette
            // tentative via apply_attempt_state() (voir plus bas).
            return random_int(1, 999999);
        }
        return $this->problemseed;
    }

    /**
     * Appelle (ou récupère depuis le cache applicatif) le rendu du problème.
     *
     * Public : appelée directement par le renderer (qtype_webwork_renderer),
     * pas seulement en interne.
     */
    public function get_render_data(): array {
        if ($this->renderdata !== null) {
            return $this->renderdata;
        }

        $language = get_config('qtype_webwork', 'language');
        if (empty($language)) {
            $language = 'en';
        }

        $cache = cache::make('qtype_webwork', 'renders');
        $cachekey = md5($this->serverurl . '|' . $this->sourcefilepath . '|' . $this->effective_seed()
            . '|' . $language . '|' . $this->entryassist);

        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            $this->renderdata = $cached;
            return $this->renderdata;
        }

        $client = new \qtype_webwork\webwork_client($this->serverurl, $this->selfsignedcert);
        $result = $client->render($this->sourcefilepath, $this->effective_seed(), [], $language, $this->entryassist);

        $parsed = $this->parse_render_result($result);
        $cache->set($cachekey, $parsed);
        $this->renderdata = $parsed;
        return $parsed;
    }

    /**
     * Récupère le rendu "corrigé" (avec classes correct/incorrect, bulles de
     * rétroaction, résumé) pour une réponse déjà soumise, en rappelant
     * check_answers() avec les mêmes champs.
     *
     * Le renderer WeBWorK est stateless (tout l'état de la tentative est
     * encapsulé dans sessionJWT/previous_* que $response contient déjà) :
     * rappeler check_answers() avec exactement les mêmes valeurs est donc
     * sans effet de bord (idempotent) et peut être fait autant de fois que
     * nécessaire pour le seul besoin d'affichage, sans faire progresser le
     * compteur de tentatives WeBWorK.
     *
     * @param array $response les champs déjà soumis (qt_data de la tentative)
     */
    public function get_graded_render_data(array $response): array {
        $language = get_config('qtype_webwork', 'language');
        if (empty($language)) {
            $language = 'en';
        }

        $cache = cache::make('qtype_webwork', 'renders');
        // Clé incluant un hachage de la réponse : chaque état de réponse
        // distinct a son propre rendu corrigé en cache.
        $cachekey = 'graded_' . md5($this->serverurl . '|' . $this->sourcefilepath . '|'
            . $this->effective_seed() . '|' . $language . '|' . $this->entryassist . '|' . serialize($response));

        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        $client = new \qtype_webwork\webwork_client($this->serverurl, $this->selfsignedcert);
        $result = $client->check_answers($this->sourcefilepath, $this->effective_seed(), $response, $language, $this->entryassist);

        $parsed = $this->parse_render_result($result);
        $cache->set($cachekey, $parsed);
        return $parsed;
    }

    /**
     * Extrait du JSON renvoyé par le renderer le fragment HTML, les champs à
     * faire transiter, et les ressources CSS/JS. Logique déléguée à
     * \qtype_webwork\response_parser (classe pure PHP, testable sans Moodle
     * -- voir tests/standalone/run_tests.php).
     *
     * Vérifié contre des réponses réelles du renderer (_format=json) le
     * 21/07/2026 : voir les clés 'renderedHTML', 'flags.ANSWER_ENTRY_ORDER',
     * 'flags.KEPT_EXTRA_ANSWERS', 'resources.assets', 'problem_result.score'.
     */
    protected function parse_render_result(array $result): array {
        return \qtype_webwork\response_parser::parse($result);
    }

    public function start_attempt(question_attempt_step $step, $variant) {
        // On fige le seed choisi (utile surtout en mode peruser, pour rester
        // cohérent même si la fonction de dérivation change plus tard).
        $step->set_qt_var('_seed', $this->effective_seed());

        // On fige aussi le mode de notation déduit du comportement du test,
        // pour deux raisons : il doit rester stable pour toute la durée de
        // la tentative même si l'enseignant change le réglage du test
        // entre-temps, et make_behaviour() n'est pas rappelé à chaque
        // requête (le rendu doit pouvoir le relire).
        $step->set_qt_var('_gradingmode', $this->gradingmode ?: 'interactive');
    }

    public function apply_attempt_state(question_attempt_step $step) {
        $seed = $step->get_qt_var('_seed');
        if ($seed !== null) {
            $this->seedmode = 'fixed';
            $this->problemseed = (int) $seed;
        }
        $mode = $step->get_qt_var('_gradingmode');
        if ($mode !== null && in_array($mode, ['deferred', 'interactive'], true)) {
            $this->gradingmode = $mode;
        }
    }

    public function get_expected_data() {
        $expected = [];
        // Tous les champs (réponses + champs compagnons MathQuill + sessionJWT
        // + previous_*), sinon Moodle les filtre du POST et l'état WeBWorK
        // (compteur de tentatives, etc.) se perd d'une page à l'autre.
        foreach ($this->get_render_data()['allfieldnames'] as $name) {
            $expected[$name] = PARAM_RAW;
        }
        return $expected;
    }

    public function is_complete_response(array $response) {
        foreach ($this->get_render_data()['fieldnames'] as $name) {
            if (!array_key_exists($name, $response) || $response[$name] === '') {
                return false;
            }
        }
        return !empty($this->get_render_data()['fieldnames']);
    }

    public function is_gradable_response(array $response) {
        foreach ($this->get_render_data()['fieldnames'] as $name) {
            if (array_key_exists($name, $response) && $response[$name] !== '') {
                return true;
            }
        }
        return false;
    }

    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return get_string('pleaseputananswer', 'question');
    }

    public function summarise_response(array $response) {
        $parts = [];
        foreach ($this->get_render_data()['fieldnames'] as $name) {
            if (!empty($response[$name])) {
                $parts[] = $response[$name];
            }
        }
        return implode('; ', $parts);
    }

    /**
     * Compare deux réponses pour savoir si elles sont "identiques" du point
     * de vue de la notation (utilisé par Moodle pour éviter de re-noter
     * inutilement, ou pour afficher "réponse inchangée"). On ne compare que
     * les champs réellement notés (AnSwEr*), pas les champs techniques
     * (sessionJWT, previous_*, MaThQuIlL_*) qui changent même si l'étudiant
     * n'a rien modifié.
     */
    public function is_same_response(array $prevresponse, array $newresponse) {
        foreach ($this->get_render_data()['fieldnames'] as $name) {
            if (!question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, $name)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Envoie les réponses au renderer pour notation et convertit le résultat
     * en (fraction, état) attendu par le moteur de question Moodle.
     */
    public function grade_response(array $response) {
        $language = get_config('qtype_webwork', 'language');
        if (empty($language)) {
            $language = 'en';
        }

        $client = new \qtype_webwork\webwork_client($this->serverurl, $this->selfsignedcert);

        // On transmet TOUT ce que Moodle a capturé (réponses + sessionJWT +
        // champs compagnons MathQuill + previous_*) : le renderer en a besoin
        // pour reconstituer l'état de la tentative (nombre d'essais, etc.).
        $result = $client->check_answers($this->sourcefilepath, $this->effective_seed(), $response, $language, $this->entryassist);

        // Confirmé sur une réponse réelle du renderer (21/07/2026) :
        // problem_result.score est un flottant 0..1 (score global du problème,
        // moyenne pondérée des scores par champ dans 'answers').
        $fraction = \qtype_webwork\response_parser::extract_score($result);

        return [$fraction, question_state::graded_state_for_fraction($fraction)];
    }

    public function get_correct_response() {
        // Optionnel : demanderait un appel avec showCorrectAnswers=1 /
        // isInstructor=1 et un parsing des bonnes réponses retournées.
        return null;
    }
}
