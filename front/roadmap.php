<?php

use GlpiPlugin\Dsimanager\RoadmapItem;
use GlpiPlugin\Dsimanager\RoadmapType;
use GlpiPlugin\Dsimanager\RoadmapPriority;
use GlpiPlugin\Dsimanager\RoadmapStatus;
use GlpiPlugin\Dsimanager\RoadmapWeekCategory;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, READ);

$canedit = Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, UPDATE);

/**
 * Ramène une date au lundi de sa semaine (les semaines sont toujours
 * représentées par la date de leur lundi dans ce module).
 */
function dsimanager_monday_of(string $date): string
{
    $dt  = new DateTime($date);
    $dow = (int) $dt->format('N'); // 1 (lundi) à 7 (dimanche)
    if ($dow > 1) {
        $dt->modify('-' . ($dow - 1) . ' days');
    }
    return $dt->format('Y-m-d');
}

/**
 * Détermine le mois (et l'année) majoritaire d'une semaine donnée par son
 * lundi : compte le mois de chacun des 7 jours (lundi à dimanche) et
 * retient celui qui revient le plus souvent, plutôt que de se baser
 * uniquement sur le mois du lundi. Une semaine à cheval sur deux mois
 * (ex. lundi 31 août, le reste en septembre) doit ainsi être rattachée au
 * mois qui contient la majorité de ses jours (septembre dans cet exemple),
 * pas systématiquement au mois du premier jour.
 */
function dsimanager_week_majority_month(string $monday): array
{
    $counts = [];
    $dt = new DateTime($monday);
    for ($i = 0; $i < 7; $i++) {
        $key = $dt->format('n') . '-' . $dt->format('Y');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $dt->modify('+1 day');
    }
    arsort($counts); // le plus grand nombre de jours en tête
    $majority_key = array_key_first($counts);
    [$month, $year] = explode('-', $majority_key);
    return [(int) $month, (int) $year];
}

/**
 * Libellé court d'une semaine à partir de son lundi : "S27-26" (numéro de
 * semaine ISO + 2 derniers chiffres de l'année, pour lever toute ambiguïté
 * en cas de débord sur l'année suivante).
 */
function dsimanager_week_label(string $monday): string
{
    $dt = new DateTime($monday);
    return 'S' . $dt->format('W') . '-' . $dt->format('y');
}

// Largeur d'une colonne de semaine, identique sur la grille Roadmap et le
// bandeau congés : c'est cette cohérence qui permet de synchroniser le
// défilement horizontal des deux tableaux en JS (voir plus bas). Élargie
// (70 -> 95) pour que les noms de catégorie de semaine ne soient plus
// coupés.
const DSIMANAGER_WEEK_COL_WIDTH = 95;

// Largeurs fixes des colonnes figées (Type -> Modifier), utilisées pour
// calculer les décalages "sticky" (à la Excel). Le bandeau congés n'a
// qu'une seule colonne figée ("Personne"), mais on la rend volontairement
// aussi large que la somme de toutes ces colonnes : ainsi, un même
// scrollLeft correspond à la même semaine affichée dans les deux tableaux,
// sans calcul de décalage supplémentaire à faire en JS.
// (Colonne "Ordre" retirée : l'ordre des projets n'a plus d'importance,
// le tri par défaut se fait maintenant sur la priorité.)
$frozen_widths = [
    'type'        => 90,
    'description' => 150,
    'details'     => 150,
    'priority'    => 70,
    'users_id'    => 110,
    'status'      => 100,
    'budget'      => 80,
    'modify'      => 90,
];
$frozen_offsets = [];
$offset = 0;
foreach ($frozen_widths as $key => $width) {
    $frozen_offsets[$key] = $offset;
    $offset += $width;
}
$frozen_total_width = $offset;
$last_frozen_key = array_key_last($frozen_widths);

