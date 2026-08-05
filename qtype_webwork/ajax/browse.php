<?php
/**
 * Retourne (en JSON) le contenu d'un dossier de la bibliothèque de
 * problèmes WeBWorK, pour alimenter le navigateur en arborescence du
 * formulaire de création de question. S'appuie sur une route Caddy
 * "file_server browse" configurée séparément par l'administrateur (le
 * renderer WeBWorK lui-même n'expose aucune API de navigation de fichiers).
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/question/type/webwork/classes/webwork_client.php');

require_login();
require_sesskey();

$contextid = required_param('contextid', PARAM_INT);
$context = context::instance_by_id($contextid, IGNORE_MISSING);
if (!$context) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => get_string('invalidcontext', 'error')]);
    die;
}
// On vérifie la capacité au contexte RÉEL de la catégorie de questions
// (transmis par edit_webwork_form.php), pas au contexte système -- une
// version précédente vérifiait au contexte système, ce qui échouait pour
// des enseignants n'ayant cette capacité qu'au niveau de leur catégorie de
// questions. Vérifier au bon contexte évite à la fois ce faux-positif ET
// le problème inverse (n'importe quel utilisateur connecté, y compris un
// étudiant, pouvant parcourir le contenu des banques de problèmes).
require_capability('moodle/question:add', $context);

$path = optional_param('path', '', PARAM_PATH);
$root = optional_param('root', 'library', PARAM_ALPHA);
if (!in_array($root, ['library', 'private'], true)) {
    $root = 'library';
}

header('Content-Type: application/json');

$serverurl = get_config('qtype_webwork', 'serverurl');
if (empty($serverurl)) {
    http_response_code(400);
    echo json_encode(['error' => get_string('serverurlnotconfigured', 'qtype_webwork')]);
    die;
}

$selfsignedcert = (bool) get_config('qtype_webwork', 'selfsignedcert');
$sharedsecret = (string) get_config('qtype_webwork', 'sharedsecret');
$client = new \qtype_webwork\webwork_client($serverurl, $selfsignedcert, $sharedsecret);

try {
    $items = $client->list_directory($path, $root);
    echo json_encode(['items' => $items]);
} catch (\Exception $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}
