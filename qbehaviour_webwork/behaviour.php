<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/behaviour/behaviourbase.php');

/**
 * Comportement de question personnalisé, conçu pour qtype_webwork.
 *
 * Proche de "adaptive" dans l'esprit (rétroaction immédiate à chaque
 * "Vérifier", bouton toujours actif pour une nouvelle tentative, pas
 * d'étape "Réessayer" intermédiaire), mais avec une règle de notation
 * différente : ce comportement ne conserve JAMAIS la "meilleure tentative"
 * -- chaque "Vérifier" remplace complètement la note précédente par celle
 * de la réponse actuelle, et surtout, au moment où le test est soumis,
 * SEULE la toute dernière réponse enregistrée est notée (voir
 * process_finish()), peu importe ce qui s'est passé lors des tentatives
 * intermédiaires.
 */
class qbehaviour_webwork extends question_behaviour_with_save {

    public function is_compatible_question(question_definition $question) {
        return $question instanceof question_automatically_gradable;
    }

    public function get_min_fraction() {
        if (!$this->question instanceof question_automatically_gradable) {
            throw new coding_exception('This behaviour only works with question_automatically_gradable questions.');
        }
        return $this->question->get_min_fraction();
    }

    public function get_max_fraction() {
        if (!$this->question instanceof question_automatically_gradable) {
            throw new coding_exception('This behaviour only works with question_automatically_gradable questions.');
        }
        return $this->question->get_max_fraction();
    }

    public function get_right_answer_summary() {
        return $this->question->get_right_answer_summary();
    }

    public function get_expected_data() {
        if ($this->qa->get_state()->is_active()) {
            return ['submit' => PARAM_BOOL];
        }
        return parent::get_expected_data();
    }

    /**
     * Contrairement aux comportements natifs (qui masquent toute la
     * rétroaction tant que la question n'est pas "terminée"), on ne masque
     * RIEN ici -- c'est précisément le but de ce comportement : rétroaction
     * immédiate à chaque "Vérifier", même si la question reste modifiable.
     * On ne touche qu'au readonly (pour garder le bouton "Vérifier" actif).
     */
    public function adjust_display_options(question_display_options $options) {
        $options->readonly = $this->qa->get_state()->is_finished();
    }

    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('finish')) {
            return $this->summarise_finish($step);
        } else if ($step->has_behaviour_var('submit')) {
            return $this->summarise_submit($step);
        } else {
            return $this->summarise_save($step);
        }
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        } else if ($pendingstep->has_behaviour_var('submit')) {
            return $this->process_submit($pendingstep);
        } else {
            return $this->process_save($pendingstep);
        }
    }

    /**
     * Traite un clic sur "Vérifier".
     *
     * - Mode interactif (question->gradingmode == 'interactive') : note la
     *   réponse ACTUELLE et affiche immédiatement la rétroaction
     *   correspondante -- remplace totalement l'état/la note de la
     *   tentative précédente. Peut figer la question si entièrement
     *   correcte / nombre max de tentatives atteint / solution affichée.
     *
     * - Mode différé (question->gradingmode == 'deferred') : enregistre
     *   simplement la réponse, SANS jamais la noter ni figer la question
     *   ici -- le bouton "Vérifier" sert seulement à afficher un aperçu
     *   neutre (voir qtype_webwork_renderer, qui masque systématiquement la
     *   correction tant que $qa->get_state()->is_finished() est faux). La
     *   vraie note n'est calculée qu'au moment de process_finish(), à
     *   partir de la toute DERNIÈRE réponse enregistrée.
     */
    public function process_submit(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);
            return question_attempt::KEEP;
        }

        $response = $pendingstep->get_qt_data();
        if ($this->question->is_same_response($this->qa->get_last_qt_data(), $response)) {
            // Réponse inchangée depuis la dernière tentative : on ne
            // recompte pas une nouvelle tentative (voir aussi
            // qtype_webwork_question::tries_so_far(), qui compte les étapes
            // réellement conservées dans l'historique).
            return question_attempt::DISCARD;
        }

        if ($this->question->gradingmode === 'deferred') {
            // On ne note PAS ici : seul un aperçu neutre est affiché (voir
            // qtype_webwork_renderer). La note réelle viendra de
            // process_finish(), à partir de la dernière réponse seulement.
            $pendingstep->set_state(question_state::$todo);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
            return question_attempt::KEEP;
        }

        list($fraction, $state) = $this->question->grade_response($response);
        $pendingstep->set_fraction($fraction);

        // On fige la question (état "terminé", champs verrouillés par
        // Moodle via adjust_display_options) si la réponse est entièrement
        // correcte, OU si le nombre maximal de tentatives (réglage de la
        // question) est atteint avec cette tentative-ci. Sinon, on reste en
        // "todo" -- l'étudiant peut continuer à modifier sa réponse jusqu'à
        // la fin du test ; seule la toute dernière réponse comptera au
        // final (voir process_finish()).
        // On détermine "parfaitement correct" nous-mêmes, avec une marge de
        // tolérance, plutôt que de comparer strictement à
        // question_state::$gradedright -- une comparaison stricte peut
        // échouer si WeBWorK renvoie un score du type 0.9999999 au lieu
        // d'exactement 1.0 (imprécision de calcul à virgule flottante,
        // surtout quand le score global est une moyenne de plusieurs
        // sous-réponses).
        $fullycorrect = ($fraction >= 0.999999);

        $triessofar = $this->question->tries_so_far($this->qa) + 1; // +1 : cette tentative-ci n'est pas encore dans l'historique.
        $maxreached = !empty($this->question->maxtries) && $triessofar >= $this->question->maxtries;
        $solutionshown = !empty($this->question->showsolutions)
            && $triessofar >= $this->question->showsolutionsafter;
        if ($fullycorrect || $maxreached || $solutionshown) {
            $pendingstep->set_state($fullycorrect ? question_state::$gradedright : $state);
        } else {
            $pendingstep->set_state(question_state::$todo);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));

        return question_attempt::KEEP;
    }

    /**
     * Appelé quand la tentative de TEST (pas juste la question) est
     * terminée. Note définitivement la question à partir de la toute
     * DERNIÈRE réponse enregistrée -- jamais à partir d'une "meilleure"
     * tentative précédente.
     */
    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $laststep = $this->qa->get_last_step();
        $response = $laststep->get_qt_data();

        if (empty($response) || !$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
            $pendingstep->set_fraction(null);
        } else {
            list($fraction, $state) = $this->question->grade_response($response);
            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state($state);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));

        return question_attempt::KEEP;
    }
}
