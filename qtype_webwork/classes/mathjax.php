<?php
namespace qtype_webwork;

defined('MOODLE_INTERNAL') || die();

/**
 * Adresse et configuration de MathJax 4 pour Moodle 5.x.
 *
 * Sous Moodle 4.x, le filtre MathJax de Moodle et le plugin utilisaient
 * tous deux MathJax v2 et partageaient le même objet global. Depuis Moodle
 * 5.0, le filtre charge MathJax 4 (4.1.3 dans Moodle 5.3) et remplace
 * window.MathJax par sa propre configuration : un MathJax v2 chargé à côté
 * par le plugin serait écrasé. Deux versions de MathJax ne peuvent pas
 * cohabiter sur une même page -- elles se disputent le même nom global.
 *
 * Le plugin passe donc par le chargeur du filtre (filter_mathjaxloader/loader,
 * voir amd/src/question.js) : UNE seule instance de MathJax 4 par page,
 * partagée avec les formules de Moodle. Cette classe calcule ce qu'on lui
 * transmet.
 */
class mathjax {

    /** Adresse par défaut du filtre de Moodle 5.3, si l'administrateur ne l'a pas changée. */
    const DEFAULT_CDN_URL = 'https://cdn.jsdelivr.net/npm/mathjax@4.1.3/tex-mml-chtml.js';

    /**
     * Adresse du script MathJax 4, version de repli.
     *
     * - « local » (défaut) : la copie de MathJax 4 servie par le renderer
     *   (Dockerfile.intl, pg_files/mathjax-v4/), en sortie SVG : ses glyphes
     *   arrivent sous forme de scripts, sans police web. C'est la seule
     *   sortie qui fonctionne quand le renderer n'envoie pas d'en-tête CORS
     *   (le navigateur refuse alors les polices d'une autre origine). Quand
     *   CORS est présent, le module AMD prend chtml_url() à la place.
     * - « cdn » : l'adresse configurée dans le filtre MathJax de Moodle.
     */
    public static function url(string $serverurl): string {
        if (self::is_local()) {
            return rtrim($serverurl, '/') . '/pg_files/mathjax-v4/tex-mml-svg.js';
        }
        $url = get_config('filter_mathjaxloader', 'httpsurl');
        return !empty($url) ? $url : self::DEFAULT_CDN_URL;
    }

    /**
     * Adresse de la sortie CHTML de la copie locale (vide hors mode local).
     *
     * Rendu nettement plus propre que le SVG : vraies polices (woff2,
     * pg_files/mathjax-v4/fonts/), crénage et lissage du navigateur, traits
     * plus nets à la taille du texte -- le SVG donnait des expressions
     * pâles et un peu floues. Exige l'en-tête CORS sur les polices ; le
     * module AMD ne la retient que si la vérification CORS réussit
     * (probe_url(), même serveur, même en-tête).
     */
    public static function chtml_url(string $serverurl): string {
        return self::is_local() ? rtrim($serverurl, '/') . '/pg_files/mathjax-v4/tex-mml-chtml.js' : '';
    }

