<?php
namespace qbank_webworkimport;

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\plugin_features_base;

/**
 * Déclare à Moodle ce que ce plugin ajoute à la banque de questions.
 *
 * Ici, uniquement un onglet de navigation (voir la classe navigation) --
 * toute la logique d'importation vit dans qtype_webwork, dont ce plugin
 * n'est qu'un point d'entrée officiel.
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
}
