<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/questiontypebase.php');

/**
 * Question type WeBWorK : la logique de rendu/notation est déléguée à un serveur
 * WeBWorK Standalone Renderer externe.
 */
class qtype_webwork extends question_type {

    public function extra_question_fields() {
        // Table + colonnes utilisées automatiquement par question_type::save_question_options()
        // pour tout ce qui n'a pas besoin d'un traitement spécial. serverurl,
        // sharedsecret et selfsignedcert n'y figurent PLUS : ce sont
        // désormais des réglages de SITE (voir settings.php), plus des
        // champs du formulaire de question -- voir initialise_question_instance().
        return ['qtype_webwork_options', 'sourcefilepath', 'seedmode', 'problemseed',
            'gradingmode', 'showcorrectness', 'showhints', 'showhintsafter', 'showsolutions',
            'showsolutionsafter', 'showsolutionsaftertest', 'maxtries', 'entryassist'];
    }

    public function questionid_column_name() {
        return 'questionid';
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);

        // serverurl/sharedsecret/selfsignedcert viennent des réglages de
        // SITE (Administration du site -> Plugins -> Types de question ->
        // webwork), pas du formulaire de la question -- un changement de
        // ces réglages s'applique donc immédiatement à TOUTES les questions
        // WeBWorK du site, sans avoir à les réenregistrer.
        $question->serverurl      = get_config('qtype_webwork', 'serverurl');
        $question->sharedsecret   = get_config('qtype_webwork', 'sharedsecret');
        $question->selfsignedcert = (bool) get_config('qtype_webwork', 'selfsignedcert');

        $question->sourcefilepath = $questiondata->options->sourcefilepath;
        $question->seedmode       = $questiondata->options->seedmode;
        $question->problemseed    = (int) $questiondata->options->problemseed;
        $question->gradingmode    = $questiondata->options->gradingmode;
        $question->showcorrectness       = (bool) $questiondata->options->showcorrectness;
        $question->entryassist           = $questiondata->options->entryassist ?? 'MathView';
        $question->showhints             = (bool) $questiondata->options->showhints;
        $question->showhintsafter        = (int) $questiondata->options->showhintsafter;
        $question->showsolutions         = (bool) $questiondata->options->showsolutions;
        $question->showsolutionsafter    = (int) $questiondata->options->showsolutionsafter;
        $question->showsolutionsaftertest = (bool) $questiondata->options->showsolutionsaftertest;
        $question->maxtries = (int) $questiondata->options->maxtries;
        // Le nombre de tentatives autorisées et le verrouillage des champs
        // (réponse correcte / solution affichée / maximum atteint) sont
        // entièrement gérés par notre comportement personnalisé
        // (question/behaviour/webwork) et par le renderer -- pas besoin
        // d'astuce basée sur les indices Moodle ici.
    }

    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('qtype_webwork_options', ['questionid' => $questionid]);
        parent::delete_question($questionid, $contextid);
    }

    // Ce type de question n'utilise pas le système standard de "answers"/"fractions"
    // de Moodle : la notation est déléguée au serveur externe.
    public function get_random_guess_score($questiondata) {
        return null;
    }
}
