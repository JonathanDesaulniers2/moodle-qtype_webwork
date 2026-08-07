<?php
/**
 * Dépôt en lot de fichiers .pg dans la banque privée du renderer, à partir
 * d'une archive ZIP.
 *
 * ⚠️ RÉSERVÉ AUX ADMINISTRATEURS DE SITE, volontairement.
 *
 * Un fichier .pg n'est pas un document : c'est du code Perl que le renderer
 * EXÉCUTE. Autoriser son dépôt revient donc à accorder une capacité
 * d'exécution de code sur le serveur renderer. Un administrateur de site
 * Moodle dispose déjà de cette capacité par d'autres voies (installation de
 * plugins, etc.), ce qui n'élargit pas réellement la surface d'attaque --
 * ce ne serait PAS le cas pour un simple enseignant, d'où cette
 * restriction.
 *
 * Le renderer applique en plus sa propre validation côté serveur : le
 * chemin de destination doit commencer par "private/", ne peut pas
 * contenir "../", et doit se terminer par .pg ou .pl (voir
 * RenderApp/Controller/IO.pm, motif "privateOnlyPg").
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/question/type/webwork/classes/webwork_client.php');

admin_externalpage_setup('qtypewebworkupload');

$pageurl = new moodle_url('/question/type/webwork/upload.php');
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('uploadtitle', 'qtype_webwork'));

// Garde-fou supplémentaire : le réglage doit être activé, ET seul un
// administrateur de site peut aller plus loin (admin_externalpage_setup
// l'exige déjà, ceci est une défense en profondeur).
if (!is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'upload WeBWorK problems');
}
if (!get_config('qtype_webwork', 'allowupload')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('uploaddisabled', 'qtype_webwork'), 'warning');
    echo $OUTPUT->footer();
    die;
}

$serverurl = get_config('qtype_webwork', 'serverurl');
if (empty($serverurl)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('serverurlnotconfigured', 'qtype_webwork'), 'error');
    echo $OUTPUT->footer();
    die;
}

/**
 * Formulaire de dépôt : archive ZIP + dossier de destination.
 */