/**
 * Nom de classe CSS pour une colonne figée donnée (une classe par colonne,
 * générée une seule fois dans le <style> de la page - voir
 * dsimanager_frozen_css() - plutôt que de répéter position/left/etc en
 * inline sur chaque cellule de chaque ligne, ce qui pèse vite lourd sur
 * une grille avec beaucoup de projets).
 */
function dsimanager_frozen_class(string $key, bool $is_header): string
{
    return "at-frz-{$key}" . ($is_header ? ' at-zh' : ' at-zb');
}

/**
 * Génère le bloc CSS correspondant à toutes les colonnes figées, à insérer
 * une seule fois dans <style> en haut de page.
 */
function dsimanager_frozen_css(array $frozen_offsets, string $last_frozen_key): string
{
    $css = ".at-zh{z-index:5;} .at-zb{z-index:3;}\n";
    foreach ($frozen_offsets as $key => $left) {
        $border_right = $key === $last_frozen_key ? '3px solid #6c757d' : '1px solid #dee2e6';
        $css .= ".at-frz-{$key}{position:sticky;left:{$left}px;background:#fff;box-sizing:border-box;padding:4px 6px;border-right:{$border_right};border-bottom:1px solid #dee2e6;}\n";
    }
    return $css;
}

$start_date  = dsimanager_monday_of($_GET['start_date'] ?? date('Y-m-d', strtotime('-2 weeks')));
$weeks_count = (int) ($_GET['weeks_count'] ?? 52);
if ($weeks_count < 4) {
    $weeks_count = 4;
}
if ($weeks_count > 104) {
    $weeks_count = 104;
}

$weeks = [];
$cursor = new DateTime($start_date);
for ($i = 0; $i < $weeks_count; $i++) {
    $weeks[] = $cursor->format('Y-m-d');
    $cursor->modify('+7 days');
}

global $DB;

// --- Dropdowns ---
$types      = iterator_to_array($DB->request(['FROM' => RoadmapType::getTable()]));
$priorities = iterator_to_array($DB->request(['FROM' => RoadmapPriority::getTable()]));
$statuses   = iterator_to_array($DB->request(['FROM' => RoadmapStatus::getTable()]));
$categories = iterator_to_array($DB->request(['FROM' => RoadmapWeekCategory::getTable(), 'ORDER' => ['ranking ASC', 'name ASC']]));

// --- Lignes de la Roadmap (les projets archivés sont masqués ici, voir
// front/roadmap_archive.php pour les consulter/restaurer) ---
$items = iterator_to_array($DB->request([
    'FROM'  => RoadmapItem::getTable(),
    'WHERE' => ['is_archived' => 0],
    'ORDER' => ['id ASC'],
]));

// --- Ressources multiples par ligne (many-to-many), + initiales de
// l'équipe pour un affichage compact en grille ---
$resource_ids = []; // [item_id] => [users_id, ...]
if (count($items)) {
    $result = $DB->request([
        'FROM'  => 'glpi_plugin_dsimanager_roadmapitems_users',
        'WHERE' => ['plugin_dsimanager_roadmapitems_id' => array_column($items, 'id')],
    ]);
    foreach ($result as $row) {
        $resource_ids[$row['plugin_dsimanager_roadmapitems_id']][] = $row['users_id'];
    }
}
$team_initials = RoadmapItem::getTeamInitialsArray();

function dsimanager_resources_label(array $user_ids, array $team_initials): string
{
    $labels = [];
    foreach ($user_ids as $uid) {
        $labels[] = !empty($team_initials[$uid]) ? $team_initials[$uid] : getUserName($uid);
    }
    return implode(', ', $labels);
}

