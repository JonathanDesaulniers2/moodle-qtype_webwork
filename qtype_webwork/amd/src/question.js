// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Mise en route côté navigateur d'une question WeBWorK (Moodle 5.x).
 *
 * Sous Moodle 4.x, tout ceci était émis en scripts en ligne par
 * renderer.php. Moodle 5 change deux choses qui obligent à passer par un
 * module AMD, chargé après ceux de Moodle :
 *
 * - Bootstrap 5. Le renderer fournit sa propre copie (bootstrap.bundle) ;
 *   sous Moodle 4.x (Bootstrap 4, attributs data-toggle) elle ne gênait
 *   rien. Sous Moodle 5, deux copies de Bootstrap 5 traiteraient chacune
 *   les clics sur les data-bs-toggle de Moodle : menus et panneaux
 *   s'ouvriraient puis se refermeraient aussitôt. On ne charge donc plus
 *   la copie du renderer et on prête celle de Moodle aux scripts de
 *   WeBWorK (window.bootstrap) -- d'où le chargement différé de ces
 *   scripts : le Bootstrap de Moodle n'existe qu'en module AMD.
 * - MathJax 4. Voir classes/mathjax.php : une seule instance par page,
 *   obtenue par le chargeur du filtre MathJax de Moodle.
 *
 * Les scripts de PG s'initialisent tous immédiatement sur le contenu déjà
 * présent dans la page (fonctions auto-exécutées), ce qui permet de les
 * charger après coup. Seule exception connue : ww_applet_support.js
 * (applets), qui attend DOMContentLoaded.
 *
 * @module     qtype_webwork/question
 * @copyright  2026 Jonathan Desaulniers
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as BootstrapModule from 'bootstrap';
import * as MathJaxLoader from 'filter_mathjaxloader/loader';

/** Attribut posé par response_parser à la place de data-bs-toggle="popover". */
const TOGGLE_ATTRIBUTE = 'data-qtype-webwork-toggle';

const PROBLEM_SELECTOR = '.webwork-problem';

/** @type {Promise|null} MathJax prêt (ou null s'il n'a pas pu être chargé). */
let mathJaxReady = null;

/** @type {Map<string, Promise>} scripts du renderer déjà demandés, par adresse. */
const scripts = new Map();

/** File d'attente : une question à la fois, dans l'ordre de la page. */
let queue = Promise.resolve();

/**
 * Le Bootstrap de Moodle, quelle que soit la forme sous laquelle le
 * chargeur AMD nous le remet.
 *
 * @returns {Object}
 */
const getBootstrap = () => (BootstrapModule.Popover ? BootstrapModule : BootstrapModule.default);

/**
 * Vérifie que le navigateur peut lire un fichier du renderer d'une origine
 * à l'autre (en-tête CORS). Sans cela, la synthèse vocale de MathJax 4 ne
 * peut pas charger ses dictionnaires, et cet échec empêche le rendu de
 * toutes les formules : voir mathjax::without_enrichment() côté PHP.
 *
 * @param {string} url fichier à demander, ou chaîne vide (rien à vérifier)
 * @returns {Promise<boolean>}
 */
const corsAllowed = (url) => {
    if (!url) {
        return Promise.resolve(true);
    }
    return fetch(url, {method: 'HEAD', mode: 'cors', cache: 'no-store'})
        .then((response) => response.ok)
        .catch(() => false);
};

/**
 * Configuration MathJax sans enrichissement sémantique (donc sans synthèse
 * vocale), avec le MathML d'assistance pour les lecteurs d'écran. Même
 * règle que mathjax::without_enrichment() côté PHP.
 *
 * @param {string} json configuration MathJax (JSON)
 * @returns {string}
 */
const withoutEnrichment = (json) => {
    const config = JSON.parse(json || '{}');
    config.options = config.options || {};
    config.options.menuOptions = config.options.menuOptions || {};
    const settings = config.options.menuOptions.settings = config.options.menuOptions.settings || {};
    if (settings.enrich === undefined) {
        settings.enrich = false;
    }
    if (settings.assistiveMml === undefined) {
        settings.assistiveMml = true;
    }
    return JSON.stringify(config);
};

/**
 * Charge MathJax 4, une seule fois par page.
 *
 * Si une instance complète existe déjà (chargée par le filtre de Moodle,
 * ou par un autre plugin), on la réutilise. Sinon on configure puis on
 * lance le chargeur du filtre dans la foulée : une fois le chargement
 * lancé, un appel ultérieur de configure() par le filtre lui-même
 * n'écrase plus window.MathJax. Si le filtre a lancé le sien pendant la
 * vérification CORS, c'est le sien qui sert : une seule instance quand
 * même.
 *
 * @param {Object} params {mathjaxurl, mathjaxconfig, lang, probeurl}, voir classes/mathjax.php
 * @returns {Promise}
 */
