<?php
namespace qbank_webworkimport;

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\plugin_features_base;

/**
 * Déclare à Moodle ce que ce plugin ajoute à la banque de questions :
 * l'onglet d'importation (classe navigation) et la colonne d'aperçu
 * WeBWorK (classe webwork_preview_column).
 *
 * La logique d'importation elle-même vit dans qtype_webwork, dont ce
 * plugin n'est que le point d'entrée officiel côté banque de questions.
 */
class plugin_feature extends plugin_features_base {

    /**
     * Nœuds de navigation ajoutés à la banque de questions.
     *
     * @return \core_question\local\bank\navigation_node_base[]
     */
    public function get_navigation_node(): ?\core_question\local\bank\navigation_node_base {
        return new navigation();
    }

    /**
     * Colonnes ajoutées à la liste des questions.
     *
     * @param \core_question\local\bank\view $qbank
     * @return \core_question\local\bank\column_base[]
     */
    public function get_question_columns($qbank): array {
        return [new webwork_preview_column($qbank)];
    }
}
