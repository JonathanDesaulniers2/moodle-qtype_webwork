<?php
namespace qtype_webwork;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/question/engine/bank.php');
require_once($GLOBALS['CFG']->dirroot . '/question/type/webwork/classes/webwork_client.php');

/**
 * Importation en lot de problèmes WeBWorK depuis un dossier du renderer.
 *
 * Parcourt récursivement un dossier (bibliothèque libre ou banque privée),
 * et crée une question Moodle par fichier .pg trouvé, en réutilisant les
 * valeurs par défaut du formulaire de création. Les sous-dossiers
 * deviennent des sous-catégories de la banque de questions, reproduisant
 * l'arborescence d'origine.
 */
class bulk_importer {

    /** @var webwork_client */
    protected $client;

    /** @var \context */
    protected $context;

    /** @var array Journal des actions, pour le rapport final. */
    protected $log = ['created' => [], 'skipped' => [], 'failed' => [], 'categories' => []];

    /**
     * Profondeur maximale de descente dans les sous-dossiers. Garde-fou
     * contre une arborescence anormalement profonde (ou un lien symbolique
     * cyclique côté serveur, que la navigation Caddy suivrait sans le
     * signaler).
     */
    const MAX_DEPTH = 10;

    /** Nombre maximal de fichiers .pg traités en une seule importation. */
    const MAX_FILES = 2000;

    public function __construct(webwork_client $client, \context $context) {
        $this->client = $client;
        $this->context = $context;
    }

    /**
     * Lance l'importation.
     *
     * @param string $path Chemin du dossier de départ, relatif à la racine choisie.
     * @param string $root 'library' ou 'private'.
     * @param int $categoryid Catégorie de questions de destination.
     * @param array $defaults Valeurs par défaut à appliquer à chaque question créée.
     * @param bool $recursive Descendre dans les sous-dossiers ?
     * @param bool $createroot Créer une catégorie portant le nom du dossier importé ?
     * @return array Journal des actions (created/skipped/failed/categories).
     */
    public function import(string $path, string $root, int $categoryid,
            array $defaults, bool $recursive = true, bool $createroot = true): array {
        $this->log = ['created' => [], 'skipped' => [], 'failed' => [], 'categories' => []];

        // Le dossier importé devient lui-même une catégorie (ex. importer
        // "JonathanDesaulniers/Algebre" crée une catégorie "Algebre"), ce
        // qui évite de déverser les questions en vrac dans la catégorie
        // courante et rend l'importation reproductible sans doublons.
        $target = $categoryid;
        $basename = trim(basename(trim($path, '/')));
        if ($createroot && $basename !== '' && $basename !== '.') {
            $created = $this->ensure_category($basename, $categoryid);
            if ($created !== null) {
                $target = $created;
            }
        }

        $this->import_directory($path, $root, $target, $defaults, $recursive, 0);
        return $this->log;
    }

    /**
     * Traite un dossier : crée une question par .pg, et descend
     * récursivement dans les sous-dossiers (chacun devenant une
     * sous-catégorie).
     */
    protected function import_directory(string $path, string $root, int $categoryid,
            array $defaults, bool $recursive, int $depth): void {
        if ($depth > self::MAX_DEPTH) {
            $this->log['failed'][] = [
                'path' => $path,
                'reason' => get_string('importtoodeep', 'qtype_webwork', self::MAX_DEPTH),
            ];
            return;
        }
        if (count($this->log['created']) >= self::MAX_FILES) {
            return;
        }

        try {
            $items = $this->client->list_directory($path, $root);
        } catch (\Exception $e) {
            $this->log['failed'][] = ['path' => $path, 'reason' => $e->getMessage()];
            return;
        }

        $subdirs = [];
        foreach ($items as $item) {
            $name = (string) $item['name'];
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            if (!empty($item['is_dir'])) {
                $subdirs[] = $name;
                continue;
            }
            // Seuls les .pg nous intéressent (un dossier de problèmes
            // contient aussi souvent des images, des fichiers .txt, etc.).
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pg') {
                continue;
            }
            if (count($this->log['created']) >= self::MAX_FILES) {
                return;
            }
            $this->import_file(rtrim($path, '/') . '/' . $name, $root, $categoryid, $defaults);
        }