const getMathJax = (params) => {
    if (mathJaxReady) {
        return mathJaxReady;
    }
    let loading;
    if (window.MathJax && window.MathJax.startup && window.MathJax.typesetPromise) {
        loading = Promise.resolve();
    } else {
        loading = corsAllowed(params.probeurl).then((allowed) => {
            if (window.MathJax && window.MathJax.startup && window.MathJax.typesetPromise) {
                return null;
            }
            MathJaxLoader.configure({
                mathjaxurl: params.mathjaxurl,
                mathjaxconfig: allowed ? params.mathjaxconfig : withoutEnrichment(params.mathjaxconfig),
                lang: params.lang,
            });
            return MathJaxLoader.loadMathJax();
        });
    }
    mathJaxReady = loading
        .then(() => {
            const mj = window.MathJax;
            // Une adresse de filtre pointant encore vers MathJax 2 n'offre
            // pas cette API : on renonce plutôt que de planter plus loin.
            if (!mj || !mj.startup || !mj.startup.promise) {
                window.console.error('qtype_webwork : MathJax 3 ou plus est requis sous Moodle 5.');
                return null;
            }
            // MathJax 4 ne définit typesetPromise() qu'à la fin de son
            // démarrage, pas au chargement de son script.
            return mj.startup.promise.then(() => (window.MathJax.typesetPromise ? window.MathJax : null));
        })
        .catch((e) => {
            window.console.error('qtype_webwork : MathJax n\'a pas pu être chargé.', e);
            return null;
        });
    return mathJaxReady;
};

/**
 * Rend les formules contenues dans ces éléments.
 *
 * @param {Element[]} nodes
 * @returns {Promise}
 */
const typeset = (nodes) => {
    if (!mathJaxReady) {
        return Promise.resolve();
    }
    return mathJaxReady.then((mj) => {
        if (!mj || !nodes.length) {
            return null;
        }
        // Enchaînement recommandé par MathJax : chaque passe attend la
        // précédente.
        mj.startup.promise = mj.startup.promise
            .then(() => mj.typesetPromise(nodes))
            .catch((e) => window.console.error(e));
        return mj.startup.promise;
    });
};

/** Marque (data-qtype-webwork-script) des scripts du renderer insérés par loadScripts(). */
const SCRIPT_MARKER = 'qtypeWebworkScript';

/** @type {boolean} define.amd déjà remplacé par l'accesseur de guardAmd(). */
let amdGuarded = false;

/**
 * Cache define.amd aux seuls scripts du renderer.
 *
 * Les scripts du renderer au format UMD (jQuery UI notamment) se déclarent
 * auprès du chargeur AMD dès qu'ils voient define.amd, au lieu de
 * s'installer sur window.jQuery. RequireJS refuse ces define() anonymes
 * (« Mismatched anonymous define() module ») et jQuery UI n'est jamais
 * installé. Sous Moodle 4.x, ces scripts s'exécutaient avant RequireJS et
 * le problème ne se posait pas.
 *
 * Masquer define.amd le temps du chargement ne convient pas : les
 * bibliothèques UMD de Moodle chargées pendant ce temps (Mustache pour
 * core/templates, par exemple) ne s'enregistraient plus, et des modules de
 * Moodle restaient indéfinis. define.amd devient donc un accesseur qui ne
 * répond undefined qu'au script en cours d'exécution s'il porte notre
 * marque (document.currentScript) : les tests UMD se font au premier
 * niveau du script, donc pendant son exécution.
 */
const guardAmd = () => {
    const define = window.define;
    if (amdGuarded || !define || !Object.prototype.hasOwnProperty.call(define, 'amd')) {
        return;
    }
    const descriptor = Object.getOwnPropertyDescriptor(define, 'amd');
    if (!descriptor.configurable) {
        return;
    }
    amdGuarded = true;
    let amd = define.amd;
    Object.defineProperty(define, 'amd', {
        configurable: true,
        enumerable: descriptor.enumerable,
        get: () => {
            const current = document.currentScript;
            return current && current.dataset[SCRIPT_MARKER] ? undefined : amd;
        },
        set: (value) => {
            amd = value;
        },
    });
};

/**
 * Charge les scripts du renderer dans l'ordre donné (dépendances déjà
 * triées par response_parser::sort_js_by_priority()). Les scripts insérés
 * avec async = false s'exécutent dans leur ordre d'insertion.
 *
 * @param {string[]} urls
 * @returns {Promise}
 */
const loadScripts = (urls) => {
    guardAmd();
    return Promise.all(urls.map((url) => {
        if (!scripts.has(url)) {
            scripts.set(url, new Promise((resolve) => {
                const script = document.createElement('script');
                script.src = url;
                script.async = false;
                script.dataset[SCRIPT_MARKER] = '1';
                script.onload = resolve;
                script.onerror = () => {
                    window.console.error('qtype_webwork : échec du chargement de ' + url);
                    resolve();
                };
                document.body.appendChild(script);
            }));
        }
        return scripts.get(url);
    }));
};

/**
 * Câble les bulles de rétroaction de WeBWorK (icônes vert/rouge,
 * prévisualisation de la réponse). Même logique que sous Moodle 4.5, où
 * elle a été mise au point ; voir l'historique de renderer.php pour le
 * détail de chaque contournement.
 */