// --- Tri (en PHP, après chargement - évite toute syntaxe SQL de tri sur
// des libellés joints dont je ne suis pas certain à 100%). Par défaut :
// priorité croissante (l'ordre manuel des projets n'a plus d'importance).
$sort_key = $_GET['sort'] ?? 'priority';
$sort_dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$sort_value = function (array $item) use ($sort_key, $types, $priorities, $statuses): mixed {
    switch ($sort_key) {
        case 'type':         return strtolower($types[$item['plugin_dsimanager_roadmaptypes_id']]['name'] ?? '');
        case 'description':  return strtolower($item['description'] ?? '');
        case 'status':       return strtolower($statuses[$item['plugin_dsimanager_roadmapstatuses_id']]['name'] ?? '');
        case 'budget':       return (float) ($item['budget'] ?? 0);
        case 'priority':
        default:             return (int) ($priorities[$item['plugin_dsimanager_roadmappriorities_id']]['name'] ?? 0);
    }
};

usort($items, function ($a, $b) use ($sort_value, $sort_dir) {
    $va = $sort_value($a);
    $vb = $sort_value($b);
    $cmp = $va <=> $vb;
    return $sort_dir === 'desc' ? -$cmp : $cmp;
});

/**
 * Construit l'URL de tri pour un en-tête de colonne cliquable, avec une
 * petite flèche indiquant le sens courant si la grille est déjà triée sur
 * cette colonne.
 */
function dsimanager_sort_link(string $key, string $label, string $current_key, string $current_dir): string
{
    $next_dir = ($current_key === $key && $current_dir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($current_key === $key) {
        $arrow = $current_dir === 'asc' ? ' ▲' : ' ▼';
    }
    $qs = $_GET;
    $qs['sort'] = $key;
    $qs['dir']  = $next_dir;
    $url = '?' . http_build_query($qs);
    return "<a href='{$url}' style='color:inherit; text-decoration:none;'>" . htmlspecialchars($label) . $arrow . "</a>";
}

// --- Cases hebdomadaires : uniquement celles de la fenêtre affichée.
// Le filtre 'week_date' => $weeks (liste exacte des dates affichées)
// utilise le même mécanisme IN(...) déjà éprouvé ailleurs dans ce fichier
// (ids d'items, ids d'utilisateurs) - pas de syntaxe de plage de dates
// incertaine, juste une liste de valeurs exactes. Important pour les
// performances : sans ce filtre, un projet ancien avec beaucoup
// d'historique chargeait toutes ses cases, même celles hors écran. ---
$cell_values = []; // [item_id][week_date] = category_id
if (count($items)) {
    $result = $DB->request([
        'FROM'  => 'glpi_plugin_dsimanager_roadmapcells',
        'WHERE' => [
            'plugin_dsimanager_roadmapitems_id' => array_column($items, 'id'),
            'week_date'                        => $weeks,
        ],
    ]);
    foreach ($result as $row) {
        $cell_values[$row['plugin_dsimanager_roadmapitems_id']][$row['week_date']] = $row['plugin_dsimanager_roadmapweekcategories_id'];
    }
}

// --- Équipe et congés (lecture seule sur cette page - gérés depuis
// front/roadmap_conges.php) ---
$team = iterator_to_array($DB->request([
    'SELECT' => ['glpi_plugin_dsimanager_team.id AS team_id', 'glpi_users.id AS users_id', 'glpi_users.firstname', 'glpi_users.realname', 'glpi_users.name AS login'],
    'FROM'   => 'glpi_plugin_dsimanager_team',
    'INNER JOIN' => [
        'glpi_users' => [
            'FKEY' => [
                'glpi_plugin_dsimanager_team' => 'users_id',
                'glpi_users'                 => 'id',
            ],
        ],
    ],
]));

$conges_values = []; // [users_id][week_date] = ['days_off' => int, 'note' => string]
if (count($team)) {
    $result = $DB->request([
        'FROM'  => 'glpi_plugin_dsimanager_conges',
        'WHERE' => [
            'users_id'  => array_column($team, 'users_id'),
            'week_date' => $weeks,
        ],
    ]);
    foreach ($result as $row) {
        $conges_values[$row['users_id']][$row['week_date']] = [
            'days_off' => (int) $row['days_off'],
            'note'     => $row['note'] ?? '',
        ];
    }
}

// --- Regroupement des semaines par mois (bandeau congés ET grille
// Roadmap partagent ce calcul, pour un affichage cohérent) ---
$dsimanager_month_names = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];
$month_groups = [];
foreach ($weeks as $week) {
    [$month, $year] = dsimanager_week_majority_month($week);
    $label = $dsimanager_month_names[$month] . ' ' . $year;
    if (count($month_groups) && end($month_groups)['label'] === $label) {
        $month_groups[array_key_last($month_groups)]['colspan']++;
    } else {
        $month_groups[] = ['label' => $label, 'colspan' => 1];
    }
}

