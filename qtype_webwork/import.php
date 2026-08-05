<?php
/**
 * Importation en lot de problèmes WeBWorK depuis un dossier du renderer.
 *
 * Accessible depuis la banque de questions. Crée une question par fichier
 * .pg trouvé, en reproduisant l'arborescence des sous-dossiers sous forme
 * de sous-catégories.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/type/webwork/classes/webwork_client.php');
require_once($CFG->dirroot . '/question/type/webwork/classes/bulk_importer.php');
require_once($CFG->dirroot . '/question/type/webwork/classes/bulk_import_form.php');

// La catégorie peut être fournie de deux façons : soit directement en
// "categoryid", soit via le paramètre "cat" que Moodle promène partout
// dans la banque de questions, au format "<categoryid>,<contextid>" --
// accepter les deux permet de simplement remplacer "edit.php" par
// "type/webwork/import.php" dans l'URL courante.
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$cat = optional_param('cat', '', PARAM_RAW);
if (!$categoryid && $cat !== '' && strpos($cat, ',') !== false) {
    [$catid] = explode(',', $cat, 2);
    $categoryid = (int) $catid;
}
$confirmed = optional_param('confirmed', 0, PARAM_BOOL);
$courseid = optional_param('courseid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);

// Dernier repli : arrivé depuis l'onglet de la banque de questions sans
// catégorie explicite, on prend la catégorie par défaut du contexte
// (comportement des pages natives d'importation/exportation de Moodle).
if ($categoryid <= 0 && ($cmid || $courseid)) {
    require_login();
    if ($cmid) {
        $fallbackcontext = context_module::instance($cmid);
    } else {
        $fallbackcontext = context_course::instance($courseid);
    }
    $defaultcategory = $DB->get_record_select('question_categories',
        'contextid = :contextid AND parent = 0',
        ['contextid' => $fallbackcontext->id], '*', IGNORE_MULTIPLE);
    if ($defaultcategory) {
        // La catégorie racine ("top") ne peut pas contenir de questions --
        // on descend d'un niveau vers sa première sous-catégorie.
        $child = $DB->get_records_select('question_categories',
            'parent = :parent', ['parent' => $defaultcategory->id],
            'sortorder ASC, id ASC', '*', 0, 1);
        $categoryid = $child ? (int) reset($child)->id : (int) $defaultcategory->id;
    }
}

if ($categoryid <= 0) {
    require_login();
    throw new moodle_exception('importnocategory', 'qtype_webwork');
}

$category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
$context = context::instance_by_id($category->contextid, MUST_EXIST);

require_login();
require_capability('moodle/question:add', $context);

// Le cours et le module sont DÉDUITS du contexte de la catégorie plutôt
// que d'être exigés dans l'URL : c'est la seule source fiable. Se fier aux
// paramètres transmis menait à un retour vers le mauvais cours (ou vers le
// site) quand ils étaient absents de l'URL de départ.
$cmid = 0;
$courseid = SITEID;
if ($context->contextlevel == CONTEXT_MODULE) {
    $cmid = $context->instanceid;
    $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    $courseid = $cm->course;
    require_login($courseid, false, $cm);
} else if ($context->contextlevel == CONTEXT_COURSE) {
    $courseid = $context->instanceid;
    require_login($courseid, false);
} else {
    // Contexte système ou catégorie de cours : banque partagée.
    $coursecontext = $context->get_course_context(false);
    if ($coursecontext) {
        $courseid = $coursecontext->instanceid;
    }
}

$pageurl = new moodle_url('/question/type/webwork/import.php', ['categoryid' => $categoryid]);
if ($courseid) {
    $pageurl->param('courseid', $courseid);
}
if ($cmid) {
    $pageurl->param('cmid', $cmid);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('importtitle', 'qtype_webwork'));
$PAGE->set_heading(get_string('importtitle', 'qtype_webwork'));

// Retour vers la banque de questions d'origine.
$returnurl = new moodle_url('/question/edit.php', ['cat' => $categoryid . ',' . $category->contextid]);
if ($cmid) {
    $returnurl->param('cmid', $cmid);
} else if ($courseid) {
    $returnurl->param('courseid', $courseid);
}

$serverurl = get_config('qtype_webwork', 'serverurl');
if (empty($serverurl)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('serverurlnotconfigured', 'qtype_webwork'), 'error');
    echo $OUTPUT->continue_button($returnurl);
    echo $OUTPUT->footer();
    die;
}

$form = new \qtype_webwork\bulk_import_form($pageurl, [
    'categoryid' => $categoryid,
    'contextid' => $category->contextid,
    'categoryname' => $category->name,
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

$data = $form->get_data();

if ($data) {
    $client = new \qtype_webwork\webwork_client(
        $serverurl,
        (bool) get_config('qtype_webwork', 'selfsignedcert'),
        (string) get_config('qtype_webwork', 'sharedsecret')
    );

    $defaults = [
        'entryassist' => $data->entryassist,
        'gradingmode' => $data->gradingmode,
        'seedmode' => $data->seedmode,
        'problemseed' => 1,
        'showcorrectness' => 1,
        'showhints' => (int) $data->showhints,
        'showhintsafter' => 1,
        'showsolutions' => (int) $data->showsolutions,
        'showsolutionsafter' => 1,
        'showsolutionsaftertest' => 0,
        'maxtries' => 0,
    ];

    $path = trim((string) $data->path, '/');
    $root = ($data->root === 'library') ? 'library' : 'private';
    $recursive = !empty($data->recursive);

    // L'importation peut être longue (une requête HTTP par dossier, plus
    // une écriture en base par question) -- on lève les limites d'exécution
    // comme le fait Moodle pour ses propres importations de questions.
    core_php_time_limit::raise(600);
    raise_memory_limit(MEMORY_EXTRA);

    $importer = new \qtype_webwork\bulk_importer($client, $context);
    $createroot = !empty($data->createroot);
    $log = $importer->import($path, $root, $categoryid, $defaults, $recursive, $createroot);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('importresults', 'qtype_webwork'), 3);

    $created = count($log['created']);
    $skipped = count($log['skipped']);
    $failed = count($log['failed']);
    $newcats = count($log['categories']);

    if ($created > 0) {
        echo $OUTPUT->notification(
            get_string('importsummarycreated', 'qtype_webwork',
                (object) ['count' => $created, 'categories' => $newcats]),
            'success');
    } else {
        echo $OUTPUT->notification(get_string('importnothingcreated', 'qtype_webwork'), 'warning');
    }

    $renderlist = function (array $entries, string $heading, string $style) use ($OUTPUT) {
        if (empty($entries)) {
            return;
        }
        echo $OUTPUT->heading($heading . ' (' . count($entries) . ')', 4);
        echo html_writer::start_tag('ul', ['class' => $style]);
        foreach ($entries as $entry) {
            $line = s($entry['path'] ?? $entry);
            if (!empty($entry['reason'])) {
                $line .= ' — ' . html_writer::tag('em', s($entry['reason']));
            }
            echo html_writer::tag('li', $line);
        }
        echo html_writer::end_tag('ul');
    };

    $renderlist($log['created'], get_string('importlistcreated', 'qtype_webwork'), 'text-success');
    $renderlist($log['skipped'], get_string('importlistskipped', 'qtype_webwork'), 'text-muted');
    $renderlist($log['failed'], get_string('importlistfailed', 'qtype_webwork'), 'text-danger');

    echo $OUTPUT->continue_button($returnurl);
    echo $OUTPUT->footer();
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importtitle', 'qtype_webwork'), 2);
echo html_writer::tag('p', get_string('importintro', 'qtype_webwork'));
$form->display();
echo $OUTPUT->footer();
