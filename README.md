# Plugins Moodle — WeBWorK

Trois plugins à installer ensemble :

- **`qtype_webwork`** — le type de question lui-même (formulaire, rendu, navigateur de
  bibliothèque, fenêtre d'édition intégrée, importation en lot).
- **`qbehaviour_webwork`** — le comportement de notation associé (modes différé et interactif).
- **`qbank_webworkimport`** — ajoute l'onglet « Importer WeBWorK » à la banque de questions.
  Moodle 4.x réserve l'extension de la banque de questions aux plugins de type `qbank` : ce petit
  plugin compagnon n'est qu'un point d'entrée officiel vers l'importation fournie par
  `qtype_webwork`. Sans lui, l'importation reste accessible par URL directe.

**Compatibilité** : Moodle 4.5 (testé sur 4.5.1+). Une version pour Moodle 5.3 est prévue.

Les trois dépendent d'un [renderer WeBWorK](https://github.com/JonathanDesaulniers/webwork-renderer)
accessible sur le réseau — installez-le d'abord. Des images Docker pré-construites sont
disponibles (variantes française et internationale), ou vous pouvez la construire vous-même.

## 1. Installer les plugins

⚠️ **L'ordre compte** : `qbank_webworkimport` dépend de `qtype_webwork` et refusera de s'installer
avant lui.

1. *Administration du site → Plugins → Installer des plugins*
2. Téléchargez les trois `.zip` depuis la
   [dernière release](https://github.com/JonathanDesaulniers/moodle-qtype_webwork/releases),
   ou compressez vous-même chaque dossier de ce dépôt
3. Téléversez **`qtype_webwork.zip` en premier**, confirmez la mise à jour de la base de données
4. Puis `qbehaviour_webwork.zip`, puis `qbank_webworkimport.zip`
5. *Administration du site → Développement → Purger tous les caches*

*(Installation manuelle : les dossiers vont respectivement dans `question/type/webwork/`,
`question/behaviour/webwork/` et `question/bank/webworkimport/` — noter que le préfixe du type de
plugin ne fait pas partie du nom du dossier.)*

## 2. Caddy — HTTPS et navigation de fichiers

Le plugin communique avec le renderer via [Caddy](https://caddyserver.com/), qui sert de proxy
HTTPS et expose deux routes de navigation en lecture seule (bibliothèque libre + banque locale).

**Caddyfile :**
```
:443 {
    tls /etc/caddy/webwork.crt /etc/caddy/webwork.key

    handle_path /library-browse/* {
        root * /srv/library
        file_server browse
    }
    handle_path /private-browse/* {
        root * /srv/private
        file_server browse
    }

    reverse_proxy host.docker.internal:3000
}
```

**Protection optionnelle par secret partagé** (recommandée), à ajouter dans chacun des deux
blocs `handle_path` :
```
@missingsecret not header X-WebWork-Secret "VOTRE_SECRET_ICI"
respond @missingsecret 403
```

**Lancer Caddy :**
```bash
sudo docker run -d --name webwork-caddy --restart unless-stopped \
  --add-host=host.docker.internal:host-gateway -p 3443:443 \
  -v $(pwd)/Caddyfile:/etc/caddy/Caddyfile \
  -v $(pwd)/webwork-open-problem-library/OpenProblemLibrary:/srv/library:ro \
  -v $(pwd)/private:/srv/private:ro \
  -v caddy_data:/data -v caddy_config:/config \
  caddy:2
```

Remplacez les chemins des volumes `-v` par les vôtres — ils doivent correspondre exactement aux
dossiers montés dans le conteneur du renderer (voir [dépôt du renderer](https://github.com/JonathanDesaulniers/webwork-renderer)).

## 3. Configuration du plugin (réglages admin)

*Administration du site → Plugins → Types de question → WeBWorK* :

| Réglage | Valeur / exemple |
|---|---|
| URL du serveur | `https://IP_OU_DOMAINE_CADDY:3443` |
| Certificat auto-signé | Activé (si vous utilisez un certificat auto-signé, comme dans ce guide) |
| Secret partagé | Une valeur longue et aléatoire (ex. `openssl rand -hex 32`) — protège la navigation, **pas** `/render-api` (voir sécurité ci-dessous) |
| Préfixe de chemin — bibliothèque libre | `Library` |
| Préfixe de chemin — banque locale | `private` |
| Langue des messages WeBWorK | `en` (par défaut) ou `fr-CA` si vous utilisez la variante francisée du renderer |

## 4. Créer une question

1. *Banque de questions → Créer une nouvelle question → WeBWorK*
2. Choisissez un fichier via les boutons de navigation (« Banque de problèmes libres » /
   « Banque de problèmes locaux »), ou tapez le chemin directement
3. Pour créer un **nouveau** problème : bouton « Créer / éditer un problème » (ouvre l'éditeur
   natif du renderer). Le renderer refuse d'écrire sous `Library/` (contenu officiel, lecture
   seule) — utilisez toujours un chemin `private/...` pour du nouveau contenu.
4. Réglez le mode de notation, les indices/solutions, la graine aléatoire, et l'**aide à la
   saisie mathématique** (MathQuill, MathView, ou aucun éditeur — MathView par défaut). Ce
   choix nécessite que le renderer ait bien le correctif `patch_renderproblem.pl` appliqué (voir
   [docs/PATCHES.md](https://github.com/JonathanDesaulniers/webwork-renderer/blob/main/docs/PATCHES.md))
   pour que MathView fonctionne réellement — sans lui, MathQuill s'affiche toujours peu importe
   ce choix.
5. **Aide à la saisie des réponses** : MathView (par défaut), MathQuill, ou aucun éditeur assisté
   — voir le [docs/PATCHES.md](https://github.com/JonathanDesaulniers/webwork-renderer/blob/main/docs/PATCHES.md)
   pour le détail des correctifs nécessaires côté serveur pour que ce choix fonctionne réellement
6. Enregistrez — la question est prête à être ajoutée à un quiz

## Informations de débogage (enseignants)

Sous chaque question corrigée s'affiche un bloc repliable **« Informations de débogage
(enseignants seulement) »**, visible uniquement pour qui possède la capacité `mod/quiz:grade`
(enseignants, correcteurs) — jamais pour les étudiants. Il contient :

- La **graine WeBWorK** (`problemSeed`) réellement utilisée pour générer cette instance précise
  du problème
- Le **chemin du fichier `.pg`** source
- L'**historique complet des réponses** soumises pour cette tentative : numéro, horodatage,
  contenu de chaque champ, et note obtenue

**Pourquoi c'est utile** : le couple fichier `.pg` + graine est entièrement déterministe. En
reprenant la graine affichée ici, vous pouvez reproduire à l'identique la version du problème
qu'un étudiant précis a vue (par exemple dans le renderer standalone, ou en créant une question
de test avec cette graine fixe) — indispensable pour déboguer un problème dont le code comporte
une erreur qui ne se manifeste que pour certaines valeurs aléatoires.

Le bloc apparaît dans la zone de rétroaction de la question, donc typiquement sur la page de
relecture d'une tentative, ou après un « Vérifier » en mode interactif.

## Importation en lot

Plutôt que de créer les questions une par une, vous pouvez importer **tout un dossier** de
problèmes d'un coup.

Depuis une banque de questions, ouvrez l'onglet **« Importer WeBWorK »** (fourni par le plugin
compagnon `qbank_webworkimport`, à côté des onglets natifs Questions / Catégories / Importation /
Exportation).

*(Sans ce plugin compagnon, l'importation reste accessible en remplaçant `edit.php` par
`type/webwork/import.php` dans l'URL de votre banque de questions, en conservant les
paramètres.)*

Vous choisissez :
- la **banque de problèmes** (locale `private` ou bibliothèque libre) ;
- le **dossier** à importer, sans le préfixe de racine (ex. `MonNom/Algebre`) ;
- s'il faut **inclure les sous-dossiers** ;
- les **réglages appliqués** à toutes les questions créées (aide à la saisie, mode de notation,
  stratégie de graine, indices/solutions).

Ce qui se passe :
- une **question par fichier `.pg`** trouvé, nommée d'après le fichier (sans l'extension) ;
- chaque **sous-dossier devient une sous-catégorie** de la banque, reproduisant l'arborescence ;
- les fichiers qui ne sont pas des `.pg` (images, textes, etc.) sont ignorés ;
- un rapport détaillé s'affiche à la fin : questions créées, ignorées, et échecs éventuels.

**Déduplication** : elle se fait **par catégorie**. Un fichier `.pg` déjà importé dans une
catégorie n'y sera pas réimporté ; mais le même fichier rangé dans un autre dossier (donc une
autre catégorie) crée bien une question distincte. Vous pouvez donc relancer l'importation d'un
dossier après y avoir ajouté de nouveaux problèmes, sans créer de doublons.

**Limites de sécurité** : maximum 2000 fichiers et 10 niveaux de profondeur par importation.
Attention si vous importez depuis la racine de la bibliothèque libre — elle contient des dizaines
de milliers de problèmes.

## Sécurité — ce qu'il faut absolument savoir

Le renderer n'a **aucune notion d'identité ou de rôle** au niveau de `/render-api` : n'importe
qui pouvant l'atteindre sur le réseau peut potentiellement demander lui-même l'affichage de
solutions, en contournant complètement les réglages `showSolutions`/mode différé de Moodle. Le
secret partagé configuré dans ce plugin protège uniquement la **navigation** en arborescence
(noms de fichiers), **pas** le rendu des problèmes lui-même.

**La seule protection réellement efficace pour `/render-api` est la restriction réseau** — voir
la section pare-feu du [docs/INSTALLATION.md](https://github.com/JonathanDesaulniers/webwork-renderer/blob/main/docs/INSTALLATION.md#pare-feu--sécurité-réseau).
Assurez-vous que seul votre serveur Moodle peut atteindre le renderer, avant de mettre ce
plugin en production avec de vrais étudiants.

Autres couches de sécurité déjà en place dans le plugin :
- Vérification de capacité Moodle (`moodle/question:add`) au bon contexte pour la navigation et l'édition
- HTTPS de bout en bout entre Moodle et Caddy

## Limitations connues

- Les ~185 messages bas niveau du Parser (variante fr-CA du renderer) restent toujours en
  français, même si `language=en` est envoyé — voir [docs/TRADUCTION.md](https://github.com/JonathanDesaulniers/webwork-renderer/blob/main/docs/TRADUCTION.md)
- La traduction des fichiers macro individuels (au-delà du Parser/Value et de MathView) suit une
  politique **incrémentale** — seuls les messages réellement rencontrés en usage sont traduits au
  fil du temps, pas l'intégralité de la bibliothèque PG. Voir
  [docs/TRADUCTION.md](https://github.com/JonathanDesaulniers/webwork-renderer/blob/main/docs/TRADUCTION.md#couverture-incrémentale-des-messages-macro-spécifiques)
- La fenêtre d'édition intégrée exige d'accepter le certificat auto-signé du renderer
  manuellement, une première fois, dans un onglet séparé (sans quoi elle reste vide silencieusement)
- La navigation « banque locale » ne liste que le contenu déjà présent dans le dossier monté
  côté Caddy — un fichier créé via l'éditeur intégré n'apparaît dans la navigation qu'après un
  export/montage approprié côté serveur (voir le renderer README)
- Le choix MathQuill/MathView/aucun (par question) nécessite `patch_renderproblem.pl` côté
  renderer — sans lui, MathQuill s'affiche toujours par défaut peu importe le réglage choisi
- Fonctionnalités PG avancées (applets Java/JS spécialisés, macros peu courantes) non
  exhaustivement testées

## Structure des dossiers

```
qtype_webwork/
├── classes/            webwork_client.php (appels HTTP au renderer), response_parser.php
├── ajax/                browse.php (navigation en arborescence)
├── db/                  install.xml, upgrade.php, caches.php
├── lang/en/             chaînes de langue
├── edit_webwork_form.php
├── question.php
├── questiontype.php
├── renderer.php
└── settings.php         réglages admin (serveur, secret, langue, préfixes)

qbehaviour_webwork/
├── behaviour.php        logique de notation (différé/interactif)
├── behaviourtype.php
├── renderer.php
└── lang/en/

qbank_webworkimport/     (→ question/bank/webworkimport/)
├── classes/
│   ├── navigation.php       l'onglet lui-même
│   └── plugin_feature.php   déclaration à Moodle
├── lang/en/
└── version.php
```