        if (!$recursive) {
            return;
        }
        foreach ($subdirs as $dirname) {
            $subcategoryid = $this->ensure_category($dirname, $categoryid);
            if ($subcategoryid === null) {
                continue;
            }
            $this->import_directory(
                rtrim($path, '/') . '/' . $dirname,
                $root,
                $subcategoryid,
                $defaults,
                true,
                $depth + 1
            );
        }
    }

    /**
     * Crée une question pour un fichier .pg donné, sauf si une question
     * pointant déjà vers ce même fichier existe DANS CETTE CATÉGORIE.
     *
     * La déduplication est volontairement limitée à la catégorie : le même
     * fichier .pg rangé dans deux dossiers différents donne bien deux
     * questions distinctes (dans deux catégories distinctes), ce qui
     * correspond à l'organisation voulue par l'enseignant.
     */
    protected function import_file(string $filepath, string $root, int $categoryid, array $defaults): void {
        global $DB, $USER;

        // Le chemin est stocké tel que le formulaire de question l'attend :
        // préfixé par la racine choisie (voir edit_webwork_form.php, qui
        // applique exactement le même préfixage côté navigateur).
        if ($root === 'private') {
            $prefix = get_config('qtype_webwork', 'privateroot');
            if ($prefix === false || $prefix === '') {
                $prefix = 'private';
            }
        } else {
            $prefix = get_config('qtype_webwork', 'libraryroot');
            if ($prefix === false || $prefix === '') {
                $prefix = 'Library';
            }
        }
        $prefix = trim((string) $prefix, '/');
        $sourcefilepath = ($prefix !== '' ? $prefix . '/' : '') . ltrim($filepath, '/');

        $name = pathinfo($filepath, PATHINFO_FILENAME);

        // Doublon dans cette catégorie ? (jointure sur les versions
        // courantes : la banque de questions de Moodle 4.x range les
        // questions via question_bank_entries -> question_versions.)
        $existing = $DB->record_exists_sql("
                SELECT 1
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {qtype_webwork_options} o ON o.questionid = q.id
                 WHERE qbe.questioncategoryid = :categoryid
                   AND o.sourcefilepath = :sourcefilepath",
            ['categoryid' => $categoryid, 'sourcefilepath' => $sourcefilepath]);
        if ($existing) {
            $this->log['skipped'][] = ['path' => $sourcefilepath, 'name' => $name];
            return;
        }

        try {
            $question = new \stdClass();
            $question->category = $categoryid;
            $question->qtype = 'webwork';
            $question->createdby = $USER->id;
            $question->modifiedby = $USER->id;
            $question->timecreated = time();
            $question->timemodified = time();
            $question->name = $name;
            $question->questiontext = '';
            $question->questiontextformat = FORMAT_HTML;
            $question->generalfeedback = '';
            $question->generalfeedbackformat = FORMAT_HTML;
            $question->defaultmark = 1;
            $question->penalty = 0;
            $question->length = 1;
            $question->stamp = make_unique_id_code();
            $question->idnumber = null;

            // Champs propres au type de question : valeurs par défaut du
            // formulaire, plus le chemin du fichier.
            foreach ($defaults as $field => $value) {
                $question->$field = $value;
            }
            $question->sourcefilepath = $sourcefilepath;

            $qtype = \question_bank::get_qtype('webwork');
            $qtype->save_question($question, clone $question);

            $this->log['created'][] = ['path' => $sourcefilepath, 'name' => $name];
        } catch (\Exception $e) {
            $this->log['failed'][] = ['path' => $sourcefilepath, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Retourne l'id d'une sous-catégorie portant ce nom sous le parent
     * donné, en la créant si nécessaire.
     */
    protected function ensure_category(string $name, int $parentid): ?int {
        global $DB;

        $parent = $DB->get_record('question_categories', ['id' => $parentid]);
        if (!$parent) {
            return null;
        }

        $existing = $DB->get_record('question_categories', [
            'name' => $name,
            'parent' => $parentid,
            'contextid' => $parent->contextid,
        ]);
        if ($existing) {
            return (int) $existing->id;
        }

        $category = new \stdClass();
        $category->name = $name;
        $category->info = get_string('importcategoryinfo', 'qtype_webwork');
        $category->infoformat = FORMAT_HTML;
        $category->contextid = $parent->contextid;
        $category->parent = $parentid;
        $category->sortorder = 999;
        $category->stamp = make_unique_id_code();
        $category->id = $DB->insert_record('question_categories', $category);

        $this->log['categories'][] = $name;
        return (int) $category->id;
    }
}
