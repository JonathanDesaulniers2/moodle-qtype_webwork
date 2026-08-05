<?php
/**
 * Tests autonomes pour qtype_webwork, sans Moodle ni PHPUnit.
 *
 * Utilité : valider la logique de parsing (classes/response_parser.php) --
 * la partie la plus délicate et la plus utile à couvrir par des tests --
 * avec un simple `php run_tests.php`, sans avoir besoin d'installer
 * PHPUnit, Composer, ou même de démarrer Moodle.
 *
 * Lancer depuis n'importe où :
 *   php tests/standalone/run_tests.php
 *
 * Ce script n'est PAS un remplacement des tests PHPUnit "officiels" que
 * nous écrirons plus tard (ceux-là couvriront aussi questiontype.php,
 * la sauvegarde en base, make_behaviour(), etc. -- des choses qui ont
 * réellement besoin de Moodle démarré). Il couvre uniquement la logique
 * PHP pure, qui est justement celle qui casse le plus facilement si le
 * schéma JSON du renderer change.
 */

require_once __DIR__ . '/../../classes/response_parser.php';

use qtype_webwork\response_parser;

$failures = 0;
$total = 0;

/**
 * Petite fonction d'assertion maison (pas besoin de PHPUnit pour ça).
 */
function check(string $label, $actual, $expected): void {
    global $failures, $total;
    $total++;
    if ($actual === $expected) {
        echo "  [OK]   $label\n";
    } else {
        $failures++;
        echo "  [FAIL] $label\n";
        echo "         attendu : " . var_export($expected, true) . "\n";
        echo "         obtenu  : " . var_export($actual, true) . "\n";
    }
}

function check_true(string $label, $actual): void {
    check($label, (bool) $actual, true);
}

function load_fixture(string $name): array {
    $path = __DIR__ . '/../fixtures/' . $name;
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Impossible de lire la fixture : $path");
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException("JSON invalide dans la fixture : $path");
    }
    return $data;
}

echo "=== qtype_webwork : tests autonomes (response_parser) ===\n\n";

// --- Fixture 1 : réponse partiellement notée (1 correcte / 1 vide) -------
echo "-- graded_response_1.json --\n";
$fixture1 = load_fixture('graded_response_1.json');
$parsed1 = response_parser::parse($fixture1);

check('fieldnames == [AnSwEr0001, AnSwEr0002]', $parsed1['fieldnames'], ['AnSwEr0001', 'AnSwEr0002']);
check_true('allfieldnames contient AnSwEr0001', in_array('AnSwEr0001', $parsed1['allfieldnames'], true));
check_true('allfieldnames contient MaThQuIlL_AnSwEr0002', in_array('MaThQuIlL_AnSwEr0002', $parsed1['allfieldnames'], true));
check_true('allfieldnames contient sessionJWT', in_array('sessionJWT', $parsed1['allfieldnames'], true));
check_true('allfieldnames contient previous_AnSwEr0001', in_array('previous_AnSwEr0001', $parsed1['allfieldnames'], true));
check('nombre de feuilles CSS == 8', count($parsed1['css']), 8);
check('nombre de scripts JS == 15 (tex-svg/mathjax-config filtrés, MathJax v2 chargé par le plugin)', count($parsed1['js']), 15);
$jspos = function ($needle) use ($parsed1) {
    foreach ($parsed1['js'] as $i => $url) {
        if (stripos($url, $needle) !== false) {
            return $i;
        }
    }
    return -1;
};
check_true('jquery.min.js chargé avant bootstrap.bundle', $jspos('jquery.min.js') < $jspos('bootstrap.bundle'));
check_true('tex-svg.js n\'est plus chargé (MathJax v2 du plugin utilisé à la place)', $jspos('tex-svg.js') === -1);
check_true('bootstrap.bundle chargé avant feedback.js (qui en dépend)', $jspos('bootstrap.bundle') < $jspos('feedback'));
check_true('le html extrait ne contient pas la balise <form> elle-même', strpos($parsed1['html'], '<form') === false);
check_true('le html extrait contient bien le champ AnSwEr0001', strpos($parsed1['html'], 'name="AnSwEr0001"') !== false);
check('score extrait == 0.5', response_parser::extract_score($fixture1), 0.5);

echo "\n-- graded_response_2.json --\n";
$fixture2 = load_fixture('graded_response_2.json');
$parsed2 = response_parser::parse($fixture2);

check('fieldnames == [AnSwEr0001, AnSwEr0002]', $parsed2['fieldnames'], ['AnSwEr0001', 'AnSwEr0002']);
check_true('le html corrigé contient la classe "correct"', strpos($parsed2['html'], 'class="correct') !== false);
check_true('le html corrigé contient la classe "incorrect"', strpos($parsed2['html'], 'class="incorrect') !== false);
check_true('allfieldnames contient problem-result-score (champ post-correction)',
    in_array('problem-result-score', $parsed2['allfieldnames'], true));
