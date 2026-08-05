<?php
namespace qtype_webwork;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Formulaire de l'importation en lot : choix de la banque, du dossier, et
 * des options appliquées aux questions créées.
 */
class bulk_import_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $categoryid = $this->_customdata['categoryid'];
        $contextid = $this->_customdata['contextid'];
        $categoryname = $this->_customdata['categoryname'];

        $mform->addElement('hidden', 'categoryid', $categoryid);
        $mform->setType('categoryid', PARAM_INT);
        $mform->addElement('hidden', 'contextid', $contextid);
        $mform->setType('contextid', PARAM_INT);

        $mform->addElement('static', 'targetcategory',
            get_string('importtargetcategory', 'qtype_webwork'), s($categoryname));

        $mform->addElement('select', 'root', get_string('importroot', 'qtype_webwork'), [
            'private' => get_string('importrootprivate', 'qtype_webwork'),
            'library' => get_string('importrootlibrary', 'qtype_webwork'),
        ]);
        $mform->setDefault('root', 'private');

        $mform->addElement('text', 'path', get_string('importpath', 'qtype_webwork'),
            ['size' => 60, 'placeholder' => 'JonathanDesaulniers/Algebre']);
        $mform->setType('path', PARAM_PATH);
        $mform->addHelpButton('path', 'importpath', 'qtype_webwork');

        $mform->addElement('html', $this->get_folder_browser_html($contextid));

        $mform->addElement('advcheckbox', 'createroot',
            get_string('importcreateroot', 'qtype_webwork'), '',
            [], [0, 1]);
        $mform->setDefault('createroot', 1);
        $mform->addHelpButton('createroot', 'importcreateroot', 'qtype_webwork');

        $mform->addElement('advcheckbox', 'recursive',
            get_string('importrecursive', 'qtype_webwork'), '',
            [], [0, 1]);
        $mform->setDefault('recursive', 1);
        $mform->addHelpButton('recursive', 'importrecursive', 'qtype_webwork');

        $mform->addElement('header', 'defaultsheader',
            get_string('importdefaultsheader', 'qtype_webwork'));

        $mform->addElement('select', 'entryassist',
            get_string('entryassist', 'qtype_webwork'), [
                'MathView' => get_string('entryassistmathview', 'qtype_webwork'),
                'MathQuill' => get_string('entryassistmathquill', 'qtype_webwork'),
                'None' => get_string('entryassistnone', 'qtype_webwork'),
            ]);
        $mform->setDefault('entryassist', 'MathView');

        $mform->addElement('select', 'gradingmode',
            get_string('gradingmode', 'qtype_webwork'), [
                'deferred' => get_string('gradingmodedeferred', 'qtype_webwork'),
                'interactive' => get_string('gradingmodeinteractive', 'qtype_webwork'),
            ]);
        $mform->setDefault('gradingmode', 'deferred');

        $mform->addElement('select', 'seedmode',
            get_string('problemseedmode', 'qtype_webwork'), [
                'random' => get_string('problemseedrandom', 'qtype_webwork'),
                'peruser' => get_string('problemseedperuser', 'qtype_webwork'),
                'fixed' => get_string('problemseedfixed', 'qtype_webwork'),
            ]);
        $mform->setDefault('seedmode', 'random');

        $mform->addElement('advcheckbox', 'showhints',
            get_string('showhints', 'qtype_webwork'), '', [], [0, 1]);
        $mform->setDefault('showhints', 0);

        $mform->addElement('advcheckbox', 'showsolutions',
            get_string('showsolutions', 'qtype_webwork'), '', [], [0, 1]);
        $mform->setDefault('showsolutions', 0);

        $this->add_action_buttons(true, get_string('importpreview', 'qtype_webwork'));
    }

    /**
     * Navigateur d'arborescence permettant de CHOISIR UN DOSSIER (et non un
     * fichier, contrairement à celui du formulaire de question) : chaque
     * dossier a un lien pour l'ouvrir et un bouton pour le sélectionner.
     *
     * Suit la banque choisie dans le menu déroulant « root » : changer de
     * banque réinitialise l'arborescence.
     */
    protected function get_folder_browser_html(int $contextid): string {
        global $CFG;

        $ajaxurl = $CFG->wwwroot . '/question/type/webwork/ajax/browse.php';

        $html = \html_writer::tag('button', get_string('importbrowse', 'qtype_webwork'), [
            'type' => 'button',
            'id' => 'wwimport_btn',
            'class' => 'btn btn-secondary btn-sm mb-2',
        ]);
        $html .= \html_writer::tag('div', '', [
            'id' => 'wwimport_container',
            'style' => 'display:none; border:1px solid #ccc; max-height:320px;'
                . ' overflow:auto; padding:.5rem; margin-bottom:1rem; font-family:monospace;',
        ]);

        $strings = [
            'loading' => get_string('browseloading', 'qtype_webwork'),
            'empty' => get_string('browseempty', 'qtype_webwork'),
            'error' => get_string('browseerror', 'qtype_webwork'),
            'select' => get_string('importselectfolder', 'qtype_webwork'),
        ];

        $js = 'document.addEventListener("DOMContentLoaded", function() {'
            . 'var btn = document.getElementById("wwimport_btn");'
            . 'var container = document.getElementById("wwimport_container");'
            . 'var input = document.querySelector("[name=\"path\"]");'
            . 'var rootsel = document.querySelector("[name=\"root\"]");'
            . 'if (!btn || !container || !input || !rootsel) { return; }'
            . 'var ajaxurl = ' . json_encode($ajaxurl) . ';'
            . 'var contextid = ' . json_encode((string) $contextid) . ';'
            . 'var sesskey = ' . json_encode(sesskey()) . ';'
            . 'var S = ' . json_encode($strings) . ';'
            . 'function fetchDir(path, target) {'
            . 'target.textContent = S.loading;'
            . 'fetch(ajaxurl + "?root=" + encodeURIComponent(rootsel.value)'
            . ' + "&path=" + encodeURIComponent(path)'
            . ' + "&contextid=" + encodeURIComponent(contextid)'
            . ' + "&sesskey=" + encodeURIComponent(sesskey))'
            . '.then(function(r) { return r.json(); })'
            . '.then(function(data) {'
            . 'target.innerHTML = "";'
            . 'if (data.error) { target.textContent = data.error; return; }'
            . 'var dirs = (data.items || []).filter(function(i) { return i.is_dir; });'
            . 'if (!dirs.length) { target.textContent = S.empty; return; }'
            . 'dirs.forEach(function(item) {'
            . 'var row = document.createElement("div");'
            . 'row.style.paddingLeft = "1rem";'
            . 'var full = (path ? path + "/" : "") + item.name;'
            . 'var link = document.createElement("a");'
            . 'link.href = "#";'
            . 'link.textContent = "\u{1F4C1} " + item.name;'
            . 'var pick = document.createElement("button");'
            . 'pick.type = "button";'
            . 'pick.className = "btn btn-link btn-sm p-0 ml-2";'
            . 'pick.style.marginLeft = ".5rem";'
            . 'pick.textContent = S.select;'
            . 'pick.addEventListener("click", function(e) {'
            . 'e.preventDefault();'
            . 'input.value = full;'
            . 'container.style.display = "none";'
            . '});'
            . 'var sub = document.createElement("div");'
            . 'sub.style.display = "none";'
            . 'var loaded = false;'
            . 'link.addEventListener("click", function(e) {'
            . 'e.preventDefault();'
            . 'if (sub.style.display === "none") {'
            . 'sub.style.display = "block";'
            . 'if (!loaded) { loaded = true; fetchDir(full, sub); }'
            . '} else { sub.style.display = "none"; }'
            . '});'
            . 'row.appendChild(link); row.appendChild(pick); row.appendChild(sub);'
            . 'target.appendChild(row);'
            . '});'
            . '})'
            . '.catch(function() { target.textContent = S.error; });'
            . '}'
            . 'btn.addEventListener("click", function() {'
            . 'var show = container.style.display === "none";'
            . 'container.style.display = show ? "block" : "none";'
            . 'if (show) { fetchDir("", container); }'
            . '});'
            // Changer de banque réinitialise l'arborescence affichée.
            . 'rootsel.addEventListener("change", function() {'
            . 'if (container.style.display !== "none") { fetchDir("", container); }'
            . '});'
            . '});';

        return $html . \html_writer::tag('script', $js);
    }
}