const wirePopovers = () => {
    const Bootstrap = getBootstrap();
    document.querySelectorAll(PROBLEM_SELECTOR + ' [' + TOGGLE_ATTRIBUTE + '="popover"]').forEach((original) => {
        if (original.dataset.qtypeWebworkWired) {
            return;
        }
        // Repartir d'un élément vierge : feedback.js (WeBWorK) pose sa
        // propre gestion de la bulle sur ce même élément, qui entrait en
        // conflit avec la nôtre (réouverture immédiate avec du contenu non
        // rendu). cloneNode() copie les data-bs-* mais aucun écouteur.
        const el = original.cloneNode(true);
        el.dataset.qtypeWebworkWired = '1';
        original.parentNode.replaceChild(el, original);

        const existing = Bootstrap.Popover.getInstance(el);
        if (existing) {
            existing.dispose();
        }
        // sanitize: false -- l'assainisseur de Bootstrap retire les
        // <script> du contenu, ce qui effaçait les formules. Le contenu
        // vient de notre propre renderer, pas d'une saisie d'utilisateur.
        const instance = new Bootstrap.Popover(el, {sanitize: false});

        el.addEventListener('shown.bs.popover', () => {
            const tipid = el.getAttribute('aria-describedby');
            const tip = tipid ? document.getElementById(tipid) : null;
            if (!tip) {
                return;
            }
            // Un <script> inséré par innerHTML ne s'exécute jamais : on
            // recrée ceux de la bulle (prévisualisation GraphTool, etc.).
            tip.querySelectorAll('script').forEach((oldscript) => {
                const newscript = document.createElement('script');
                if (oldscript.src) {
                    newscript.src = oldscript.src;
                } else {
                    newscript.textContent = oldscript.textContent;
                }
                oldscript.parentNode.replaceChild(newscript, oldscript);
            });
            // Bouton « X » de WeBWorK, cloné pour le libérer des écouteurs
            // de feedback.js avant d'y brancher notre instance.
            const closebtn = tip.querySelector('[data-dismiss="popover"], [data-bs-dismiss="popover"], .close, .btn-close');
            if (closebtn && !closebtn.dataset.webworkCloseWired) {
                const freshbtn = closebtn.cloneNode(true);
                freshbtn.dataset.webworkCloseWired = '1';
                closebtn.parentNode.replaceChild(freshbtn, closebtn);
                freshbtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    instance.hide();
                    // Le focus rendu à l'icône réactiverait l'affichage
                    // au focus de feedback.js : on le retire.
                    if (document.activeElement && document.activeElement.blur) {
                        document.activeElement.blur();
                    }
                    el.blur();
                    setTimeout(() => el.blur(), 300);
                });
            }
            typeset([tip]);
        });
    });
};

/**
 * Grise et bloque l'éditeur MathQuill des champs désactivés (question
 * terminée). MathQuill affiche son propre éditeur (span.mq-edit), créé
 * par mqeditor.js, indépendant du champ désactivé qu'il recouvre.
 */
const lockMathQuill = () => {
    document.querySelectorAll(PROBLEM_SELECTOR + ' input[disabled], ' + PROBLEM_SELECTOR + ' textarea[disabled]')
        .forEach((input) => {
            const span = document.getElementById('mq-answer-' + input.id);
            if (!span || span.classList.contains('qtype-webwork-disabled')) {
                return;
            }
            span.classList.add('qtype-webwork-disabled');
            span.setAttribute('tabindex', '-1');
            span.querySelectorAll('textarea, input').forEach((field) => {
                field.setAttribute('disabled', 'disabled');
                field.setAttribute('tabindex', '-1');
            });
        });
};

/**
 * Rend les formules des problèmes, puis vérifie une fois plus tard qu'il
 * ne reste pas de délimiteur brut (contenu arrivé après coup).
 *
 * @returns {Promise}
 */
const typesetProblems = () => {
    const problems = [...document.querySelectorAll(PROBLEM_SELECTOR)];
    return typeset(problems).then(() => {
        setTimeout(() => {
            const leftover = problems.filter((el) => el.textContent.includes('\\('));
            if (leftover.length) {
                typeset(leftover);
            }
        }, 1200);
    });
};

/**
 * Point d'entrée, appelé une fois par question WeBWorK de la page.
 *
 * @param {Object} params
 * @param {string[]} params.scripts scripts du renderer propres à cette question (adresses absolues)
 * @param {Object} params.mathjax paramètres du chargeur MathJax (classes/mathjax.php)
 */
export const init = (params) => {
    if (!window.bootstrap) {
        window.bootstrap = getBootstrap();
    }
    const ready = getMathJax(params.mathjax);
    queue = queue
        // Les scripts de WeBWorK testent MathJax.startup dès leur
        // exécution (correctifs patch_mathview.pl / patch_graphtool.pl) :
        // MathJax doit être en place avant eux.
        .then(() => ready)
        .then(() => loadScripts(params.scripts || []))
        .then(() => {
            wirePopovers();
            // mqeditor.js crée ses éditeurs un peu après son exécution.
            lockMathQuill();
            setTimeout(lockMathQuill, 500);
            setTimeout(lockMathQuill, 2000);
            return typesetProblems();
        })
        .catch((e) => window.console.error(e));
};