check('score extrait == 0.5', response_parser::extract_score($fixture2), 0.5);

// --- Cas limites ---------------------------------------------------------
echo "\n-- cas limites --\n";
check('extract_score() sur un tableau vide == 0.0', response_parser::extract_score([]), 0.0);
check('extract_score() plafonne à 1.0 si le renderer renvoie > 1',
    response_parser::extract_score(['problem_result' => ['score' => 1.5]]), 1.0);
check('extract_score() plancher à 0.0 si le renderer renvoie une valeur négative',
    response_parser::extract_score(['problem_result' => ['score' => -0.2]]), 0.0);
$emptyparsed = response_parser::parse([]);
check('parse() sur un tableau vide ne plante pas et renvoie fieldnames vide', $emptyparsed['fieldnames'], []);

// --- Nouvelles fonctions de masquage (indices/solutions/correction) ------
echo "\n-- strip_hints_and_solutions() / strip_correctness() --\n";

$sample = '<div id="problem_body">'
    . '<input class="correct codeshard" name="AnSwEr0001" value="3">'
    . '<button class="ww-feedback-btn btn-success" aria-label="Correct">x</button>'
    . '</div>'
    . '<p>You received a score of 50% for this attempt.</p>'
    . '<div role="alert"><div class="alert alert-danger">1 of the answers is NOT correct.</div></div>'
    . '<div class="hint accordion my-3"><div class="accordion-body">Indice secret</div></div>'
    . '<div class="solution accordion my-3"><div class="accordion-body">Solution secrète</div></div>';

$stripboth = response_parser::strip_hints_and_solutions($sample, false, false);
check_true('hints masqués : "Indice secret" absent', strpos($stripboth, 'Indice secret') === false);
check_true('solutions masquées : "Solution secrète" absente', strpos($stripboth, 'Solution secrète') === false);
check_true('le champ de réponse reste présent après masquage indices/solutions',
    strpos($stripboth, 'name="AnSwEr0001"') !== false);

$keepboth = response_parser::strip_hints_and_solutions($sample, true, true);
check_true('hints conservés si autorisés', strpos($keepboth, 'Indice secret') !== false);
check_true('solutions conservées si autorisées', strpos($keepboth, 'Solution secrète') !== false);

$onlyhints = response_parser::strip_hints_and_solutions($sample, true, false);
check_true('mode mixte : indice conservé', strpos($onlyhints, 'Indice secret') !== false);
check_true('mode mixte : solution retirée', strpos($onlyhints, 'Solution secrète') === false);

$nocorrectness = response_parser::strip_correctness($sample);
check_true('classe "correct" retirée', strpos($nocorrectness, 'class="correct') === false);
check_true('bouton de rétroaction TOUJOURS présent (neutralisé, pas retiré -- prévisualisation accessible)',
    strpos($nocorrectness, 'ww-feedback-btn') !== false);
check_true('bouton de rétroaction neutralisé (gris)', strpos($nocorrectness, 'btn-secondary') !== false);
check_true('classe de couleur "btn-success" retirée', strpos($nocorrectness, 'btn-success') === false);
check_true('résumé d\'alerte retiré', strpos($nocorrectness, 'NOT correct') === false);
check_true('paragraphe de score retiré', strpos($nocorrectness, 'received a score') === false);
check_true('la valeur saisie reste affichée malgré le masquage de la correction',
    strpos($nocorrectness, 'value="3"') !== false);

echo "\n-- rename_fields() / inject_values() --\n";
$renamed = response_parser::rename_fields($sample, ['AnSwEr0001' => 'q123:1_AnSwEr0001']);
check_true('le champ renommé porte le nouveau nom préfixé',
    strpos($renamed, 'name="q123:1_AnSwEr0001"') !== false);
check_true('l\'ancien nom non préfixé a disparu', strpos($renamed, 'name="AnSwEr0001"') === false);

$injected = response_parser::inject_values('<input name="AnSwEr0001" value="">', ['AnSwEr0001' => '42']);
check_true('inject_values() place bien la valeur dans le bon champ', strpos($injected, 'value="42"') !== false);

$disabled = response_parser::disable_inputs(
    '<input type="text" name="AnSwEr0001" value="3">'
    . '<input type="hidden" name="sessionJWT" value="x">'
    . '<input type="submit" name="submitAnswers" value="Go">'
);
check_true('le champ texte est désactivé', strpos($disabled, 'disabled') !== false
    && strpos($disabled, 'name="AnSwEr0001"') !== false);
check_true('le champ caché n\'est pas désactivé inutilement',
    substr_count($disabled, 'disabled') === 1);

// --- Résumé ---------------------------------------------------------------
echo "\n=== Résumé : " . ($total - $failures) . "/$total tests réussis ===\n";

if ($failures > 0) {
    echo "ÉCHEC : $failures test(s) en échec.\n";
    exit(1);
}

echo "Tous les tests sont passés.\n";
exit(0);
