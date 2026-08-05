<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/edit_question_form.php');

class qtype_webwork_edit_form extends question_edit_form {

    protected function definition_inner($mform) {
        // Retire l'obligation (ajoutée par la classe de base pour TOUS les
        // types de question) de remplir le texte de la question -- ni côté
        // serveur (validation() ci-dessous) ni côté client (règle JS ajoutée
        // par la classe de base), puisque le contenu affiché vient de
        // WeBWorK et qu'on ne veut pas forcer à ajouter du texte superflu.
        if (isset($mform->_rules['questiontext'])) {
            unset($mform->_rules['questiontext']);
        }
        if (isset($mform->_required) && is_array($mform->_required)) {
            $mform->_required = array_values(array_diff($mform->_required, ['questiontext']));
        }

        $mform->addElement('text', 'sourcefilepath', get_string('sourcefilepath', 'qtype_webwork'), ['size' => 60]);
        $mform->setType('sourcefilepath', PARAM_PATH);
        $mform->addRule('sourcefilepath', null, 'required', null, 'client');
        $mform->addHelpButton('sourcefilepath', 'sourcefilepath', 'qtype_webwork');

        // Navigateur en arborescence (optionnel) pour choisir le fichier
        // .pg sans avoir à taper le chemin exact. S'appuie sur une route
        // Caddy "file_server browse" configurée séparément par
        // l'administrateur (voir l'aide du réglage "Dossier racine de la
        // bibliothèque", Administration du site -> Types de question ->
        // WeBWorK) -- le renderer WeBWorK lui-même n'expose aucune API de
        // navigation de fichiers.
        $mform->addElement('html', $this->get_library_browser_html());

        // Fenêtre surgissante intégrant l'interface web NATIVE du renderer
        // (celle accessible directement à https://<serverurl>) -- elle
        // permet de charger, éditer et surtout ENREGISTRER un fichier .pg à
        // un chemin donné, y compris un chemin qui n'existe pas encore
        // (créant ainsi un nouveau problème). Notre navigateur en
        // arborescence ci-dessus reste en LECTURE SEULE (Caddy
        // file_server) -- cette fenêtre est donc le seul moyen de créer ou
        // modifier le contenu d'un fichier depuis Moodle.
        $mform->addElement('html', $this->get_renderer_editor_html());

        $mform->addElement('select', 'seedmode', get_string('problemseedmode', 'qtype_webwork'), [
            'random'  => get_string('problemseedrandom', 'qtype_webwork'),
            'fixed'   => get_string('problemseedfixed', 'qtype_webwork'),
            'peruser' => get_string('problemseedperuser', 'qtype_webwork'),
        ]);
        $mform->setDefault('seedmode', 'random');
        $mform->addHelpButton('seedmode', 'problemseedmode', 'qtype_webwork');

        $mform->addElement('text', 'problemseed', get_string('problemseed', 'qtype_webwork'), ['size' => 10]);
        $mform->setType('problemseed', PARAM_INT);
        $mform->setDefault('problemseed', 1);
        $mform->hideIf('problemseed', 'seedmode', 'neq', 'fixed');

        $mform->addElement('select', 'gradingmode', get_string('gradingmode', 'qtype_webwork'), [
            'deferred'    => get_string('gradingmodedeferred', 'qtype_webwork'),
            'interactive' => get_string('gradingmodeinteractive', 'qtype_webwork'),
        ]);
        $mform->setDefault('gradingmode', 'deferred');
        $mform->addHelpButton('gradingmode', 'gradingmode', 'qtype_webwork');

        // Pénalité par essai incorrect, pertinente uniquement en mode
        // interactif (ce champ correspond à la colonne standard
        // 'penalty' de la table Moodle 'question').
        $mform->addElement('text', 'penalty', get_string('penalty', 'qtype_webwork'), ['size' => 5]);
        $mform->setType('penalty', PARAM_FLOAT);
        $mform->setDefault('penalty', 0.3333333);
        $mform->hideIf('penalty', 'gradingmode', 'eq', 'deferred');

        // showcorrectness/showhints/showsolutions s'appliquent aux DEUX
        // modes de notation désormais : en différé, "showcorrectness"
        // détermine si la correction est révélée une fois le test terminé
        // (jamais avant), et "showsolutions" fonctionne de la même façon
        // (voir showsolutionsafter/showsolutionsaftertest ci-dessous, dont
        // le sens diffère selon le mode).
        $mform->addElement('advcheckbox', 'showcorrectness', get_string('showcorrectness', 'qtype_webwork'));
        $mform->setDefault('showcorrectness', 1);
        $mform->addHelpButton('showcorrectness', 'showcorrectness', 'qtype_webwork');

        $mform->addElement('select', 'entryassist', get_string('entryassist', 'qtype_webwork'), [
            'MathView'  => get_string('entryassistmathview', 'qtype_webwork'),
            'MathQuill' => get_string('entryassistmathquill', 'qtype_webwork'),
            'None'      => get_string('entryassistnone', 'qtype_webwork'),
        ]);
        $mform->setDefault('entryassist', 'MathView');
        $mform->addHelpButton('entryassist', 'entryassist', 'qtype_webwork');

        $mform->addElement('advcheckbox', 'showhints', get_string('showhints', 'qtype_webwork'));
        $mform->setDefault('showhints', 0);

        $mform->addElement('text', 'showhintsafter', get_string('showhintsafter', 'qtype_webwork'), ['size' => 4]);
        $mform->setType('showhintsafter', PARAM_INT);
        $mform->setDefault('showhintsafter', 1);
        $mform->hideIf('showhintsafter', 'showhints', 'notchecked');

        $mform->addElement('advcheckbox', 'showsolutions', get_string('showsolutions', 'qtype_webwork'));
        $mform->setDefault('showsolutions', 0);

        // "Après N tentatives" n'a de sens qu'en mode interactif -- en mode
        // différé, les solutions ne sont de toute façon jamais montrées
        // avant la fin du test (voir qtype_webwork_renderer), donc ce
        // réglage-là serait sans effet.
        $mform->addElement('text', 'showsolutionsafter', get_string('showsolutionsafter', 'qtype_webwork'), ['size' => 4]);
        $mform->setType('showsolutionsafter', PARAM_INT);
        $mform->setDefault('showsolutionsafter', 1);
        $mform->hideIf('showsolutionsafter', 'gradingmode', 'eq', 'deferred');
        $mform->hideIf('showsolutionsafter', 'showsolutions', 'notchecked');

        // En mode différé, ce réglage est implicite (les solutions
        // n'apparaissent de toute façon qu'une fois le test terminé) --
        // masqué pour ce mode afin d'éviter la confusion.
        $mform->addElement('advcheckbox', 'showsolutionsaftertest', get_string('showsolutionsaftertest', 'qtype_webwork'));
        $mform->setDefault('showsolutionsaftertest', 0);
        $mform->hideIf('showsolutionsaftertest', 'gradingmode', 'eq', 'deferred');
        $mform->hideIf('showsolutionsaftertest', 'showsolutions', 'notchecked');

        $mform->addElement('text', 'maxtries', get_string('maxtries', 'qtype_webwork'), ['size' => 4]);
        $mform->setType('maxtries', PARAM_INT);
        $mform->setDefault('maxtries', 0);
        $mform->addHelpButton('maxtries', 'maxtries', 'qtype_webwork');
        $mform->hideIf('maxtries', 'gradingmode', 'eq', 'deferred');
    }

