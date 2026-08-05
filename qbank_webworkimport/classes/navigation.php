<?php
namespace qbank_webworkimport;

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\navigation_node_base;

/**
 * Ajoute un onglet « Importer WeBWorK » à la banque de questions.
 *
 * Moodle 4.x réserve l'extension de la banque de questions aux plugins de
 * type "qbank" -- c'est la raison d'être de ce petit plugin compagnon :
 * offrir un point d'entrée officiel et durable vers l'importation en lot
 * fournie par qtype_webwork, plutôt que d'injecter un bouton par des
 * moyens détournés (sélecteurs CSS devinés, JavaScript inséré au pied de
 * page) qui casseraient à la première mise à jour de Moodle.
 *
 * L'onglet apparaît à côté des onglets natifs « Questions », « Catégories »,
 * « Importation », « Exportation ».
 */
class navigation extends navigation_node_base {

    /**
     * Nom affiché sur l'onglet.
     */
    public function get_navigation_title(): string {
        return get_string('pluginname', 'qbank_webworkimport');
    }

    /**
     * Clé interne de l'onglet.
     */
    public function get_navigation_key(): string {
        return 'webworkimport';
    }

    /**
     * Cible de l'onglet : la page d'importation fournie par qtype_webwork.
     */
    public function get_navigation_url(): \moodle_url {
        return new \moodle_url('/question/type/webwork/import.php');
    }

    /**
     * L'onglet n'est visible que si le type de question WeBWorK est
     * installé et activé, et si l'utilisateur peut ajouter des questions.
     */
    public function get_navigation_capabilities(): ?array {
        return ['moodle/question:add'];
    }
}
