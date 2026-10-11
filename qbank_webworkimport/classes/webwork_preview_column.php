<?php
namespace qbank_webworkimport;

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\column_base;

/**
 * Colonne « Aperçu » pour les questions WeBWorK dans la banque de questions.
 *
 * Les questions WeBWorK ont un champ "questiontext" VIDE (leur énoncé vit
 * dans le fichier .pg, sur le renderer) : la colonne native « Texte de la
 * question » n'affiche donc rien pour elles. Cette colonne comble ce vide.
 *
 * L'aperçu est chargé À LA DEMANDE, question par question : afficher
 * d'emblée l'aperçu de toutes les questions d'une catégorie exigerait un
 * appel HTTP au renderer PAR QUESTION à chaque chargement de page (soit
 * plusieurs dizaines de secondes sur une grosse catégorie, et autant de
 * charge inutile sur le renderer). Le repli/dépli garde la banque
 * instantanée tout en donnant un aperçu fidèle et jamais périmé -- rien
 * n'est mis en cache, chaque dépli reflète l'état réel du fichier .pg.
 */
class webwork_preview_column extends column_base {

    public function get_name(): string {
        return 'webworkpreview';
    }

    public function get_title(): string {
        return get_string('previewcolumn', 'qbank_webworkimport');
    }

    /**
     * Champs supplémentaires à charger avec chaque question : on a besoin du
     * type pour ne rien afficher sur les questions non-WeBWorK.
     */
    public function get_required_fields(): array {
        return ['q.id', 'q.qtype'];
    }

    /**
     * Hauteur de l'aperçu, en pixels (réglable par l'administrateur).
     * Au-delà, le contenu défile dans l'iframe -- voir get_toggle_script()
     * pour la justification du choix d'une hauteur fixe.
     */
    protected static function get_preview_height(): int {
        $h = (int) get_config('qbank_webworkimport', 'previewheight');
        return $h > 0 ? $h : 350;
    }

    /**
     * Largeur de la fenêtre d'aperçu, en pixels (réglable par
     * l'administrateur).
     *
     * L'aperçu déborde volontairement de sa colonne. Élargir la colonne
     * elle-même repousserait toutes les suivantes loin vers la droite,
     * pour rien : leur contenu tient sur une seule ligne, l'espace sous
     * elles est donc inutilisé. Le débordement occupe cet espace libre
     * sans déranger la mise en page du tableau -- un dégagement au-dessus
     * de la fenêtre (voir PREVIEW_TOP_GAP) garantit qu'elle passe bien
     * SOUS le contenu des autres colonnes.
     */
    protected static function get_preview_width(): int {
        $w = (int) get_config('qbank_webworkimport', 'previewwidth');
        return $w > 0 ? $w : 500;
    }

    /**
     * Dégagement au-dessus de la fenêtre d'aperçu, en pixels : laisse
     * passer le contenu des colonnes voisines (une ligne de texte) avant
     * que la fenêtre ne déborde sous elles.
     */
    const PREVIEW_TOP_GAP = 50;

    protected function display_content($question, $rowclasses): void {
        global $PAGE;

        if ($question->qtype !== 'webwork') {
            return;
        }

        $id = 'wwprev_' . (int) $question->id;
        $url = new \moodle_url('/question/type/webwork/ajax/preview.php', [
            'questionid' => (int) $question->id,
            'contextid' => $PAGE->context->id,
            'sesskey' => sesskey(),
        ]);

        echo \html_writer::tag('button', get_string('previewshow', 'qbank_webworkimport'), [
            'type' => 'button',
            'class' => 'btn btn-link btn-sm p-0 qbank-webwork-preview-toggle',
            'data-target-id' => $id,
            'data-preview-url' => $url->out(false),
            'data-preview-height' => self::get_preview_height(),
            'data-label-show' => get_string('previewshow', 'qbank_webworkimport'),
            'data-label-hide' => get_string('previewhide', 'qbank_webworkimport'),
        ]);
        echo \html_writer::tag('div', '', [
            'id' => $id,
            'class' => 'qbank-webwork-preview',
            'style' => 'display:none; width:' . self::get_preview_width() . 'px;'
                . ' margin-top:' . self::PREVIEW_TOP_GAP . 'px;',
        ]);

        // Le script n'est injecté qu'une fois par page, quel que soit le
        // nombre de questions listées.
        static $jsdone = false;
        if (!$jsdone) {
            $jsdone = true;
            echo $this->get_toggle_script();
        }
    }

    /**
     * Script de repli/dépli : crée l'iframe au premier dépli, puis se
     * contente de masquer/afficher.
     *
     * L'iframe a une HAUTEUR FIXE avec défilement interne, plutôt que de
     * s'adapter à son contenu. Un redimensionnement dynamique posait deux
     * problèmes : les lignes de la banque sautaient à chaque rendu de
     * MathJax ou de GraphTool, et surtout la hauteur ne faisait que
     * croître -- déplier puis replier une solution laissait un grand vide,
     * puisque scrollHeight reste gonflé une fois le contenu replié.
     */
    protected function get_toggle_script(): string {
        $js = <<<'JS'
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.qbank-webwork-preview-toggle');
    if (!btn) { return; }
    e.preventDefault();

    var box = document.getElementById(btn.dataset.targetId);
    if (!box) { return; }

    if (box.style.display !== 'none') {
        box.style.display = 'none';
        btn.textContent = btn.dataset.labelShow;
        return;
    }

    box.style.display = 'block';
    btn.textContent = btn.dataset.labelHide;

    // Créée une seule fois : rouvrir la page repart à zéro, donc l'aperçu
    // reflète toujours l'état réel du fichier .pg.
    if (box.dataset.loaded === '1') { return; }
    box.dataset.loaded = '1';

    var frame = document.createElement('iframe');
    frame.src = btn.dataset.previewUrl;
    frame.style.width = '100%';
    frame.style.height = btn.dataset.previewHeight + 'px';
    frame.style.border = '1px solid #dee2e6';
    frame.style.borderRadius = '.25rem';
    frame.setAttribute('title', btn.dataset.labelShow);
    box.appendChild(frame);
});
JS;
        return \html_writer::tag('script', $js);
    }
}
