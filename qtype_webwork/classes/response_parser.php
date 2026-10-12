<?php
namespace qtype_webwork;

/**
 * Logique de parsing des réponses JSON du WeBWorK Standalone Renderer.
 *
 * Volontairement indépendante de Moodle (aucun require_once vers config.php,
 * aucune classe core utilisée) : elle peut être testée avec un simple script
 * `php ...` ou avec PHPUnit une fois disponible, sans démarrer Moodle.
 */
class response_parser {

    /**
     * Extrait du JSON renvoyé par le renderer : le fragment HTML du
     * formulaire, les noms des champs "notés" (AnSwEr*), la liste complète
     * des champs à faire transiter, et les ressources CSS/JS à charger.
     *
     * @param array $result JSON décodé (voir _format=json de /render-api)
     * @return array{html:string, css:string[], js:string[], fieldnames:string[], allfieldnames:string[]}
     */
    public static function parse(array $result): array {
        $fullhtml = self::fix_verb_delimiters($result['renderedHTML'] ?? '');

        if ($fullhtml !== '' && preg_match('/<form\b[^>]*id=["\']problemMainForm["\'][^>]*>(.*)<\/form>/is', $fullhtml, $m)) {
            $forminner = $m[1];
        } else {
            $forminner = $fullhtml;
        }

        $answerfields = $result['flags']['ANSWER_ENTRY_ORDER'] ?? [];

        $allfields = [];
        $dom = null;
        if ($forminner !== '') {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $forminner . '</div>');
            libxml_clear_errors();
            foreach (['input', 'select', 'textarea'] as $tag) {
                foreach ($dom->getElementsByTagName($tag) as $el) {
                    $name = $el->getAttribute('name');
                    if ($name !== '') {
                        $allfields[$name] = true;
                    }
                }
            }

            // Retire les boutons de soumission propres à WeBWorK (Preview My
            // Answers / Submit Answers / Show Correct Answers). Une fois le
            // <form> de WeBWorK enlevé, ces boutons se retrouvent imbriqués
            // dans le VRAI formulaire de la page Moodle et déclenchent une
            // soumission générique que Moodle ne sait pas gérer (page de
            // redirection cassée). Moodle fournit son propre bouton
            // "Vérifier"/"Terminer" séparément, on n'a jamais besoin des
            // boutons de WeBWorK.
            $xpath = new \DOMXPath($dom);
            foreach (iterator_to_array($xpath->query('//input[@type="submit"]')) as $node) {
                $node->parentNode->removeChild($node);
            }
            foreach (iterator_to_array($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " submit-buttons-container ")]')) as $node) {
                $node->parentNode->removeChild($node);
            }