// --- Semaine courante, pour l'encadrer en rouge ---
$current_week = dsimanager_monday_of(date('Y-m-d'));

Html::header(
    __('Roadmap', 'dsimanager'),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    RoadmapItem::class,
    'roadmap'
);

// Classes CSS partagées pour les éléments répétés sur chaque case de la
// grille (select invisible, div décoratif, cellule) : sur une grille avec
// beaucoup de projets et de semaines, répéter ces styles en inline sur
// chaque cellule alourdissait sensiblement le HTML envoyé au navigateur.
echo "<style>
.at-wsel{position:absolute;inset:0;width:100%;height:100%;opacity:0;margin:0;border:none;}
.at-wdiv{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:0.7em;text-align:center;pointer-events:none;overflow:hidden;padding:0 2px;}
.at-wtd{padding:0;position:relative;height:28px;}
.at-wro{text-align:center;font-size:0.75em;}
.at-ell{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
" . dsimanager_frozen_css($frozen_offsets, $last_frozen_key) . "
</style>";

// =====================================================================
// Barre d'actions en haut de page (équipe, congés, listes déroulantes)
// =====================================================================
echo "<div class='firstbloc' style='display:flex; flex-wrap:wrap; gap:6px; align-items:center; padding:8px; background:#f8f9fa; border-radius:6px;'>";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_team.php' class='btn btn-outline-secondary btn-sm'>"
   . __('Gérer l\'équipe', 'dsimanager') . "</a>";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_conges.php' class='btn btn-outline-secondary btn-sm'>"
   . __('Gérer les congés', 'dsimanager') . "</a>";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmaptype.php' class='btn btn-outline-secondary btn-sm'>"
   . __('Gérer les types', 'dsimanager') . "</a>";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmappriority.php' class='btn btn-outline-secondary btn-sm'>"
   . __('Gérer les priorités', 'dsimanager') . "</a>";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmapstatus.php' class='btn btn-outline-secondary btn-sm'>"
   . __('Gérer les statuts', 'dsimanager') . "</a>";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmapweekcategory.php' class='btn btn-outline-secondary btn-sm'>"
   . __('Gérer les catégories de semaine', 'dsimanager') . "</a> ";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_archive.php' class='btn btn-outline-secondary btn-sm'>"
   . __('Projets archivés', 'dsimanager') . "</a>";
echo "</div>";

// =====================================================================
// Barre de navigation de la fenêtre affichée
// =====================================================================
echo "<div class='firstbloc'>";
echo "<form method='get' class='form-inline'>";
echo "<label>" . __('Semaine de départ', 'dsimanager') . " ";
echo "<input type='date' name='start_date' value='{$start_date}'>";
echo "</label> ";
echo "<label>" . __('Nombre de semaines affichées', 'dsimanager') . " ";
echo "<select name='weeks_count'>";
foreach ([12, 26, 52, 104] as $opt) {
    $sel = $opt === $weeks_count ? "selected" : "";
    echo "<option value='{$opt}' {$sel}>{$opt}</option>";
}
echo "</select>";
echo "</label> ";
echo "<button type='submit' class='btn btn-primary btn-sm'>" . __('Afficher', 'dsimanager') . "</button>";
Html::closeForm();
echo "</div>";

// =====================================================================
// Bandeau congés - lecture seule ici (édition sur roadmap_conges.php).
// Figé en haut de la page, colonne "Personne" figée à gauche avec la
// même largeur que la zone figée de la grille Roadmap ci-dessous, pour
// que le défilement horizontal des deux tableaux reste synchronisé (voir
// le script en bas de page).
// =====================================================================
echo "<div class='firstbloc' style='position:sticky; top:0; z-index:20; background:#fff; padding:10px 0; border-bottom:2px solid #dee2e6;'>";
echo "<h3>" . __('Congés de l\'équipe', 'dsimanager') . "</h3>";
echo "<div id='dsimanager-conges-scroll' style='overflow-x:auto;'>";
echo "<table class='tab_cadre_fixe' style='table-layout:fixed; border-collapse:separate; border-spacing:0;'>";
echo "<colgroup>";
echo "<col style='width:{$frozen_total_width}px;'>";
foreach ($weeks as $week) {
    echo "<col style='width:" . DSIMANAGER_WEEK_COL_WIDTH . "px;'>";
}
echo "</colgroup>";
echo "<tr>";
echo "<th style='position:sticky; left:0; width:{$frozen_total_width}px; background:#fff; z-index:5; border-right:3px solid #6c757d;'></th>";
foreach ($month_groups as $group) {
    echo "<th colspan='{$group['colspan']}' style='font-size:0.75em; border-left:1px solid #dee2e6; border-bottom:1px solid #dee2e6; background:#f1f3f5;'>"
       . htmlspecialchars($group['label']) . "</th>";
}
echo "</tr>";
echo "<tr><th style='position:sticky; left:0; width:{$frozen_total_width}px; background:#fff; z-index:5; border-right:3px solid #6c757d; border-bottom:1px solid #dee2e6;'>"
   . __('Personne', 'dsimanager') . "</th>";
foreach ($weeks as $week) {
    $is_current = $week === $current_week;
    $current_border = $is_current ? 'border-left:2px solid #d63939; border-right:2px solid #d63939; border-top:2px solid #d63939;' : 'border-left:1px solid #dee2e6;';
    echo "<th style='font-size:0.75em; border-bottom:1px solid #dee2e6; {$current_border}'>" . dsimanager_week_label($week) . "</th>";
}
echo "</tr>";
foreach ($team as $team_index => $member) {
    $label = trim($member['firstname'] . ' ' . $member['realname']) ?: $member['login'];
    $is_last_team_row = $team_index === array_key_last($team);
    echo "<tr class='tab_bg_1'><td style='position:sticky; left:0; width:{$frozen_total_width}px; min-width:{$frozen_total_width}px; max-width:{$frozen_total_width}px; background:#fff; z-index:3; border-right:3px solid #6c757d;'>"
       . htmlspecialchars($label) . "</td>";
    foreach ($weeks as $week) {
        $cell     = $conges_values[$member['users_id']][$week] ?? ['days_off' => 0, 'note' => ''];
        $days     = $cell['days_off'];
        $note     = $cell['note'];
        if ($days >= 5) {
            $bg = '#cfe2ff'; // bleu pâle : semaine complète
            $content = '';
        } elseif ($days > 0) {
            $bg = '#ffffff';
            $content = str_repeat('*', $days);
        } else {
            $bg = '#ffffff';
            $content = '';
        }
        $title = $note !== '' ? " title=\"" . htmlspecialchars($note) . "\"" : '';
        $is_current_week = $week === $current_week;
        if ($is_current_week) {
            $week_border = "border-left:2px solid #d63939; border-right:2px solid #d63939; "
                         . ($is_last_team_row ? "border-bottom:2px solid #d63939;" : "border-bottom:1px solid #dee2e6;");
        } else {
            $week_border = "border-left:1px solid #dee2e6; border-bottom:1px solid #dee2e6;";
        }
        echo "<td class='center' style='background:{$bg}; font-size:0.75em; {$week_border}'{$title}>{$content}"
           . ($note !== '' ? "<br><span style='color:#6c757d;'>" . htmlspecialchars(mb_strimwidth($note, 0, 12, '…')) . "</span>" : "")
           . "</td>";
    }
    echo "</tr>";
}
echo "</table>";
echo "</div>";
echo "</div>";

// =====================================================================
// Grille Roadmap - un seul <form> pour tout le tableau.
// =====================================================================
echo "<div class='firstbloc'>";
echo "<h3>" . __('Roadmap', 'dsimanager') . "</h3>";
if ($canedit) {
    echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmapitem.form.php' class='btn btn-primary btn-sm' style='margin-bottom:8px;'>"
       . __('Nouveau projet', 'dsimanager') . "</a>";
}
echo "<form method='post' action='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_save.php'>";
echo "<div id='dsimanager-roadmap-scroll' style='overflow-x:auto;'>";
echo "<table class='tab_cadre_fixe' style='table-layout:fixed; border-collapse:separate; border-spacing:0;'>";
echo "<colgroup>";
foreach ($frozen_widths as $width) {
    echo "<col style='width:{$width}px;'>";
}
foreach ($weeks as $week) {
    echo "<col style='width:" . DSIMANAGER_WEEK_COL_WIDTH . "px;'>";
}
echo "</colgroup>";

$col_keys = ['type', 'description', 'details', 'priority', 'users_id', 'status', 'budget', 'modify'];
$col_labels = [
    'type'        => dsimanager_sort_link('type', __('Type', 'dsimanager'), $sort_key, $sort_dir),
    'description' => dsimanager_sort_link('description', __('Description', 'dsimanager'), $sort_key, $sort_dir),
    'details'     => __('Détails', 'dsimanager'),
    'priority'    => dsimanager_sort_link('priority', __('Priorité', 'dsimanager'), $sort_key, $sort_dir),
    'users_id'    => __('Ressource(s)', 'dsimanager'),
    'status'      => dsimanager_sort_link('status', __('Statut', 'dsimanager'), $sort_key, $sort_dir),
    'budget'      => dsimanager_sort_link('budget', __('Budget', 'dsimanager'), $sort_key, $sort_dir),
    'modify'      => __('Modifier', 'dsimanager'),
];

// Ligne des mois (fusionnée par mois, au-dessus des numéros de semaine).
echo "<tr>";
echo "<th colspan='" . count($col_keys) . "' style='position:sticky; left:0; width:{$frozen_total_width}px; min-width:{$frozen_total_width}px; max-width:{$frozen_total_width}px; background:#fff; z-index:5;'></th>";
foreach ($month_groups as $group) {
    echo "<th colspan='{$group['colspan']}' style='font-size:0.75em; border-left:1px solid #dee2e6; border-bottom:1px solid #dee2e6; background:#f1f3f5;'>"
       . htmlspecialchars($group['label']) . "</th>";
}
echo "</tr>";

echo "<tr>";
foreach ($col_keys as $key) {
    $class = dsimanager_frozen_class($key, true);
    echo "<th class='{$class}'>{$col_labels[$key]}</th>";
}
foreach ($weeks as $week) {
    $is_current = $week === $current_week;
    $current_border = $is_current ? 'border-left:2px solid #d63939; border-right:2px solid #d63939; border-top:2px solid #d63939;' : 'border-left:1px solid #dee2e6;';
    echo "<th style='font-size:0.75em; border-bottom:1px solid #dee2e6; {$current_border}'>" . dsimanager_week_label($week) . "</th>";
}
echo "</tr>";

foreach ($items as $item_index => $item) {
    $id = (int) $item['id'];
    $is_last_row = $item_index === array_key_last($items);
    echo "<tr class='tab_bg_1'>";

    $type_name      = $types[$item['plugin_dsimanager_roadmaptypes_id']]['name'] ?? '';
    $priority_name  = $priorities[$item['plugin_dsimanager_roadmappriorities_id']]['name'] ?? '';
    $status_name    = $statuses[$item['plugin_dsimanager_roadmapstatuses_id']]['name'] ?? '';
    $ressource_full = dsimanager_resources_label($resource_ids[$id] ?? [], $team_initials);

    // Colonnes descriptives : lecture seule, tronquées avec "..." et
    // l'intégralité du texte visible au survol (title) - l'édition
    // complète se fait sur la fiche dédiée (bouton "Modifier"), pour ne
    // plus dépendre de widgets qui s'étirent (select2 sur "Ressource"
    // notamment) et cassaient la mise en page de la grille.
    // Classe "at-ell" plutôt que le style répété sur chaque cellule.
    echo "<td class='" . dsimanager_frozen_class('type', false) . " at-ell' title=\"" . htmlspecialchars($type_name) . "\">" . htmlspecialchars($type_name) . "</td>";
    echo "<td class='" . dsimanager_frozen_class('description', false) . " at-ell' title=\"" . htmlspecialchars($item['description']) . "\">" . htmlspecialchars($item['description']) . "</td>";
    echo "<td class='" . dsimanager_frozen_class('details', false) . " at-ell' title=\"" . htmlspecialchars($item['details'] ?? '') . "\">" . htmlspecialchars($item['details'] ?? '') . "</td>";
    echo "<td class='" . dsimanager_frozen_class('priority', false) . " at-ell'>" . htmlspecialchars($priority_name) . "</td>";
    echo "<td class='" . dsimanager_frozen_class('users_id', false) . " at-ell' title=\"" . htmlspecialchars($ressource_full) . "\">" . htmlspecialchars($ressource_full) . "</td>";
    echo "<td class='" . dsimanager_frozen_class('status', false) . " at-ell'>" . htmlspecialchars($status_name) . "</td>";
    echo "<td class='" . dsimanager_frozen_class('budget', false) . " at-ell'>" . htmlspecialchars((string) ($item['budget'] ?? '')) . "</td>";
    echo "<td class='" . dsimanager_frozen_class('modify', false) . "'>";
    echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmapitem.form.php?id={$id}' class='btn btn-sm btn-outline-primary' style='white-space:nowrap; font-size:0.75em; padding:2px 6px;'>" . __('Modifier', 'dsimanager') . "</a>";
    echo "</td>";

    foreach ($weeks as $week) {
        $current = $cell_values[$id][$week] ?? 0;
        $is_current_week = $week === $current_week;
        // Largeurs volontairement absentes ici : le <colgroup> en tête de
        // tableau les fixe déjà pour toute la colonne, les répéter sur
        // chaque cellule n'apporterait qu'un poids inutile au HTML.
        if ($is_current_week) {
            $cell_style = "border-left:2px solid #d63939; border-right:2px solid #d63939; "
                        . ($is_last_row ? "border-bottom:2px solid #d63939;" : "border-bottom:1px solid #dee2e6;");
        } else {
            $cell_style = "border-left:1px solid #dee2e6; border-bottom:1px solid #dee2e6;";
        }
        if ($canedit) {
            $bgcolor = $current && isset($categories[$current]) ? $categories[$current]['color'] : '#ffffff';
            $label   = $current && isset($categories[$current]) ? $categories[$current]['name'] : '';
            $text_color = $current && isset($categories[$current]) ? '#fff' : '#000';
            // Après deux tentatives infructueuses pour forcer un <select>
            // fermé à afficher une couleur de fond (l'habillage natif de
            // l'OS/navigateur l'emporte trop souvent sur le CSS), nouvelle
            // approche plus fiable : un <select> invisible (opacity:0) qui
            // capte les clics et gère la sélection, superposé à un <div>
            // purement décoratif qui affiche la couleur et le texte - sur
            // le même principe que le rendu (fiable, lui) des congés en
            // lecture seule, mais qui reste ici cliquable pour éditer.
            // Les <option> ne sont plus générées ici : elles sont ajoutées
            // une seule fois en JS à partir d'un JSON commun (voir plus bas)
            // plutôt que d'être répétées dans chaque case.
            echo "<td class='at-wtd' style='{$cell_style}'>";
            echo "<select name='cells[{$id}][{$week}]' class='dsimanager-week-select at-wsel' data-project='{$id}' data-week='{$week}' data-value='{$current}'></select>";
            echo "<div class='at-wdiv' style='background-color:{$bgcolor}; color:{$text_color};'>" . htmlspecialchars($label) . "</div>";
            echo "</td>";
        } else {
            if ($current && isset($categories[$current])) {
                $cat = $categories[$current];
                echo "<td class='at-wro' style='{$cell_style} background-color:{$cat['color']}; color:#fff;'>" . htmlspecialchars($cat['name']) . "</td>";
            } else {
                echo "<td style='{$cell_style}'></td>";
            }
        }
    }

    echo "</tr>";
}

echo "</table>";
echo "</div>";
if ($canedit) {
    echo "<button type='submit' name='save_roadmap' class='btn btn-primary' style='margin-top:10px;'>"
       . __('Enregistrer la Roadmap', 'dsimanager') . "</button>";
}
Html::closeForm();
echo "</div>";

// =====================================================================
// Synchronisation du défilement horizontal entre le bandeau congés et la
// grille Roadmap (les deux ont la même largeur de zone figée et la même
// largeur de colonne par semaine, donc un simple recopiage de scrollLeft
// suffit à garder les semaines alignées visuellement entre les deux).
// =====================================================================
echo "<script>
(function() {
    var a = document.getElementById('dsimanager-conges-scroll');
    var b = document.getElementById('dsimanager-roadmap-scroll');
    if (!a || !b) { return; }
    var syncing = false;
    a.addEventListener('scroll', function() {
        if (syncing) { return; }
        syncing = true;
        b.scrollLeft = a.scrollLeft;
        syncing = false;
    });
    b.addEventListener('scroll', function() {
        if (syncing) { return; }
        syncing = true;
        a.scrollLeft = b.scrollLeft;
        syncing = false;
    });
})();
</script>";

// Peuplement des listes déroulantes de catégorie en JavaScript, à partir
// d'un seul JSON transmis une fois, plutôt que de répéter la liste des
// catégories (autant de <option> que de catégories) dans chaque case
// générée côté serveur. Sur une grille avec beaucoup de projets et de
// semaines, cette répétition était la plus grosse source de poids inutile
// dans le HTML envoyé au navigateur.
$categories_json = json_encode(array_map(
    fn($cat) => ['name' => $cat['name'], 'color' => $cat['color']],
    $categories
));
echo "<script>
(function() {
    var categories = {$categories_json};

    function updateCellVisual(sel) {
        var div = sel.nextElementSibling;
        if (!div) { return; }
        var cat = categories[sel.value];
        if (cat) {
            div.style.backgroundColor = cat.color;
            div.style.color = '#fff';
            div.textContent = cat.name;
        } else {
            div.style.backgroundColor = '#ffffff';
            div.style.color = '#000';
            div.textContent = '';
        }
    }

    var catIds = Object.keys(categories);
    document.querySelectorAll('.dsimanager-week-select').forEach(function(sel) {
        var frag = document.createDocumentFragment();
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '0';
        frag.appendChild(emptyOpt);
        catIds.forEach(function(id) {
            var opt = document.createElement('option');
            opt.value = id;
            opt.textContent = categories[id].name;
            frag.appendChild(opt);
        });
        sel.appendChild(frag);
        sel.value = sel.getAttribute('data-value') || '0';
        sel.addEventListener('change', function() { updateCellVisual(sel); });
    });
})();
</script>";

Html::footer();
