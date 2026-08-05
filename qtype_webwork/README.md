# qtype_webwork — squelette de plugin Moodle (ciblant Moodle 4.5)

Intègre le **WeBWorK Standalone Renderer** comme type de question dans la banque
de questions de Moodle (`question/type/webwork`).

> **Note de version** : ce plugin cible désormais Moodle 4.5 (build
> `2024100700`), suite à des difficultés d'installation de Moodle 5.2 côté
> développement. Le code n'utilise aucune API propre à 5.2 — la bascule vers
> 5.x ultérieurement ne devrait nécessiter que la mise à jour de
> `$plugin->requires`/`$plugin->supported` dans `version.php` et une
> vérification rapide des notes de version Moodle entre les deux versions
> (voir https://moodledev.io/general/releases/5.2 le moment venu).

## Installation

1. Copier ce dossier dans `moodle/question/type/webwork`.
2. Se rendre sur `Administration du site > Notifications` pour déclencher
   l'installation (création de la table `qtype_webwork_options`).
3. Créer une question de type « WeBWorK problem » dans la banque de questions
   d'un cours : renseigner l'URL du serveur renderer, le chemin du fichier
   `.pg`, et éventuellement le secret partagé si votre renderer signe/valide
   des JWT.
4. Ajouter la question à un test (quiz) comme n'importe quelle autre question.

## Prérequis côté serveur WeBWorK Standalone Renderer

- Le renderer doit être accessible en HTTP(S) depuis le serveur Moodle
  (attention aux règles de pare-feu si Moodle et le renderer sont sur des
  réseaux différents).
- CORS n'est pas un problème puisque les appels à `/render-api` se font
  **côté serveur** (PHP), pas depuis le navigateur de l'étudiant — seuls les
  assets statiques (CSS/JS/MathJax) sont chargés directement par le
  navigateur depuis l'URL du renderer.
- Voir https://github.com/openwebwork/renderer pour l'installation du
  renderer lui-même (Docker recommandé).

## Points à vérifier / compléter avant mise en production

Ce squelette pose l'architecture centrale mais nécessite encore, en particulier :

1. ~~Schéma JSON exact de votre version du renderer~~ **VÉRIFIÉ le 21/07/2026**
   sur une instance réelle. Résumé (voir `question.php::parse_render_result()`) :
   - `renderedHTML` est un **document HTML complet**, pas un fragment : on en
     extrait le contenu de `<form id="problemMainForm">...</form>`.
   - `flags.ANSWER_ENTRY_ORDER` donne la liste ordonnée et autoritaire des
     champs notés (`AnSwEr0001`, `AnSwEr0002`, ...).
   - `flags.KEPT_EXTRA_ANSWERS` liste des champs cachés compagnons
     (ex. `MaThQuIlL_AnSwEr0001` pour l'éditeur MathQuill) qui doivent
     impérativement transiter d'une requête à l'autre.
   - Il y a aussi un champ caché `sessionJWT` (hors de `#problem_body`, mais
     dans le `<form>`) à transmettre systématiquement.
   - `resources.assets` est **une seule liste mélangeant CSS et JS** (pas deux
     clés séparées) — on distingue par l'extension du fichier.
   - `problem_result.score` est bien un flottant `0..1` (score global déjà
     calculé/pondéré par le grader WeBWorK, ex. `"type": "avg_problem_grader"`
     avec un score de `0.5` pour une réponse correcte sur deux) — inutile de
     recalculer soi-même à partir du détail par champ dans `answers`.
   - `answers.<champ>.score` / `.correct_ans` donnent un détail par champ,
     utile pour du feedback spécifique (non exploité dans ce squelette).

   **Reste à vérifier** : le comportement quand plusieurs tentatives sont
   autorisées (impact de `sessionJWT`/`previous_*` sur le nombre d'essais
   restants), et la structure JSON en cas d'erreur PG (syntaxe invalide dans
   le `.pg`, chemin introuvable, etc.) pour un traitement d'erreur robuste.
2. ~~Authentification / JWT~~ **RÉSOLU (décision d'architecture) le 21/07/2026.**
   Le `secret_key` de `render_app.conf` sert au renderer à **chiffrer ses
   propres jetons** (`problemJWT`/`sessionJWT`/`answerJWT`, format JWE
   `PBES2-HS512+A256KW` / `A256GCM`) pendant qu'ils transitent par le
   navigateur de l'étudiant — pas à authentifier l'appel à `/render-api`
   lui-même, qui accepte des paramètres en clair. Comme Moodle relaie ces
   jetons de façon **opaque** (il ne les déchiffre jamais, voir
   `question.php`/`renderer.php`), le plugin n'a jamais besoin de connaître
   ce secret.

   **Décision retenue :** restreindre l'accès au serveur renderer au niveau
   réseau (pare-feu / VPN), puisqu'il s'agit d'un appel serveur-à-serveur.
   Concrètement :
   - N'exposer le port du renderer (3000 par défaut) que sur une interface
     réseau interne/privée, jamais sur l'Internet public.
   - Configurer le pare-feu du serveur renderer pour n'accepter les connexions
     entrantes sur ce port que depuis l'adresse IP (ou le sous-réseau) du
     serveur Moodle — ex. avec `ufw` :
     ```
     sudo ufw allow from <IP_DU_SERVEUR_MOODLE> to any port 3000 proto tcp
     sudo ufw deny 3000/tcp
     ```
   - Si Moodle et le renderer sont sur des réseaux différents, envisager un
     VPN site-à-site ou un tunnel SSH dédié plutôt que d'exposer le renderer
     directement.
   - Le champ « secret partagé » du formulaire de question est conservé mais
     **non exploité actuellement** ; il pourra servir plus tard si vous
     décidez d'utiliser le mécanisme `JWTanswerURL` (notation par callback
     asynchrone) plutôt que l'appel direct à `/render-api` utilisé ici.
3. **Assets JS/CSS dynamiques** (MathQuill, boutons "matrix input", etc.) :
   le chargement actuel via `$this->page->requires->js()/css()` fonctionne
   pour des URLs simples, mais certains problèmes WeBWorK injectent du JS
   inline dans le fragment HTML — il faudra un traitement spécifique (CSP,
   `requires->js_amd_inline`, etc.).
4. ~~Notation "interactive" vs "notation différée"~~ **RÉSOLU le 21/07/2026.**
   Un champ « Mode de notation » dans le formulaire d'édition de la question
   (`edit_webwork_form.php`) permet de choisir, **question par question**,
   entre :
   - **Différée** : une seule correction, à la fin de la tentative de test
     (comportement Moodle habituel).
   - **Interactive** : bouton « Vérifier » avec essais multiples, en
     ignorant le réglage global « Comportement des questions » du test.

   Techniquement, ceci fonctionne parce que `question_definition` expose un
   point d'extension `make_behaviour($qa, $preferredbehaviour)` que
   `question.php` redéfinit pour forcer le comportement choisi, quel que
   soit le réglage du test. Le champ standard `penalty` (pénalité par essai
   incorrect) est également exposé dans le formulaire, masqué en mode
   différé.

   **Point de vigilance non résolu, à votre charge :** si le fichier `.pg`
   réduit déjà lui-même le crédit selon le nombre de tentatives (ce que font
   certains problèmes WeBWorK via leurs macros internes), une pénalité
   Moodle non nulle en mode interactif peut pénaliser l'étudiant deux fois.
   Recommandation : mettre `penalty` à 0 en mode interactif si vous
   utilisez des problèmes qui gèrent déjà cette dégradation eux-mêmes.
5. ~~Champs cachés / anti-CSRF générés par PG~~ **RÉSOLU et VALIDÉ le 21/07/2026**
   sur deux échanges JSON réels (un rendu vierge, puis une soumission avec
   une réponse correcte et une incorrecte) :
   - Les champs à faire transiter (`AnSwEr*`, `MaThQuIlL_AnSwEr*`,
     `sessionJWT`, `previous_AnSwEr*`) sont capturés automatiquement par le
     parsing DOM du fragment `<form>` (voir point 1) — aucun ajustement
     nécessaire.
   - **Amélioration ajoutée** : le rendu *corrigé* que renvoie
     `check_answers()` contient un balisage bien plus riche que le gabarit
     vierge (classes CSS `correct`/`incorrect`, bulles de rétroaction
     Bootstrap avec la bonne réponse, résumé "X of the answers is NOT
     correct"). `question.php::get_graded_render_data()` rappelle
     `check_answers()` avec la dernière réponse soumise pour récupérer ce
     HTML enrichi, et `renderer.php` l'utilise pour l'affichage dès qu'une
     réponse évaluable existe et que le mode d'affichage autorise la
     rétroaction (`$options->feedback`).
   - Ceci ne fait *pas* progresser le compteur de tentatives WeBWorK : le
     renderer est **stateless** (tout l'état de la tentative est encapsulé
     dans les champs `sessionJWT`/`previous_*` déjà présents dans la
     réponse), donc rappeler `check_answers()` avec exactement les mêmes
     valeurs est idempotent — sûr à faire à chaque affichage, y compris en
     relecture (review) d'une tentative terminée.
   - Les résultats sont mis en cache (`cache::make('qtype_webwork',
     'renders')`, clé incluant un hachage de la réponse) pour éviter des
     appels HTTP redondants au renderer à chaque page vue.
6. **Tests automatisés** (PHPUnit `question/type/webwork/tests/`) à écrire
   avant tout déploiement en production.

## Tester sans PHPUnit (option légère, sans Moodle)

Si PHPUnit pose problème (ex. chemins avec espace sous Windows), la partie la
plus délicate du plugin — le parsing du JSON du renderer — a été extraite
dans une classe **sans aucune dépendance à Moodle**
(`classes/response_parser.php`), testable avec un simple interpréteur PHP :

```
php tests/standalone/run_tests.php
```

Ce script utilise deux fixtures JSON réelles (`tests/fixtures/graded_response_1.json`
et `graded_response_2.json`, basées sur de vrais échanges avec le renderer,
JWT raccourcis) et vérifie :
- l'extraction correcte des noms de champs notés et de tous les champs à
  faire transiter (y compris les champs compagnons MathQuill et `sessionJWT`),
- le décompte des ressources CSS/JS,
- l'extraction et le bornage (0 à 1) du score,
- quelques cas limites (JSON vide, score hors bornes).

**Limite importante** : ce script ne couvre que la logique pure (parsing
JSON/HTML). Il ne teste PAS `questiontype.php` (sauvegarde en base),
`make_behaviour()`, ni l'intégration avec le moteur de question Moodle —
cela nécessite une vraie suite PHPUnit avec Moodle démarré, qu'on pourra
ajouter plus tard si l'installation PHPUnit se débloque (voir section
précédente).

## Activer PHPUnit sur votre instance Moodle 4.5 (préparation du point 5)

Si PHPUnit n'est pas encore configuré sur votre instance :

1. Installer les dépendances de développement via Composer, à la racine du
   code Moodle :
   ```
   composer install --dev
   ```
   (nécessite le fichier `composer.json` déjà présent à la racine de Moodle ;
   s'il manque des paquets, `composer require --dev phpunit/phpunit` selon la
   version attendue par Moodle 4.5).
2. Ajouter dans `config.php` :
   ```php
   $CFG->phpunit_prefix = 'phpu_';
   $CFG->phpunit_dataroot = '/chemin/vers/moodledata_phpunit';
   ```
   (`phpunit_dataroot` doit être un dossier séparé de votre `dataroot` de
   production, créé au préalable et accessible en écriture par le serveur web
   / l'utilisateur CLI).
3. Créer la base de données de test (même moteur que la prod, nom différent),
   puis l'ajouter à `config.php` si nécessaire (`$CFG->phpunit_...` ou via un
   second bloc `$CFG->dboptions` selon votre configuration).
4. Initialiser l'environnement de test :
   ```
   php admin/tool/phpunit/cli/init.php
   ```
5. Vérifier que tout fonctionne avant d'ajouter nos propres tests :
   ```
   vendor/bin/phpunit --filter core_component_testcase
   ```
6. Nos futurs tests iront dans `question/type/webwork/tests/` et se lancent
   avec :
   ```
   vendor/bin/phpunit --testsuite qtype_webwork_testsuite
   ```
   (le fichier `tests/` et la déclaration de la testsuite restent à créer —
   ce sera l'objet du point 5).

## Architecture en bref


```
Moodle (question engine)                WeBWorK Standalone Renderer
------------------------                ---------------------------
edit_webwork_form.php   -- config -->    (rien, juste stocké en DB Moodle)
question.php::get_render_data() -- POST /render-api (_format=json) -->
                          <-- HTML fragment + noms de champs + CSS/JS --
renderer.php             -- injecte le HTML dans la page de tentative
                             (avec les valeurs déjà saisies si besoin)
question.php::grade_response()  -- POST /render-api avec les réponses -->
                          <-- score + détails de correction --
                          -- converti en (fraction, question_state) Moodle
```