class qtype_webwork_upload_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'archive',
            get_string('uploadarchive', 'qtype_webwork'), null,
            ['accepted_types' => ['.zip'], 'maxbytes' => 50 * 1024 * 1024]);
        $mform->addRule('archive', null, 'required');
        $mform->addHelpButton('archive', 'uploadarchive', 'qtype_webwork');

        $mform->addElement('text', 'destination',
            get_string('uploaddestination', 'qtype_webwork'), ['size' => 50]);
        $mform->setType('destination', PARAM_PATH);
        $mform->addHelpButton('destination', 'uploaddestination', 'qtype_webwork');

        $mform->addElement('html', self::get_folder_browser_html());

        $mform->addElement('advcheckbox', 'overwrite',
            get_string('uploadoverwrite', 'qtype_webwork'), '', [], [0, 1]);
        $mform->setDefault('overwrite', 0);
        $mform->addHelpButton('overwrite', 'uploadoverwrite', 'qtype_webwork');

        $this->add_action_buttons(false, get_string('uploadsubmit', 'qtype_webwork'));
    }

    /**
     * Navigateur d'arborescence de private/, pour choisir le dossier de
     * destination sans avoir à le taper de mémoire.
     *
     * Contrairement à celui de l'importation, il propose AUSSI la racine de
     * private/ comme destination possible (déposer directement à la racine
     * est un cas légitime), et les dossiers proposés ne sont qu'une aide :
     * le champ reste librement modifiable pour créer une arborescence qui
     * n'existe pas encore.
     */
    protected static function get_folder_browser_html(): string {
        global $CFG, $PAGE;

        $ajaxurl = $CFG->wwwroot . '/question/type/webwork/ajax/browse.php';

        $html = html_writer::tag('button', get_string('importbrowse', 'qtype_webwork'), [
            'type' => 'button',
            'id' => 'wwupload_btn',
            'class' => 'btn btn-secondary btn-sm mb-2',
        ]);
        $html .= html_writer::tag('div', '', [
            'id' => 'wwupload_container',
            'style' => 'display:none; border:1px solid #ccc; max-height:320px;'
                . ' overflow:auto; padding:.5rem; margin-bottom:1rem; font-family:monospace;',
        ]);

        $strings = [
            'loading' => get_string('browseloading', 'qtype_webwork'),
            'empty' => get_string('uploadnosubfolder', 'qtype_webwork'),
            'error' => get_string('browseerror', 'qtype_webwork'),
            'select' => get_string('importselectfolder', 'qtype_webwork'),
            'rootlabel' => get_string('uploadrootfolder', 'qtype_webwork'),
        ];

        $js = 'document.addEventListener("DOMContentLoaded", function() {'
            . 'var btn = document.getElementById("wwupload_btn");'
            . 'var container = document.getElementById("wwupload_container");'
            . 'var input = document.querySelector("[name=\"destination\"]");'
            . 'if (!btn || !container || !input) { return; }'
            . 'var ajaxurl = ' . json_encode($ajaxurl) . ';'
            . 'var contextid = ' . json_encode((string) $PAGE->context->id) . ';'
            . 'var sesskey = ' . json_encode(sesskey()) . ';'
            . 'var S = ' . json_encode($strings) . ';'
            . 'function pickButton(path) {'
            . 'var b = document.createElement("button");'
            . 'b.type = "button";'
            . 'b.className = "btn btn-link btn-sm p-0";'
            . 'b.style.marginLeft = ".5rem";'
            . 'b.textContent = S.select;'
            . 'b.addEventListener("click", function(e) {'
            . 'e.preventDefault(); input.value = path; container.style.display = "none";'
            . '});'
            . 'return b;'
            . '}'
            . 'function fetchDir(path, target) {'
            . 'target.textContent = S.loading;'
            . 'fetch(ajaxurl + "?root=private&path=" + encodeURIComponent(path)'
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
            . 'row.appendChild(link); row.appendChild(pickButton(full)); row.appendChild(sub);'
            . 'target.appendChild(row);'
            . '});'
            . '})'
            . '.catch(function() { target.textContent = S.error; });'
            . '}'
            . 'btn.addEventListener("click", function() {'
            . 'var show = container.style.display === "none";'
            . 'container.style.display = show ? "block" : "none";'
            . 'if (!show) { return; }'
            // Ligne "racine de private/" en tête : déposer à la racine est
            // un cas légitime, que le simple parcours des sous-dossiers ne
            // permettrait pas de choisir.
            . 'container.innerHTML = "";'
            . 'var rootrow = document.createElement("div");'
            . 'rootrow.style.fontWeight = "bold";'
            . 'rootrow.appendChild(document.createTextNode("\u{1F4C2} " + S.rootlabel));'
            . 'rootrow.appendChild(pickButton(""));'
            . 'container.appendChild(rootrow);'
            . 'var tree = document.createElement("div");'
            . 'container.appendChild(tree);'
            . 'fetchDir("", tree);'
            . '});'
            . '});';

        return $html . html_writer::tag('script', $js);
    }
}

$form = new qtype_webwork_upload_form($pageurl);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('uploadtitle', 'qtype_webwork'));

