<?php
namespace qtype_webwork;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php'); // Nécessaire pour la classe \curl.

/**
 * Petit client HTTP pour l'API render-api du WeBWorK Standalone Renderer.
 *
 * Documentation de référence : https://github.com/openwebwork/renderer (section "Renderer API").
 */
class webwork_client {

    /** @var string ex: https://webwork.example.org */
    protected $serverurl;

    /** @var bool si vrai, désactive la vérification du certificat SSL (serveur en certificat auto-signé) */
    protected $selfsignedcert;

    /**
     * @var string secret transmis en en-tête HTTP X-WebWork-Secret pour les
     * appels de navigation (list_directory) -- voir l'aide du réglage
     * "sharedsecret" (Administration du site -> Types de question ->
     * WeBWorK) pour la configuration Caddy correspondante. Vide = pas
     * d'en-tête envoyé. NE PROTÈGE PAS /render-api (voir render() et
     * check_answers() ci-dessous, qui n'envoient pas ce secret -- cette
     * route doit être protégée séparément, au niveau réseau).
     */
    protected $sharedsecret;

    public function __construct(string $serverurl, bool $selfsignedcert = false, string $sharedsecret = '') {
        $this->serverurl = rtrim($serverurl, '/');
        $this->selfsignedcert = $selfsignedcert;
        $this->sharedsecret = $sharedsecret;
    }

    /**
     * Effectue un rendu initial (première présentation du problème à l'étudiant).
     *
     * @param string $sourcefilepath chemin relatif du .pg
     * @param int $problemseed
     * @param array $extra paramètres additionnels (isInstructor, showHints, ...)
     * @return array structure JSON décodée renvoyée par le renderer
     */
    public function render(
        string $sourcefilepath, int $problemseed, array $extra = [],
        string $language = 'en', string $entryassist = 'MathView'
    ): array {
        $params = array_merge([
            'sourceFilePath' => $sourcefilepath,
            'problemSeed'    => $problemseed,
            '_format'        => 'json',
            'outputFormat'   => 'default',
            'hideCheckAnswersButton' => 1, // On utilise le bouton "Vérifier" de Moodle, pas celui du renderer.
            'showSummary' => 0,
            // showHints vaut 1 par défaut côté API, mais showSolutions vaut 0
            // par défaut -- sans ce paramètre, WeBWorK omet complètement le
            // bloc solution du HTML. On demande toujours les deux et on gère
            // nous-mêmes leur visibilité (voir response_parser::strip_hints_and_solutions()).
            'showHints' => 1,
            'showSolutions' => 1,
            // Langue des messages internes de PG ("Correct", "Erroné",
            // "Variable non définie...", etc.) -- nécessite que le fichier
            // de traduction correspondant existe sur le renderer (voir
            // lib/PG/lib/WeBWorK/PG/Localize/<code>.po). N'affecte PAS le
            // contenu des problèmes eux-mêmes, seulement les chaînes
            // d'interface propres à WeBWorK.
            'language' => $language,
            // Éditeur d'aide à la saisie mathématique -- "MathQuill",
            // "MathView", ou toute autre valeur pour aucun éditeur assisté.
            // Nécessite le correctif patch_renderproblem.pl côté renderer
            // pour que "MathView" fonctionne réellement (voir le dépôt
            // GitHub du projet) -- sans lui, le renderer se rabat
            // silencieusement sur MathQuill peu importe cette valeur.
            'entryAssist' => $entryassist,
        ], $extra);

        return $this->post($params);
    }

    /**
     * Renvoie les réponses saisies par l'étudiant au renderer pour correction.
     *
     * $responses doit contenir les mêmes clés de champ (AnSwEr0001 etc.) que celles
     * générées par le rendu initial, plus le state éventuel (sessionJWT) si vous l'utilisez.
     *
     * @param string $sourcefilepath
     * @param int $problemseed
     * @param array $responses champs de réponse soumis (nom => valeur)
     * @param string $language code de langue transmis à PG (voir render()).
     * @param string $entryassist éditeur d'aide à la saisie (voir render()).
     * @return array JSON décodé, contient notamment problem_result / answers / score
     */
    public function check_answers(
        string $sourcefilepath, int $problemseed, array $responses,
        string $language = 'en', string $entryassist = 'MathView'
    ): array {
        $params = array_merge([
            'sourceFilePath' => $sourcefilepath,
            'problemSeed'    => $problemseed,
            '_format'        => 'json',
            'outputFormat'   => 'default',
            'showHints' => 1,
            'showSolutions' => 1,
            'language' => $language,
            'entryAssist' => $entryassist,
        ], $responses);

        return $this->post($params);
    }