            // Re-sérialise le HTML modifié (sans les boutons) pour l'affichage.
            $wrapper = $dom->getElementsByTagName('div')->item(0);
            if ($wrapper !== null) {
                $newhtml = '';
                foreach (iterator_to_array($wrapper->childNodes) as $child) {
                    $newhtml .= $dom->saveHTML($child);
                }
                $forminner = $newhtml;
            }
        }
        foreach (($result['flags']['KEPT_EXTRA_ANSWERS'] ?? []) as $name) {
            $allfields[$name] = true;
        }
        $allfields['sessionJWT'] = true;

        $css = [];
        $js = [];
        foreach (($result['resources']['assets'] ?? []) as $asset) {
            $path = strtok($asset, '?');
            // On ne charge PAS MathJax v3 (tex-svg.js / mathjax-config.js) :
            // dans ce contexte d'intégration (un fragment de page, pas une
            // page WeBWorK complète avec sa balise <base>), l'initialisation
            // interne de MathJax v3 s'est avérée trop fragile à fiabiliser.
            // Le plugin charge lui-même MathJax v2 de façon autonome (voir
            // qtype_webwork_renderer::get_mathjax_bootstrap()).
            if (stripos($path, 'tex-svg.js') !== false || stripos($path, 'mathjax-config') !== false) {
                continue;
            }
            if (preg_match('/\.css$/i', $path)) {
                $css[] = $asset;
            } else if (preg_match('/\.js$/i', $path)) {
                $js[] = $asset;
            }
        }

        return [
            'html' => $forminner,
            'css' => $css,
            'js' => self::sort_js_by_priority($js),
            'fieldnames' => array_values($answerfields),
            'allfieldnames' => array_keys($allfields),
        ];
    }

    /**
     * Réordonne les scripts JS selon leurs dépendances connues plutôt que
     * l'ordre arbitraire dans lequel ils apparaissent dans
     * resources.assets. Dans une vraie page WeBWorK, ces scripts sont
     * chargés avec l'attribut "defer" dans un ordre précis (jQuery avant
     * tout, configuration MathJax avant MathJax lui-même, Bootstrap avant
     * les scripts qui en dépendent comme feedback.js) -- mais
     * resources.assets ne préserve pas cet ordre, ce qui causait des
     * erreurs "bootstrap is not defined" / MathJax non configuré.
     */
    protected static function sort_js_by_priority(array $js): array {
        $priorityfor = function (string $url): int {
            $patterns = [
                '/\/jquery\.min\.js/i' => 0,
                '/\/jquery-ui\.min\.js/i' => 1,
                '/iframeResizer/i' => 2,
                '/mathjax-config/i' => 3,
                '/tex-svg\.js/i' => 4,
                '/bootstrap\.bundle/i' => 5,
                '/mathquill\.js/i' => 6, // La bibliothèque MathQuill elle-même, avant mqeditor.js qui en dépend.
            ];
            foreach ($patterns as $pattern => $prio) {
                if (preg_match($pattern, $url)) {
                    return $prio;
                }
            }
            return 100; // Tout le reste (feedback, problem, mqeditor, ...), en dernier.
        };
        usort($js, function ($a, $b) use ($priorityfor) {
            return $priorityfor($a) <=> $priorityfor($b);
        });
        return $js;
    }

    /**
     * Applique en UNE SEULE passe DOM (un seul chargement/sérialisation)
     * toutes les transformations nécessaires à l'affichage : injection des
     * valeurs, masquage indices/solutions, masquage de la correction,
     * désactivation des champs, renommage des champs. Utilisé par le
     * renderer à la place d'enchaîner les méthodes individuelles
     * (inject_values, strip_hints_and_solutions, etc.) qui, chacune,
     * refont un cycle complet de chargement/sérialisation HTML -- des
     * passes répétées risquent de progressivement corrompre les attributs
     * HTML complexes (comme le contenu encodé des bulles de
     * prévisualisation de WeBWorK), d'où cette version consolidée.
     *
     * @param string $html fragment HTML à transformer
     * @param array $ops tableau d'opérations, toutes optionnelles :
     *   'values' => [nom => valeur, ...] (voir inject_values)
     *   'showhints' => bool, 'showsolutions' => bool (voir strip_hints_and_solutions)
     *   'stripcorrectness' => bool (voir strip_correctness)
     *   'disable' => bool (voir disable_inputs)
     *   'namemap' => [ancien nom => nouveau nom, ...] (voir rename_fields, appliqué en dernier)
     *   'idmap' => [ancien nom => nouvel id, ...] (voir apply_rename_fields -- cas spécial MathQuill)
     *   'serverurl' => string (voir apply_resolve_image_urls -- images TikZ/graphiques générées dynamiquement)
     *   'entryassist' => string (voir apply_mark_foreign_entryassist -- MathQuill/MathView/None de cette question)
     *   'popovertoggle' => string (voir apply_rename_popover_toggle -- Moodle 5.x)
     *   'texdelimiters' => bool (voir apply_convert_math_scripts -- MathJax 4, Moodle 5.x)
     */
    public static function transform(string $html, array $ops): string {
        // WeBWorK code en dur le texte "Hint" ; simple remplacement de texte,
        // ne nécessite pas le DOM.
        $html = str_replace('>Hint<', '>Indice<', $html);

        $dom = self::load_fragment($html);
        if ($dom === null) {
            return $html;
        }
        $xpath = new \DOMXPath($dom);

        if (!empty($ops['values'])) {
            self::apply_inject_values($dom, $ops['values']);
        }
        if (array_key_exists('showhints', $ops) && array_key_exists('showsolutions', $ops)) {
            self::apply_strip_hints_and_solutions($xpath, (bool) $ops['showhints'], (bool) $ops['showsolutions']);
        }
        if (!empty($ops['stripcorrectness'])) {
            self::apply_strip_correctness($dom, $xpath);
        }
        if (!empty($ops['disable'])) {
            self::apply_disable_inputs($dom);
        }
        if (!empty($ops['namemap'])) {
            self::apply_rename_fields($dom, $ops['namemap'], $ops['idmap'] ?? []);
            self::apply_fix_graphtool_htmlinputid($dom, $ops['namemap']);
        }
        if (!empty($ops['serverurl'])) {
            self::apply_resolve_image_urls($dom, $ops['serverurl']);
        }
        if (isset($ops['entryassist'])) {
            self::apply_mark_foreign_entryassist($dom, $ops['entryassist']);
        }

        // Les bulles de prévisualisation de WeBWorK utilisent des balises
        // <script type="math/tex">...</script> à l'intérieur des attributs
        // data-bs-content/data-bs-title des boutons de rétroaction. Une fois
        // insérées dynamiquement dans le DOM (à l'ouverture de la bulle),
        // ces balises <script> restent inertes dans ce contexte
        // d'intégration -- le contenu mathématique n'apparaît jamais. On les
        // remplace ici par un <span data-mathjax-source="..."> que le JS du
        // renderer convertit explicitement via l'API MathJax.tex2svgPromise()
        // (plus fiable que de compter sur la reconnaissance de délimiteurs
        // \(...\), dont la configuration MathJax de WeBWorK ne dispose pas
        // forcément). On en profite aussi pour traduire "Preview of Your
        // Answer" (texte codé en dur par WeBWorK) en français.
        self::apply_fix_popover_content($xpath);

        if (!empty($ops['popovertoggle'])) {
            self::apply_rename_popover_toggle($xpath, (string) $ops['popovertoggle']);
        }
        if (!empty($ops['texdelimiters'])) {
            self::apply_convert_math_scripts($xpath);
        }

        return self::serialize_children($dom);
    }

    /**
     * Remplace les <script type="math/tex"> de PG par des délimiteurs
     * \(...\) et \[...\].
     *
     * PG (commit 4b3e564, celui de l'image du renderer) écrit toutes les
     * formules sous cette forme (PGbasicmacros.pl, mode d'affichage
     * « MathJax »), propre à MathJax v2. MathJax 3 et plus ne lisent plus
     * ces balises : sous Moodle 5.x (MathJax 4), sans cette conversion,
     * AUCUNE formule de l'énoncé ne s'affiche (« un rayon de . ») -- sans la
     * moindre erreur, puisque le navigateur ignore simplement ces scripts.
     *
     * On insère un nœud texte : le DOM échappe lui-même les < et & de la
     * formule à la sérialisation, et MathJax lit le texte décodé.
     */
    protected static function apply_convert_math_scripts(\DOMXPath $xpath): void {
        $query = '//script[starts-with(translate(@type, "MATHTEX", "mathtex"), "math/tex")]';
        foreach (iterator_to_array($xpath->query($query)) as $script) {
            $display = stripos($script->getAttribute('type'), 'mode=display') !== false;
            $tex = $script->textContent;
            $text = $display ? '\\[' . $tex . '\\]' : '\\(' . $tex . '\\)';
            $script->parentNode->replaceChild($script->ownerDocument->createTextNode($text), $script);
        }
    }

    /**
     * Rend lisibles les \verb de PG, délimités par le caractère de contrôle
     * U+001F.
     *
     * PG prévisualise les réponses textuelles (choix « A », mots...) par
     * \verb␟A␟. libxml supprime ce caractère dès le premier chargement DOM
     * (parse()), et MathJax recevait \verbA : un \verb délimité par « A »,
     * jamais refermé, affiché en LaTeX brut dans la bulle de
     * prévisualisation. Les expressions algébriques n'utilisent pas \verb,
     * d'où un défaut limité aux réponses textuelles. Appelée sur le HTML
     * brut, avant toute analyse DOM.
     */
    public static function fix_verb_delimiters(string $html): string {
        if (strpos($html, "\x1F") === false) {
            return $html;
        }
        return preg_replace_callback('/\\\\verb\x1F([^\x1F]*)\x1F/', function ($m) {
            // Délimiteurs sans signification en HTML (le texte est parfois
            // dans un attribut échappé : ni <, >, & ni guillemets).
            foreach (['|', '!', '+', '=', '@', ':', ';', '/', '*', '~', '-'] as $delimiter) {
                if (strpos($m[1], $delimiter) === false) {
                    return '\\verb' . $delimiter . $m[1] . $delimiter;
                }
            }
            $escaped = str_replace(['\\', '{', '}', '$', '#', '%', '^', '_', '~'],
                ['\\backslash ', '\\{', '\\}', '\\$', '\\#', '\\%', '\\^{}', '\\_', '\\~{}'], $m[1]);
            return '\\text{' . $escaped . '}';
        }, $html);
    }

    /**
     * Remplace data-bs-toggle="popover" par un attribut propre au plugin.
     *
     * Depuis Moodle 5.0 (Bootstrap 5), le thème Boost initialise lui-même
     * TOUS les [data-bs-toggle="popover"] de la page au chargement
     * (déclenchement au focus, contenu assaini) et rouvre, depuis un
     * écouteur de clic posé sur le document, toute bulle dont l'icône est
     * cliquée. Sous Moodle 4.x, il ne visait que l'ancien data-toggle, et
     * les icônes de rétroaction de WeBWorK lui échappaient. Laissé tel
     * quel, l'attribut ferait gérer chaque bulle WeBWorK à la fois par
     * Moodle et par le plugin : contenu mathématique effacé par
     * l'assainissement, et bulle impossible à refermer en recliquant sur
     * l'icône (Moodle la rouvre aussitôt).
     *
     * Bootstrap n'a pas besoin de data-bs-toggle pour créer un popover : le
     * contenu, le titre et le déclenchement restent lus dans les autres
     * data-bs-*, et feedback.js retrouve ses boutons par leur classe
     * (.ww-feedback-btn), pas par cet attribut.
     */
    protected static function apply_rename_popover_toggle(\DOMXPath $xpath, string $attribute): void {
        foreach (iterator_to_array($xpath->query('//*[@data-bs-toggle="popover"]')) as $node) {
            $node->removeAttribute('data-bs-toggle');
            $node->setAttribute($attribute, 'popover');
        }
    }

    protected static function apply_fix_popover_content(\DOMXPath $xpath): void {
        foreach (['data-bs-content', 'data-bs-title'] as $attr) {
            foreach (iterator_to_array($xpath->query("//*[@$attr]")) as $node) {
                $value = $node->getAttribute($attr);
                $changed = false;

                if (stripos($value, 'math/tex') !== false) {
                    $value = preg_replace_callback(
                        '#<script\b[^>]*type=["\']math/tex[^"\']*["\'][^>]*>(.*?)</script>#is',
                        function ($m) {
                            $source = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
                            $escaped = htmlspecialchars($source, ENT_QUOTES);
                            // Délimiteurs \(...\) : compatibles avec MathJax
                            // v2 chargé par le plugin (voir
                            // qtype_webwork_renderer::get_mathjax_bootstrap()).
                            return '<span class="ww-math-preview">\\(' . $escaped . '\\)</span>';
                        },
                        $value
                    );
                    $changed = true;
                }

                if (strpos($value, 'Preview of Your Answer') !== false) {
                    $value = str_replace('Preview of Your Answer', 'Prévisualisation', $value);
                    $changed = true;
                }

                if ($changed) {
                    $node->setAttribute($attr, $value);
                }
            }
        }
    }

    /**
     * Désactive (rend non modifiables) tous les champs de saisie de réponse
     * du fragment HTML -- utilisé quand la question doit être "figée"
     * (réponse entièrement correcte, solution affichée, ou nombre maximal de
     * tentatives atteint).
     */
    public static function disable_inputs(string $html): string {
        $dom = self::load_fragment($html);
        if ($dom === null) {
            return $html;
        }
        self::apply_disable_inputs($dom);
        return self::serialize_children($dom);
    }

    protected static function apply_disable_inputs(\DOMDocument $dom): void {
        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $el) {
                $type = strtolower($el->getAttribute('type'));
                if ($tag === 'input' && in_array($type, ['submit', 'hidden'], true)) {
                    continue; // Les boutons/champs cachés n'ont pas besoin d'être désactivés.
                }
                $el->setAttribute('disabled', 'disabled');
            }
        }
    }

    /**
     * Renomme les attributs name (et id, pour éviter les collisions si la
     * question apparaît plusieurs fois sur une page) selon une
     * correspondance ancien nom => nouveau nom.
     *
     * Utilisé par le renderer pour appliquer le préfixe propre à la
     * tentative Moodle ($qa->get_qt_field_name()) : sans ce préfixe, Moodle
     * ne reconnaît pas les champs comme appartenant à la question et traite
     * chaque soumission comme vide/inchangée.
     */
    public static function rename_fields(string $html, array $namemap, array $idmap = []): string {
        $dom = self::load_fragment($html);
        if ($dom === null) {
            return $html;
        }
        self::apply_rename_fields($dom, $namemap, $idmap);
        return self::serialize_children($dom);
    }

    /**
     * @param array $namemap ancien nom => nouveau nom, appliqué à l'attribut "name"
     *   -- TOUJOURS le préfixe standard de Moodle (get_qt_field_name), car
     *   Moodle reconnaît/conserve ses champs uniquement s'ils commencent
     *   par CE préfixe. Ne jamais réordonner ceci, même pour MathQuill.
     * @param array $idmap ancien nom => nouvel id, appliqué à l'attribut
     *   "id" SEULEMENT s'il diffère de ce que $namemap produirait -- utilisé
     *   uniquement pour les champs compagnons MathQuill
     *   ("MaThQuIlL_AnSwEr0001"), dont mqeditor.js retrouve le champ visible
     *   correspondant via `id.replace(/^MaThQuIlL_/, "")`, en supposant ce
     *   préfixe littéralement AU DÉBUT de l'id. Si l'id suivait le même
     *   préfixage que "name" (préfixe Moodle en premier), cette regex ne
     *   correspondrait plus et MathQuill échouerait silencieusement à
     *   s'initialiser. On garde donc "MaThQuIlL_" en tête de l'id
     *   uniquement, tout en gardant "name" conforme à ce que Moodle exige
     *   pour reconnaître et conserver la donnée soumise.
     */
    protected static function apply_rename_fields(\DOMDocument $dom, array $namemap, array $idmap = []): void {
        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $el) {
                $name = $el->getAttribute('name');
                if ($name !== '' && isset($namemap[$name])) {
                    $newname = $namemap[$name];
                    $wasidequal = ($el->getAttribute('id') === $name);
                    $el->setAttribute('name', $newname);
                    if ($wasidequal) {
                        $el->setAttribute('id', $idmap[$name] ?? $newname);
                    }
                }
            }
        }
    }

    /**
     * Corrige les identifiants liés à GraphTool, sur DEUX plans distincts :
     *
     * 1. La valeur "htmlInputId" codée en dur dans les balises <script>
     *    embarquées (window.graphTool("...graphbox", {htmlInputId:
     *    "AnSwEr0001", ...})). Ce script fait ensuite
     *    `document.getElementById(htmlInputId)` pour retrouver le champ de
     *    réponse caché où stocker le graphique tracé -- si cette valeur
     *    garde le nom WeBWorK original (non préfixé par Moodle), cette
     *    recherche échoue silencieusement, et GraphTool plante seulement au
     *    moment de VÉRIFIER la réponse (le graphique fonctionne
     *    visuellement mais ne soumet jamais rien).
     *
     * 2. Les id des <div> conteneurs de GraphTool eux-mêmes (ex.
     *    "AnSwEr0001_graphbox", "AnSwEr0001_student_ans_graphbox", et leurs
     *    descendants imbriqués comme "AnSwEr0001_graphbox_graph_ARIAlabel"),
     *    qui ne sont PAS des champs de formulaire et ne sont donc jamais
     *    touchés par apply_rename_fields(). Sur une page affichant
     *    PLUSIEURS questions GraphTool à la fois (ex. la page de relecture
     *    d'une tentative de quiz, contrairement à une page par question),
     *    ces id restent identiques d'une question à l'autre (WeBWorK
     *    nomme génériquement son premier champ de réponse "AnSwEr0001"),
     *    provoquant des id HTML DUPLIQUÉS -- chaque appel
     *    "document.getElementById()" ne trouve alors QUE le premier
     *    conteneur, faisant que plusieurs instances de GraphTool tentent
     *    de contrôler le MÊME graphique en même temps. Ceci a été observé
     *    causant un blocage complet du navigateur (page "ne répond pas"),
     *    vraisemblablement une boucle de mise à jour/redimensionnement
     *    entre instances qui se marchent sur les pieds.
     */
    protected static function apply_fix_graphtool_htmlinputid(\DOMDocument $dom, array $namemap): void {
        // Trie les clés du namemap par longueur décroissante, pour que
        // "MaThQuIlL_AnSwEr0001" (s'il existe) soit essayé avant
        // "AnSwEr0001" et ne soit jamais partiellement recouvert par erreur.
        $keys = array_keys($namemap);
        usort($keys, fn($a, $b) => strlen($b) <=> strlen($a));

        $renameprefixedid = function (string $id) use ($namemap, $keys): string {
            foreach ($keys as $orig) {
                if ($orig !== '' && strpos($id, $orig . '_') === 0) {
                    return $namemap[$orig] . '_' . substr($id, strlen($orig) + 1);
                }
                if ($orig !== '' && $id === $orig) {
                    return $namemap[$orig];
                }
            }
            return $id;
        };

        // 1. Renomme les id de TOUS les éléments dont l'id commence par un
        //    nom de champ connu suivi de "_" (conteneurs GraphTool et leurs
        //    descendants imbriqués -- large filet volontaire, puisque cette
        //    convention de nommage par préfixe n'est utilisée par aucun
        //    autre widget connu à ce jour).
        foreach ($dom->getElementsByTagName('*') as $el) {
            $id = $el->getAttribute('id');
            if ($id === '') {
                continue;
            }
            $newid = $renameprefixedid($id);
            if ($newid !== $id) {
                $el->setAttribute('id', $newid);
            }
        }

        // 2. Renomme les références correspondantes dans les <script>
        //    embarqués : le premier argument de graphTool(...) (le nom du
        //    conteneur) et "htmlInputId".
        foreach (iterator_to_array($dom->getElementsByTagName('script')) as $script) {
            $content = $script->textContent;
            if ($content === '' || (strpos($content, 'htmlInputId') === false && strpos($content, 'graphTool(') === false)) {
                continue;
            }
            $newcontent = preg_replace_callback(
                '/(htmlInputId\s*:\s*["\'])([^"\']+)(["\'])/',
                function ($m) use ($namemap) {
                    $mapped = $namemap[$m[2]] ?? $m[2];
                    return $m[1] . $mapped . $m[3];
                },
                $content
            );
            $newcontent = preg_replace_callback(
                '/(graphTool\(\s*[\'"])([^\'"]+)([\'"])/',
                function ($m) use ($renameprefixedid) {
                    return $m[1] . $renameprefixedid($m[2]) . $m[3];
                },
                $newcontent
            );
            if ($newcontent !== $content) {
                while ($script->firstChild) {
                    $script->removeChild($script->firstChild);
                }
                $script->appendChild($dom->createTextNode($newcontent));
            }
        }
    }

    /**
     * Réécrit les URL relatives des balises <img> (images TikZ/graphiques
     * générées dynamiquement par PGtikz.pl/PGlateximage.pl et autres, ex.
     * "pg_files/tmp/images/xxx.svg") en URL absolues pointant vers le
     * renderer. Sans ce traitement, ces chemins relatifs se résolvent par
     * rapport à l'URL de la page MOODLE (le HTML étant injecté directement
     * dans la page, sans iframe), pas celle du renderer -- provoquant un
     * 404 silencieux (l'image apparaît vide, aucune erreur PG/Perl
     * puisque le rendu côté serveur a réellement réussi).
     */
    protected static function apply_resolve_image_urls(\DOMDocument $dom, string $serverurl): void {
        foreach ($dom->getElementsByTagName('img') as $el) {
            $src = $el->getAttribute('src');
            if ($src === '' || preg_match('#^(https?:)?//#i', $src) || strpos($src, 'data:') === 0) {
                continue;
            }
            $el->setAttribute('src', rtrim($serverurl, '/') . '/' . ltrim($src, '/'));
        }
    }

    /**
     * MathQuill (mqeditor.js) et MathView (mathview.js) partagent EXACTEMENT
     * la même convention de classes CSS ("codeshard"/"latexentryfield") sur
     * les champs de réponse texte -- une classe générique posée par
     * WeBWorK sur TOUS les champs de ce type, peu importe l'éditeur
     * réellement configuré. Chacune de ces bibliothèques scanne, À SON
     * CHARGEMENT, TOUTE LA PAGE (document.querySelectorAll) pour s'attacher
     * à ces champs -- un modèle conçu à l'origine pour une page WeBWorK
     * classique n'affichant JAMAIS qu'un seul problème (et donc un seul
     * éditeur configuré) à la fois.
     *
     * Notre fonctionnalité de choix d'éditeur PAR QUESTION casse cette
     * hypothèse : une page Moodle affichant plusieurs questions aux
     * réglages "entryAssist" différents (ex. MathView sur l'une, MathQuill
     * sur une autre) charge alors LES DEUX bibliothèques simultanément, et
     * chacune s'accroche aveuglément aux champs de TOUTES les questions,
     * pas seulement les siennes -- provoquant des éditeurs erronés
     * affichés sur les mauvaises questions, et un ralentissement sévère du
     * navigateur (scan + double initialisation sur toute la page).
     *
     * On marque ici préventivement, avec l'attribut "data-mv-initialized"
     * (correspondant à la propriété JS "dataset.mvInitialized" que
     * mathview.js vérifie déjà -- voir patch_mathview_loop.pl côté
     * renderer, qui étend cette vérification à SES boucles de scan
     * initial, pas seulement son MutationObserver), les champs des
     * questions n'utilisant PAS MathView, pour que son scan les ignore
     * dès le départ.
     */
    protected static function apply_mark_foreign_entryassist(\DOMDocument $dom, string $entryassist): void {
        if ($entryassist === 'MathView') {
            return;
        }
        foreach (['codeshard', 'latexentryfield'] as $class) {
            foreach ($dom->getElementsByTagName('*') as $el) {
                $classattr = $el->getAttribute('class');
                if ($classattr === '') {
                    continue;
                }
                $classes = preg_split('/\s+/', $classattr);
                if (in_array($class, $classes, true)) {
                    $el->setAttribute('data-mv-initialized', 'true');
                }
            }
        }
    }

    /**
     * Réinjecte les valeurs déjà saisies par l'étudiant dans le gabarit HTML
     * vierge (tentative en cours, pas encore corrigée). Implémentation
     * basée sur le DOM plutôt que sur une expression régulière -- plus
     * robuste face aux variations d'ordre/format des attributs HTML.
     *
     * @param string $html fragment HTML (déjà extrait du <form>)
     * @param array $values tableau nom de champ => valeur à injecter
     */
    public static function inject_values(string $html, array $values): string {
        $dom = self::load_fragment($html);
        if ($dom === null) {
            return $html;
        }
        self::apply_inject_values($dom, $values);
        return self::serialize_children($dom);
    }

    protected static function apply_inject_values(\DOMDocument $dom, array $values): void {
        foreach ($dom->getElementsByTagName('input') as $el) {
            $name = $el->getAttribute('name');
            if ($name !== '' && array_key_exists($name, $values)) {
                $el->setAttribute('value', (string) $values[$name]);
            }
        }
    }

    /**
     * Retire les blocs indices/solutions du fragment HTML si non autorisés.
     * WeBWorK les place dans des <div class="hint accordion ..."> et
     * <div class="solution accordion ..."> à l'intérieur du formulaire.
     */
    public static function strip_hints_and_solutions(string $html, bool $showhints, bool $showsolutions): string {
        // WeBWorK code en dur le texte "Hint" dans l'entête de l'accordéon ;
        // on le traduit ici puisqu'aucun paramètre d'API ne permet de le
        // changer directement.
        $html = str_replace('>Hint<', '>Indice<', $html);

        if ($showhints && $showsolutions) {
            return $html; // Rien à retirer.
        }
        $dom = self::load_fragment($html);
        if ($dom === null) {
            return $html;
        }
        $xpath = new \DOMXPath($dom);
        self::apply_strip_hints_and_solutions($xpath, $showhints, $showsolutions);
        return self::serialize_children($dom);
    }

    protected static function apply_strip_hints_and_solutions(\DOMXPath $xpath, bool $showhints, bool $showsolutions): void {
        if ($showhints && $showsolutions) {
            return;
        }
        if (!$showhints) {
            self::remove_matches($xpath, '//div[contains(concat(" ", normalize-space(@class), " "), " hint ")]');
        }
        if (!$showsolutions) {
            self::remove_matches($xpath, '//div[contains(concat(" ", normalize-space(@class), " "), " solution ")]');
        }
    }

    /**
     * Retire les indicateurs de correction (couleurs correct/incorrect,
     * bulles de rétroaction, résumé de score) du fragment HTML *déjà corrigé*
     * -- utilisé quand la question est configurée pour ne pas révéler si la
     * réponse est correcte lors d'un "Vérifier" (mode "enregistrement
     * silencieux"). Les valeurs saisies par l'étudiant restent affichées.
     */
    public static function strip_correctness(string $html): string {
        $dom = self::load_fragment($html);
        if ($dom === null) {
            return $html;
        }
        $xpath = new \DOMXPath($dom);
        self::apply_strip_correctness($dom, $xpath);
        return self::serialize_children($dom);
    }

    protected static function apply_strip_correctness(\DOMDocument $dom, \DOMXPath $xpath): void {
        // Retire les classes "correct"/"incorrect" (couleurs) de TOUT élément
        // concerné (champs de saisie, icônes <i>, boutons), sans retirer
        // l'élément lui-même ni sa valeur -- on neutralise (grise), on ne
        // supprime pas, pour que la bulle de prévisualisation reste
        // accessible (l'étudiant doit pouvoir vérifier ce qu'il a saisi,
        // même quand on ne révèle pas si c'est correct).
        foreach (iterator_to_array($xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " correct ")'
                . ' or contains(concat(" ", normalize-space(@class), " "), " incorrect ")]')) as $node) {
            $classes = array_filter(
                preg_split('/\s+/', trim($node->getAttribute('class'))),
                function ($c) {
                    return $c !== 'correct' && $c !== 'incorrect';
                }
            );
            $node->setAttribute('class', implode(' ', $classes));
        }

        // Neutralise les boutons de rétroaction (Correct/Incorrect -> gris) :
        // couleur Bootstrap (bg-success/bg-danger -> bg-secondary) et classe
        // personnalisée de la bulle (data-bs-custom-class="... correct/
        // incorrect" -> retirée), sans retirer le bouton -- la
        // prévisualisation ("You Entered"/formule saisie) doit rester
        // consultable.
        foreach (iterator_to_array($xpath->query(
                '//button[contains(concat(" ", normalize-space(@class), " "), " ww-feedback-btn ")]')) as $node) {
            $classes = preg_split('/\s+/', trim($node->getAttribute('class')));
            $classes = array_map(function ($c) {
                return in_array($c, ['btn-success', 'btn-danger'], true) ? 'btn-secondary' : $c;
            }, $classes);
            $node->setAttribute('class', implode(' ', array_unique($classes)));

            $customclass = $node->getAttribute('data-bs-custom-class');
            if ($customclass !== '') {
                $tokens = array_filter(
                    preg_split('/\s+/', trim($customclass)),
                    function ($c) {
                        return $c !== 'correct' && $c !== 'incorrect';
                    }
                );
                $node->setAttribute('data-bs-custom-class', implode(' ', $tokens));
            }

            if ($node->getAttribute('aria-label') !== '') {
                $node->setAttribute('aria-label', get_string('previewtitle', 'qtype_webwork'));
            }

            // Le titre de la bulle annonce la correction en toutes lettres
            // ("Correct", "Incorrect", et leurs traductions "Correct",
            // "Erroné"...). Le simple remplacement de ">Correct<" ne suffit
            // pas : le libellé varie selon la langue du renderer, et n'est
            // pas toujours entouré des mêmes balises. On remplace donc le
            // titre ENTIER par un libellé neutre -- c'est le seul moyen sûr
            // de ne rien laisser filtrer.
            //
            // Important : ce titre est la seule information de correction
            // dans la bulle ; son contenu (data-bs-content) ne montre que
            // la réponse saisie et son rendu, jamais la correction.
            if ($node->getAttribute('data-bs-title') !== '') {
                $node->setAttribute('data-bs-title', get_string('previewtitle', 'qtype_webwork'));
            }
            // Certaines versions utilisent l'attribut "title" classique.
            if ($node->getAttribute('title') !== '') {
                $node->setAttribute('title', get_string('previewtitle', 'qtype_webwork'));
            }
        }

        // Retire le résumé (bandeau rouge/vert "X of the answers is NOT correct").
        self::remove_matches($xpath, '//div[@role="alert"]');

        // Retire les paragraphes de score ("You received a score of X% ...").
        // Repérés par leur texte, dans chaque langue que le renderer peut
        // renvoyer : avec l'image fr et language=fr-CA, ils arrivent en
        // français (catalogues fr-CA.renderer.po et fr-CA.po), et un filtre
        // anglais seul laissait le résultat visible en mode différé.
        $scoremarkers = [
            'received a score', 'partial credit',            // Anglais.
            'avez obtenu un résultat', 'partie des points',  // fr-CA (webwork-renderer).
        ];
        foreach (iterator_to_array($xpath->query('//p')) as $node) {
            $text = $node->textContent;
            foreach ($scoremarkers as $marker) {
                if (mb_stripos($text, $marker) !== false) {
                    $node->parentNode->removeChild($node);
                    break;
                }
            }
        }
    }

    /**
     * Charge un fragment HTML dans un DOMDocument, enveloppé dans un <div>
     * unique pour permettre une sérialisation propre ensuite.
     */
    protected static function load_fragment(string $html): ?\DOMDocument {
        if (trim($html) === '') {
            return null;
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');
        libxml_clear_errors();
        return $dom;
    }

    /**
     * Sérialise le contenu du <div> racine ajouté par load_fragment(), sans
     * la balise <div> elle-même.
     */
    protected static function serialize_children(\DOMDocument $dom): string {
        $wrapper = $dom->getElementsByTagName('div')->item(0);
        if ($wrapper === null) {
            return '';
        }
        $html = '';
        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $html .= $dom->saveHTML($child);
        }
        return $html;
    }

    /**
     * Supprime tous les nœuds correspondant à une requête XPath.
     */
    protected static function remove_matches(\DOMXPath $xpath, string $query): void {
        foreach (iterator_to_array($xpath->query($query)) as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /**
     * Extrait le score global 0..1 d'une réponse de check_answers().
     */
    public static function extract_score(array $result): float {
        $fraction = 0.0;
        if (isset($result['problem_result']['score'])) {
            $fraction = (float) $result['problem_result']['score'];
        } else if (isset($result['problem_state']['recorded_score'])) {
            $fraction = (float) $result['problem_state']['recorded_score'];
        }
        return max(0.0, min(1.0, $fraction));
    }
}