    /**
     * Configuration MathJax : celle du filtre de Moodle (pour ne rien
     * changer au rendu des formules de Moodle sur la même page), complétée
     * de ce dont les problèmes WeBWorK ont besoin.
     */
    public static function config(string $serverurl): array {
        $config = [];
        $filterconfig = (string) get_config('filter_mathjaxloader', 'mathjaxconfig');
        if (trim($filterconfig) !== '') {
            $wwwroot = (new \moodle_url('/'))->out(true);
            $decoded = json_decode(str_replace('{wwwroot}', $wwwroot, $filterconfig), true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        // Délimiteurs produits par PG (et par response_parser pour les
        // bulles de prévisualisation). C'est le défaut de MathJax 4 ; on ne
        // le pose que si l'administrateur n'a rien configuré, pour ne pas
        // retirer ses propres délimiteurs.
        if (!isset($config['tex']['inlineMath'])) {
            $config['tex']['inlineMath'] = [['\\(', '\\)']];
        }
        // Entrée AsciiMath, comme le « AM » de TeX-MML-AM_CHTML chargé par le
        // plugin 4.x : l'aperçu de l'éditeur MathView (mode PGML, celui de
        // PG) écrit l'expression entre accents graves (`x^2`), en AsciiMath.
        // Les composants tex-mml-* de MathJax 4 n'incluent pas cette entrée :
        // sans elle, l'aperçu sous les boutons de MathView restait vide.
        $load = $config['loader']['load'] ?? [];
        if (!in_array('input/asciimath', $load, true)) {
            $load[] = 'input/asciimath';
            $config['loader']['load'] = $load;
        }
        if (!isset($config['asciimath']['delimiters'])) {
            $config['asciimath']['delimiters'] = [['`', '`']];
        }
        // Zoom au double-clic, comme sous MathJax v2 avec le plugin 4.x
        // (menuSettings: {zoom: 'Double-Click'}). MathJax 4 exige en plus,
        // par défaut, la touche Alt pendant le double-clic (alt: true) :
        // sans rien d'autre, le double-clic seul ne zoomait plus. Aucune
        // touche requise, comme sous MathJax v2 -- sauf réglage contraire
        // de l'administrateur dans le filtre MathJax.
        $zoomsettings = ['zoom' => 'DoubleClick', 'alt' => false, 'ctrl' => false, 'cmd' => false, 'shift' => false];
        foreach ($zoomsettings as $name => $value) {
            if (!isset($config['options']['menuOptions']['settings'][$name])) {
                $config['options']['menuOptions']['settings'][$name] = $value;
            }
        }
        // Les polices de MathJax 4 sont un paquet séparé, cherché par défaut
        // sur cdn.jsdelivr.net : en mode local, on pointe vers la copie
        // installée à côté de MathJax sur le renderer.
        if (self::is_local()) {
            $config['loader']['paths']['fonts'] = rtrim($serverurl, '/') . '/pg_files/mathjax-v4/fonts';
        }
        return $config;
    }

    /**
     * La même configuration, sans l'enrichissement sémantique de MathJax.
     *
     * La synthèse vocale de MathJax 4 (speech-rule-engine) charge ses
     * dictionnaires (sre/mathmaps/*.json) par fetch() : d'une origine à
     * l'autre, le navigateur exige un en-tête CORS. Si le renderer n'en
     * envoie pas, la requête échoue et MathJax ne rend plus AUCUNE formule
     * (vérifié avec MathJax 4.1.3 : typesetPromise ne se résout jamais).
     * Désactiver l'enrichissement évite ce chargement ; le MathML
     * d'assistance, activé à la place, reste lu par les lecteurs d'écran.
     *
     * Le renderer amont envoie « Access-Control-Allow-Origin: * » par défaut
     * (CORS_ORIGIN de render_app.conf) : ce repli ne sert que si un
     * administrateur l'a restreint. Sur les pages de Moodle, le module AMD
     * le vérifie lui-même avant de charger MathJax (voir probe_url()).
     */
    public static function without_enrichment(array $config): array {
        if (!isset($config['options']['menuOptions']['settings']['enrich'])) {
            $config['options']['menuOptions']['settings']['enrich'] = false;
        }
        if (!isset($config['options']['menuOptions']['settings']['assistiveMml'])) {
            $config['options']['menuOptions']['settings']['assistiveMml'] = true;
        }
        return $config;
    }

    /**
     * Fichier du renderer que le module AMD demande (requête CORS) pour
     * savoir si la synthèse vocale de MathJax pourra charger ses
     * dictionnaires -- voir without_enrichment(). Vide hors mode local :
     * le CDN envoie toujours les en-têtes CORS.
     */
    public static function probe_url(string $serverurl): string {
        return self::is_local() ? rtrim($serverurl, '/') . '/pg_files/mathjax-v4/sre/mathmaps/base.json' : '';
    }

    /**
     * Paramètres attendus par filter_mathjaxloader/loader::configure().
     */
    public static function loader_params(string $serverurl): array {
        return [
            'mathjaxurl' => self::url($serverurl),
            'mathjaxconfig' => json_encode(self::config($serverurl)),
            'lang' => self::language(),
            // Lus par amd/src/question.js, ignorés par le chargeur du filtre.
            'probeurl' => self::probe_url($serverurl),
            'chtmlurl' => self::chtml_url($serverurl),
        ];
    }

    /**
     * Langue de l'interface de MathJax (menu contextuel) : « fr_ca » → « fr ».
     */
    public static function language(): string {
        return explode('_', current_language())[0];
    }

    public static function is_local(): bool {
        return (get_config('qtype_webwork', 'mathjaxsource') ?: 'local') === 'local';
    }
}
