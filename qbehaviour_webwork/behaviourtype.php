<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/behaviour/behaviourtypebase.php');

/**
 * Déclare le comportement qbehaviour_webwork à Moodle. Requis par le moteur
 * de question pour tout plugin de comportement, même minimal.
 *
 * Ce comportement n'est pas "archétypal" : il n'est jamais destiné à
 * apparaître dans le menu déroulant "Comportement des questions" au niveau
 * du test -- il est toujours imposé par qtype_webwork_question::make_behaviour()
 * indépendamment de ce réglage.
 */
class qbehaviour_webwork_type extends question_behaviour_type {
    public function is_archetypal() {
        return false;
    }
}
