<?php
/**
 * Aperçu autonome d'une question WeBWorK, destiné à être affiché dans une
 * IFRAME depuis la banque de questions (voir
 * qbank_webworkimport\webwork_preview_column).
 *
 * Pourquoi une page complète plutôt qu'un fragment HTML ?
 *
 * Un problème WeBWorK n'est pas qu'un bloc de HTML : il s'accompagne de
 * feuilles de style et de scripts fournis par le renderer (MathJax,
 * GraphTool, MathView, l'accordéon des indices/solutions...). Injecter le
 * seul HTML dans la page de la banque donnait un résultat inerte : formules
 * non rendues, indices et solutions sans effet au clic, GraphTool absent.
 *
 * Charger ces ressources dans la page de la banque elle-même poserait deux
 * problèmes : conflit avec les scripts de Moodle (jQuery, Bootstrap,
 * MathJax du filtre), et chargement redondant pour chaque aperçu ouvert.
 * L'iframe isole complètement chaque aperçu -- c'est d'ailleurs le mode
 * d'intégration prévu à l'origine par WeBWorK.
 *
 * Rien n'est mis en cache côté navigateur : chaque ouverture reflète l'état
 * réel du fichier .pg.
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->dirroot . '/question/type/webwork/classes/webwork_client.php');

$questionid = required_param('questionid', PARAM_INT);
$contextid = required_param('contextid', PARAM_INT);

require_sesskey();

$context = context::instance_by_id($contextid, MUST_EXIST);
require_login();
// Même capacité que pour parcourir les banques de problèmes : il s'agit
// d'afficher le contenu d'une question à qui peut déjà l'ouvrir en édition.
require_capability('moodle/question:useall', $context);

/**
 * Rend une page d'erreur minimale (l'iframe n'a pas de mise en page Moodle).
 */
function qtype_webwork_preview_error(string $message): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<style>body{font:14px sans-serif;color:#a00;padding:1rem;margin:0}</style>'
        . '</head><body>' . s($message) . '</body></html>';
    die;
}

try {
    $question = question_bank::load_question($questionid);
} catch (Exception $e) {
    qtype_webwork_preview_error(get_string('previewnotfound', 'qbank_webworkimport'));
}

if (!($question instanceof qtype_webwork_question)) {
    qtype_webwork_preview_error(get_string('previewnotwebwork', 'qbank_webworkimport'));
}

$serverurl = get_config('qtype_webwork', 'serverurl');
if (empty($serverurl)) {
    qtype_webwork_preview_error(get_string('serverurlnotconfigured', 'qtype_webwork'));
}

try {
    // get_render_data() normalise la réponse brute du renderer (HTML, noms
    // de champs, feuilles de style, scripts) et partage le cache de rendu
    // du plugin : afficher un aperçu réchauffe le cache pour l'affichage
    // réel de la question, et inversement.
    $render = $question->get_render_data();
} catch (Exception $e) {
    qtype_webwork_preview_error($e->getMessage());
}

$html = $render['html'] ?? '';
if ($html === '') {
    qtype_webwork_preview_error(get_string('previewempty', 'qbank_webworkimport'));
}

// Champs désactivés (l'aperçu n'est pas interactif) et indicateurs de
// correction retirés ; les images générées (TikZ, etc.) sont résolues en
// URL absolues ; formules converties pour MathJax 4.
$html = \qtype_webwork\response_parser::transform($html, [
    'disable' => true,
    'stripcorrectness' => true,
    'serverurl' => $serverurl,
    'texdelimiters' => true,
]);

/**
 * Résout une URL d'asset du renderer en URL absolue (même logique que
 * qtype_webwork_renderer::resolve_asset_url).
 */
$resolveasset = function (string $asset) use ($serverurl): string {
    if (preg_match('#^https?://#', $asset)) {
        return $asset;
    }
    return rtrim($serverurl, '/') . '/' . ltrim($asset, '/');
};

// MathJax 4, comme sur les pages de test (voir classes/mathjax.php), mais
// chargé directement : l'iframe est une page autonome, sans le chargeur AMD
// de Moodle. Contrairement aux pages de Moodle, rien n'y rend les formules
// à notre place : on laisse MathJax traiter la page dès son démarrage.
// En mode local, configuration prudente (sans enrichissement sémantique,
// voir mathjax::without_enrichment()) : l'aperçu est un outil d'enseignant,
// inutile d'y ajouter une vérification CORS.
$mathjaxconfig = \qtype_webwork\mathjax::config($serverurl);
if (\qtype_webwork\mathjax::is_local()) {
    $mathjaxconfig = \qtype_webwork\mathjax::without_enrichment($mathjaxconfig);
}
$mathjaxconfig['startup']['typeset'] = true;
$mathjaxsrc = \qtype_webwork\mathjax::url($serverurl);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="<?php echo s(current_language()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php foreach (($render['css'] ?? []) as $css) { ?>
<link rel="stylesheet" href="<?php echo s($resolveasset($css)); ?>">
<?php } ?>
<script>
window.MathJax = <?php echo json_encode($mathjaxconfig); ?>;
</script>
<script src="<?php echo s($mathjaxsrc); ?>"></script>
<style>
    /* L'iframe a une hauteur fixe (voir webwork_preview_column) : le
       contenu plus long défile ici plutôt que d'être tronqué. */
    html, body { margin: 0; }
    body { padding: .75rem; font-family: sans-serif; background: #fff; overflow-y: auto; }
    /* L'aperçu n'est pas interactif : on masque ce qui inviterait à agir. */
    .webwork-problem input[type="submit"], .webwork-problem button[type="submit"] { display: none; }
</style>
</head>
<body>
<div class="webwork-problem"><?php echo $html; ?></div>
<?php
// problem.js suppose un contexte d'iframe WeBWorK et un formulaire
// englobant : on fournit le même leurre que le renderer principal, sans
// quoi ces scripts s'interrompent avant d'initialiser l'accordéon des
// indices/solutions et GraphTool.
?>
<div id="problemMainForm" style="display:none"></div>
<?php foreach (($render['js'] ?? []) as $js) { ?>
<script src="<?php echo s($resolveasset($js)); ?>"></script>
<?php } ?>
</body>
</html>