    public function qtype() {
        return 'webwork';
    }

    /**
     * Construit le bouton + conteneur du navigateur en arborescence pour
     * choisir le fichier .pg (voir sourcefilepath ci-dessus). Utilise du
     * JavaScript simple (sans dépendance AMD) qui appelle ajax/browse.php
     * à chaque expansion de dossier.
     */
    protected function get_library_browser_html(): string {
        $ajaxurl = (new moodle_url('/question/type/webwork/ajax/browse.php'))->out(false);
        $sesskey = sesskey();
        // Contexte RÉEL de la catégorie de questions (pas le contexte
        // système) -- transmis à ajax/browse.php pour que la vérification
        // de capacité y porte sur le bon contexte. Voir la note de
        // sécurité dans browse.php.
        $contextid = $this->context->id;

        $libraryroot = get_config('qtype_webwork', 'libraryroot');
        if ($libraryroot === false || $libraryroot === '') {
            $libraryroot = 'Library';
        }
        $privateroot = get_config('qtype_webwork', 'privateroot');
        if ($privateroot === false || $privateroot === '') {
            $privateroot = 'private';
        }

        // On génère deux instances du même widget (bouton + arborescence),
        // une par "banque" -- "library" pointe vers le contenu officiel de
        // la bibliothèque de problèmes (Library/..., lecture seule côté
        // Caddy), "private" vers le contenu personnalisé de l'enseignant
        // (private/..., voir la fenêtre "Créer / éditer un problème" pour
        // y écrire du nouveau contenu). Les deux widgets sont
        // indépendants : ouvrir l'un ne ferme pas l'autre.
        $html = $this->get_one_library_browser_html(
            'wwbrowse_library', 'library', $libraryroot,
            get_string('browsebuttonlibrary', 'qtype_webwork')
        );
        $html .= $this->get_one_library_browser_html(
            'wwbrowse_private', 'private', $privateroot,
            get_string('browsebuttonprivate', 'qtype_webwork')
        );

        $js = 'var qtypeWebworkAjaxUrl = ' . json_encode($ajaxurl) . ';'
            . 'var qtypeWebworkSesskey = ' . json_encode($sesskey) . ';'
            . 'var qtypeWebworkContextId = ' . json_encode($contextid) . ';';

        return $html . html_writer::tag('script', $js);
    }

