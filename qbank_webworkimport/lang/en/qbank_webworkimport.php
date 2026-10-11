<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'WeBWorK — importation et aperçu';
$string['privacy:metadata'] = 'Le plugin « Importer WeBWorK » n\'enregistre aucune donnée personnelle : il ne fait qu\'ajouter un point d\'entrée vers l\'importation en lot fournie par le type de question WeBWorK.';
$string['webworkimport'] = 'Importer WeBWorK';
$string['previewcolumn'] = 'Aperçu WeBWorK';
$string['previewshow'] = 'Afficher l\'aperçu';
$string['previewhide'] = 'Masquer l\'aperçu';
$string['previewloading'] = 'Chargement de l\'aperçu…';
$string['previewnotfound'] = 'Question introuvable.';
$string['previewnotwebwork'] = 'Cette question n\'est pas une question WeBWorK.';
$string['previewempty'] = 'Le renderer n\'a renvoyé aucun contenu pour ce problème. Vérifiez que le fichier .pg existe toujours et ne comporte pas d\'erreur.';
$string['previewheight'] = 'Hauteur de l\'aperçu (px)';
$string['previewheight_help'] = 'Hauteur de la fenêtre d\'aperçu dans la banque de questions. Le contenu plus long défile à l\'intérieur. Une hauteur fixe est volontaire : un redimensionnement automatique ferait sauter les lignes de la banque à chaque rendu de formule, et laisserait un grand vide après avoir replié une solution. Valeur par défaut : 350.';
$string['previewwidth'] = 'Largeur de l\'aperçu (px)';
$string['previewwidth_help'] = 'Largeur de la fenêtre d\'aperçu. Elle déborde volontairement de sa colonne : élargir la colonne elle-même repousserait toutes les suivantes loin vers la droite pour rien, leur contenu tenant sur une seule ligne. Le débordement occupe cet espace libre sans déranger la mise en page du tableau. Valeur par défaut : 500.';