    /**
     * Liste le contenu d'un dossier de la bibliothèque de problèmes, via une
     * route Caddy "file_server browse" configurée séparément par
     * l'administrateur (voir la documentation du plugin) -- PAS une
     * fonctionnalité du renderer WeBWorK lui-même, qui n'expose aucune API
     * de navigation de fichiers.
     *
     * @param string $path chemin relatif (depuis la racine de la bibliothèque) à lister, vide pour la racine
     * @return array liste d'éléments ['name' => ..., 'is_dir' => bool]
     */
    public function list_directory(string $path, string $root = 'library'): array {
        // Nettoyage basique du chemin (pas de remontée de dossier).
        $path = str_replace(['..', "\0"], '', $path);
        // "root" sélectionne le point de montage Caddy correspondant --
        // voir la documentation du plugin (Caddyfile : blocs handle_path
        // /library-browse/* et /private-browse/*, chacun pointant vers un
        // dossier différent partagé en lecture seule).
        $segment = ($root === 'private') ? 'private-browse' : 'library-browse';
        $url = $this->serverurl . '/' . $segment . '/' . ltrim($path, '/');

        $curl = new \curl();
        $headers = ['Accept: application/json'];
        // Envoyé UNIQUEMENT ici (navigation), jamais dans render()/check_answers()
        // -- voir la note sur $sharedsecret plus haut : /render-api reste
        // protégé au niveau réseau, pas par ce secret, pour ne pas casser
        // la fenêtre d'édition native du renderer (voir edit_webwork_form.php).
        if ($this->sharedsecret !== '') {
            $headers[] = 'X-WebWork-Secret: ' . $this->sharedsecret;
        }
        $curl->setHeader($headers);
        $curlopts = [
            'CURLOPT_TIMEOUT' => 10,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
        ];
        if ($this->selfsignedcert) {
            $curlopts['CURLOPT_SSL_VERIFYPEER'] = false;
            $curlopts['CURLOPT_SSL_VERIFYHOST'] = 0;
        }
        $curl->setopt($curlopts);
        $response = $curl->get($url);
        $info = $curl->get_info();
        if (!empty($curl->error) || empty($info['http_code']) || $info['http_code'] >= 400) {
            throw new \moodle_exception('connectionerror', 'qtype_webwork', '', $curl->error ?: $info['http_code']);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidresponse', 'qtype_webwork');
        }

        $items = [];
        foreach ($decoded as $entry) {
            $items[] = [
                'name' => $entry['name'] ?? '',
                'is_dir' => !empty($entry['is_dir']),
            ];
        }
        // Dossiers d'abord, puis ordre alphabétique.
        usort($items, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $items;
    }

    /**
     * Récupère le contenu brut d'une ressource du renderer (utilisé pour
     * post-traiter mathjax-config.js, voir webwork_client::fetch_asset()).
     */
    public function fetch_asset(string $url): string {
        $curl = new \curl();
        $curlopts = [
            'CURLOPT_TIMEOUT' => 10,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
        ];
        if ($this->selfsignedcert) {
            $curlopts['CURLOPT_SSL_VERIFYPEER'] = false;
            $curlopts['CURLOPT_SSL_VERIFYHOST'] = 0;
        }
        $curl->setopt($curlopts);
        $response = $curl->get($url);
        $info = $curl->get_info();
        if (!empty($curl->error) || empty($info['http_code']) || $info['http_code'] >= 400) {
            throw new \moodle_exception('connectionerror', 'qtype_webwork', '', $curl->error ?: $info['http_code']);
        }
        return $response;
    }

    /**
     * Écrit un fichier .pg dans la banque privée du renderer, via la route
     * POST /render-api/can (contrôleur IO::writer).
     *
     * Le renderer valide LUI-MÊME le chemin de destination : il doit
     * commencer par "private/", ne pas contenir "../", et se terminer par
     * .pg ou .pl (voir le motif "privateOnlyPg" dans
     * RenderApp/Controller/IO.pm). Cette validation côté serveur est notre
     * garantie principale -- même un bogue de ce plugin ne pourrait pas
     * faire écrire ailleurs. On valide quand même côté Moodle avant
     * l'envoi, pour donner un message d'erreur utile plutôt qu'un rejet
     * brut du serveur.
     *
     * Les dossiers intermédiaires manquants sont créés par le renderer.
     *
     * @param string $path chemin complet du fichier, commençant par "private/"
     * @param string $contents contenu du fichier .pg
     * @return string le chemin écrit, tel que confirmé par le renderer
     */
    public function write_problem(string $path, string $contents): string {
        if (!preg_match('#^private/#', $path) || strpos($path, '../') !== false
                || !preg_match('#\.p[gl]$#i', $path)) {
            throw new \moodle_exception('uploadinvalidpath', 'qtype_webwork', '', s($path));
        }

        $url = $this->serverurl . '/render-api/can';
        $response = $this->raw_post($url, [
            'writeFilePath' => $path,
            'problemSource' => $contents,
        ]);
        return trim($response);
    }

    /**
     * Indique si un fichier existe déjà à ce chemin dans la banque privée.
     *
     * Utilise la route de lecture brute (/render-api/tap -> IO::raw) : un 404/erreur signifie
     * que le fichier n'existe pas. Sert à prévenir l'écrasement silencieux
     * lors d'un dépôt en lot.
     */
    public function problem_exists(string $path): bool {
        try {
            $this->raw_post($this->serverurl . '/render-api/tap', ['sourceFilePath' => $path]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * POST vers une route arbitraire du renderer, renvoyant la réponse
     * BRUTE (texte). Les routes d'écriture ne répondent pas en JSON,
     * contrairement à /render-api -- d'où cette variante de post().
     */
    protected function raw_post(string $url, array $params): string {
        $curl = new \curl();
        $curlopts = [
            // Plus généreux que pour un rendu : l'écriture peut porter sur
            // des dizaines de fichiers à la suite.
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
        ];
        if ($this->selfsignedcert) {
            $curlopts['CURLOPT_SSL_VERIFYPEER'] = false;
            $curlopts['CURLOPT_SSL_VERIFYHOST'] = 0;
        }
        $curl->setopt($curlopts);
        $response = $curl->post($url, $params);

        $info = $curl->get_info();
        if (!empty($curl->error) || empty($info['http_code']) || $info['http_code'] >= 400) {
            throw new \moodle_exception('connectionerror', 'qtype_webwork', '',
                $curl->error ?: ($info['http_code'] ?? '?'));
        }
        return (string) $response;
    }

    /**
     * Requête POST brute vers /render-api.
     */
    protected function post(array $params): array {
        $url = $this->serverurl . '/render-api';

        $curl = new \curl();
        $curlopts = [
            'CURLOPT_TIMEOUT' => 15,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            // Force HTTP/1.1 : certains reverse proxies (ex. Caddy) négocient
            // HTTP/2 par défaut, ce qui peut causer un "Connection reset"
            // avec PHP/cURL sur des requêtes POST volumineuses. HTTP/1.1 est
            // plus simple et évite ce genre de surprise.
            'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
        ];
        if ($this->selfsignedcert) {
            // Le serveur renderer utilise un certificat auto-signé (ou non
            // reconnu) : on désactive la vérification côté client, en
            // s'appuyant sur la restriction d'accès réseau/pare-feu
            // recommandée par ailleurs pour la sécurité de cet appel.
            $curlopts['CURLOPT_SSL_VERIFYPEER'] = false;
            $curlopts['CURLOPT_SSL_VERIFYHOST'] = 0;
        }
        $curl->setopt($curlopts);
        $response = $curl->post($url, $params);

        $info = $curl->get_info();
        if (!empty($curl->error) || empty($info['http_code']) || $info['http_code'] >= 400) {
            throw new \moodle_exception('connectionerror', 'qtype_webwork', '', $curl->error ?: $info['http_code']);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidresponse', 'qtype_webwork');
        }

        return $decoded;
    }
}