    /**
     * Construit UNE instance du widget navigateur (bouton + arborescence),
     * pour une "banque" donnée ("library" ou "private" -- voir
     * webwork_client::list_directory() et le Caddyfile de référence,
     * blocs handle_path /library-browse/* et /private-browse/*).
     *
     * @param string $idprefix préfixe unique pour les ID d'éléments DOM (un widget par banque).
     * @param string $root 'library' ou 'private' -- transmis à ajax/browse.php.
     * @param string $pathprefix préfixe ajouté devant le chemin choisi (ex. "Library", "private").
     * @param string $buttonlabel texte du bouton.
     */
    protected function get_one_library_browser_html(
        string $idprefix, string $root, string $pathprefix, string $buttonlabel
    ): string {
        $html = html_writer::tag('button', $buttonlabel, [
            'type' => 'button',
            'id' => $idprefix . '_btn',
            'class' => 'btn btn-secondary btn-sm mb-2 mr-2',
        ]);
        $html .= html_writer::tag('div', '', [
            'id' => $idprefix . '_container',
            'style' => 'display:none; border:1px solid #ccc; max-height:300px;'
                . ' overflow:auto; padding:.5rem; margin-bottom:1rem; font-family:monospace;',
        ]);

        $js = 'document.addEventListener("DOMContentLoaded", function() {'
            . 'var btn = document.getElementById(' . json_encode($idprefix . '_btn') . ');'
            . 'var container = document.getElementById(' . json_encode($idprefix . '_container') . ');'
            . 'var input = document.querySelector("[name=\"sourcefilepath\"]");'
            . 'if (!btn || !container || !input) { return; }'
            . 'var root = ' . json_encode($root) . ';'
            . 'var pathprefix = ' . json_encode($pathprefix) . ';'
            . 'function fetchDir(path, target) {'
            . 'target.textContent = "' . get_string('browseloading', 'qtype_webwork') . '";'
            . 'fetch(qtypeWebworkAjaxUrl + "?root=" + encodeURIComponent(root)'
            . ' + "&path=" + encodeURIComponent(path)'
            . ' + "&contextid=" + encodeURIComponent(qtypeWebworkContextId)'
            . ' + "&sesskey=" + encodeURIComponent(qtypeWebworkSesskey))'
            . '.then(function(r) { return r.json(); })'
            . '.then(function(data) {'
            . 'target.innerHTML = "";'
            . 'if (data.error) { target.textContent = data.error; return; }'
            . 'if (!data.items.length) { target.textContent = "' . get_string('browseempty', 'qtype_webwork') . '"; return; }'
            . 'data.items.forEach(function(item) {'
            . 'var row = document.createElement("div");'
            . 'row.style.paddingLeft = "1rem";'
            . 'var full = (path ? path + "/" : "") + item.name;'
            . 'if (item.is_dir) {'
            . 'var link = document.createElement("a");'
            . 'link.href = "#";'
            . 'link.textContent = "\u{1F4C1} " + item.name;'
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
            . 'row.appendChild(link); row.appendChild(sub);'
            . '} else if (/\.pg$/i.test(item.name)) {'
            . 'var flink = document.createElement("a");'
            . 'flink.href = "#";'
            . 'flink.textContent = "\u{1F4C4} " + item.name;'
            . 'flink.addEventListener("click", function(e) {'
            . 'e.preventDefault();'
            . 'input.value = pathprefix ? (pathprefix + "/" + full) : full;'
            . 'container.style.display = "none";'
            . '});'
            . 'row.appendChild(flink);'
            . '} else { return; }'
            . 'target.appendChild(row);'
            . '});'
            . '})'
            . '.catch(function() { target.textContent = "' . get_string('browseerror', 'qtype_webwork') . '"; });'
            . '}'
            . 'btn.addEventListener("click", function() {'
            . 'var show = container.style.display === "none";'
            . 'container.style.display = show ? "block" : "none";'
            . 'if (show && !container.dataset.loaded) {'
            . 'container.dataset.loaded = "1";'
            . 'fetchDir("", container);'
            . '}'
            . '});'
            . '});';

        return html_writer::tag('div', $html) . html_writer::tag('script', $js);
    }