if ($data = $form->get_data()) {
    require_sesskey();

    $client = new \qtype_webwork\webwork_client(
        $serverurl,
        (bool) get_config('qtype_webwork', 'selfsignedcert'),
        (string) get_config('qtype_webwork', 'sharedsecret')
    );

    // Récupère l'archive téléversée dans un fichier temporaire.
    $tmpdir = make_request_directory();
    $zippath = $tmpdir . '/archive.zip';
    if (!$form->save_file('archive', $zippath, true)) {
        echo $OUTPUT->notification(get_string('uploadnofile', 'qtype_webwork'), 'error');
        echo $OUTPUT->continue_button($pageurl);
        echo $OUTPUT->footer();
        die;
    }

    // Décompression dans un dossier temporaire (jamais directement vers le
    // renderer : on inspecte d'abord ce que l'archive contient).
    $extractdir = $tmpdir . '/extracted';
    mkdir($extractdir);
    $packer = get_file_packer('application/zip');
    $result = $packer->extract_to_pathname($zippath, $extractdir);
    if ($result === false) {
        echo $OUTPUT->notification(get_string('uploadbadzip', 'qtype_webwork'), 'error');
        echo $OUTPUT->continue_button($pageurl);
        echo $OUTPUT->footer();
        die;
    }

    // Destination : toujours sous private/, nettoyée de tout ".." et des
    // barres obliques superflues.
    $destination = trim((string) $data->destination, '/ ');
    $destination = str_replace('\\', '/', $destination);
    $parts = array_filter(explode('/', $destination), function ($p) {
        return $p !== '' && $p !== '.' && $p !== '..';
    });
    $destination = implode('/', $parts);
    $base = 'private' . ($destination !== '' ? '/' . $destination : '');

    $created = [];
    $skipped = [];
    $failed = [];

    // Parcours récursif des fichiers extraits.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractdir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $fileinfo) {
        if (!$fileinfo->isFile()) {
            continue;
        }
        $abs = $fileinfo->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($abs, strlen($extractdir))), '/');

        // Seuls les .pg/.pl nous intéressent : une archive contient souvent
        // aussi des images, des __MACOSX/, des .DS_Store, etc.
        if (!preg_match('#\.p[gl]$#i', $relative)) {
            continue;
        }
        if (strpos($relative, '__MACOSX/') === 0) {
            continue;
        }

        $target = $base . '/' . $relative;

        try {
            if (empty($data->overwrite) && $client->problem_exists($target)) {
                $skipped[] = $target;
                continue;
            }
            $contents = file_get_contents($abs);
            if ($contents === false) {
                $failed[] = ['path' => $target, 'reason' => get_string('uploadreadfail', 'qtype_webwork')];
                continue;
            }
            $client->write_problem($target, $contents);
            $created[] = $target;
        } catch (Exception $e) {
            $failed[] = ['path' => $target, 'reason' => $e->getMessage()];
        }
    }

    // Rapport.
    if (!empty($created)) {
        echo $OUTPUT->notification(
            get_string('uploadsummary', 'qtype_webwork', count($created)), 'success');
    } else {
        echo $OUTPUT->notification(get_string('uploadnothing', 'qtype_webwork'), 'warning');
    }

    $renderlist = function (array $entries, string $heading, string $class) use ($OUTPUT) {
        if (empty($entries)) {
            return;
        }
        echo $OUTPUT->heading($heading . ' (' . count($entries) . ')', 4);
        echo html_writer::start_tag('ul', ['class' => $class]);
        foreach ($entries as $entry) {
            $line = s(is_array($entry) ? $entry['path'] : $entry);
            if (is_array($entry) && !empty($entry['reason'])) {
                $line .= ' — ' . html_writer::tag('em', s($entry['reason']));
            }
            echo html_writer::tag('li', $line);
        }
        echo html_writer::end_tag('ul');
    };

    $renderlist($created, get_string('uploadlistcreated', 'qtype_webwork'), 'text-success');
    $renderlist($skipped, get_string('uploadlistskipped', 'qtype_webwork'), 'text-muted');
    $renderlist($failed, get_string('uploadlistfailed', 'qtype_webwork'), 'text-danger');

    echo $OUTPUT->continue_button($pageurl);
    echo $OUTPUT->footer();
    die;
}

echo html_writer::tag('p', get_string('uploadintro', 'qtype_webwork'));
echo $OUTPUT->notification(get_string('uploadsecuritywarning', 'qtype_webwork'), 'warning');
$form->display();
echo $OUTPUT->footer();