    /**
     * Construit le bouton + fenêtre surgissante (iframe) intégrant
     * l'interface web native du renderer WeBWorK standalone (celle
     * normalement accessible en visitant directement l'URL du serveur dans
     * un navigateur). Cette interface permet de charger un fichier .pg
     * existant, d'en écrire un nouveau à un chemin qui n'existe pas encore,
     * de prévisualiser le rendu, puis d'enregistrer -- toutes choses que
     * notre navigateur en arborescence (lecture seule) ne peut pas faire.
     *
     * IMPORTANT (voir aide affichée dans la fenêtre) : si le certificat du
     * renderer est auto-signé, l'enseignant doit d'abord avoir visité
     * l'URL du serveur directement dans un nouvel onglet et avoir accepté
     * l'avertissement de certificat au moins une fois -- sinon l'iframe
     * reste vide silencieusement (les navigateurs n'autorisent pas de
     * traiter un avertissement de certificat à l'intérieur d'une iframe).
     */
    protected function get_renderer_editor_html(): string {
        $serverurl = get_config('qtype_webwork', 'serverurl');
        if (empty($serverurl)) {
            return '';
        }
        $serverurl = rtrim($serverurl, '/');

        $html = html_writer::tag('button', get_string('editorbutton', 'qtype_webwork'), [
            'type' => 'button',
            'id' => 'qtype_webwork_editor_btn',
            'class' => 'btn btn-secondary btn-sm mb-2 ml-2',
        ]);

        $html .= html_writer::start_tag('div', [
            'id' => 'qtype_webwork_editor_overlay',
            'style' => 'display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);'
                . ' z-index:2000;',
        ]);
        $html .= html_writer::start_tag('div', [
            'style' => 'position:absolute; inset:3% 5%; background:#fff; border-radius:6px;'
                . ' display:flex; flex-direction:column; overflow:hidden;',
        ]);
        $html .= html_writer::start_tag('div', [
            'style' => 'padding:.5rem 1rem; border-bottom:1px solid #ccc; display:flex;'
                . ' justify-content:space-between; align-items:center; flex-shrink:0;',
        ]);
        $html .= html_writer::tag('strong', get_string('editormodaltitle', 'qtype_webwork'));
        $html .= html_writer::tag('button', '✕', [
            'type' => 'button',
            'id' => 'qtype_webwork_editor_close',
            'class' => 'btn btn-sm btn-secondary',
        ]);
        $html .= html_writer::end_tag('div');
        $html .= html_writer::tag('div', get_string('editorhelp', 'qtype_webwork'), [
            'style' => 'padding:.5rem 1rem; background:#fff8dc; border-bottom:1px solid #ccc;'
                . ' font-size:.9em; flex-shrink:0;',
        ]);
        $html .= html_writer::tag('iframe', '', [
            'id' => 'qtype_webwork_editor_iframe',
            'style' => 'flex:1 1 auto; border:0; width:100%;',
            'src' => 'about:blank',
        ]);
        $html .= html_writer::end_tag('div');
        $html .= html_writer::end_tag('div');

        $js = 'document.addEventListener("DOMContentLoaded", function() {'
            . 'var btn = document.getElementById("qtype_webwork_editor_btn");'
            . 'var overlay = document.getElementById("qtype_webwork_editor_overlay");'
            . 'var closebtn = document.getElementById("qtype_webwork_editor_close");'
            . 'var iframe = document.getElementById("qtype_webwork_editor_iframe");'
            . 'var input = document.querySelector("[name=\"sourcefilepath\"]");'
            . 'if (!btn || !overlay || !closebtn || !iframe) { return; }'
            . 'var serverurl = ' . json_encode($serverurl) . ';'
            . 'btn.addEventListener("click", function() {'
            // On tente de préremplir le chemin via un paramètre d'URL --
            // à titre indicatif seulement, l'interface du renderer peut ou
            // non en tenir compte selon sa version. Sans effet, il suffit
            // de taper/coller le chemin manuellement dans son propre champ.
            . 'var path = input ? input.value.trim() : "";'
            . 'var url = serverurl + (path ? "/?sourceFilePath=" + encodeURIComponent(path) : "/");'
            . 'iframe.src = url;'
            . 'overlay.style.display = "block";'
            . '});'
            . 'closebtn.addEventListener("click", function() {'
            . 'overlay.style.display = "none";'
            . 'iframe.src = "about:blank";'
            . '});'
            . '});';

        return html_writer::tag('div', $html) . html_writer::tag('script', $js);
    }

    /**
     * Le texte de la question est normalement obligatoire pour tous les
     * types de question Moodle -- on le rend optionnel ici, puisque le
     * contenu affiché vient de WeBWorK et qu'on ne veut pas forcer
     * l'enseignant à ajouter du texte superflu juste pour satisfaire cette
     * exigence générique.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        unset($errors['questiontext']);
        return $errors;
    }
}
