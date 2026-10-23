<?php

use GlpiPlugin\Dsimanager\RoadmapItem;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, READ);

$canedit = Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, UPDATE);

function dsimanager_monday_of_conges(string $date): string
{
    $dt  = new DateTime($date);
    $dow = (int) $dt->format('N');
    if ($dow > 1) {
        $dt->modify('-' . ($dow - 1) . ' days');
    }
    return $dt->format('Y-m-d');
}

function dsimanager_week_label_conges(string $monday): string
{
    $dt = new DateTime($monday);
    return 'S' . $dt->format('W') . '-' . $dt->format('y');
}

/**
 * Mois majoritaire d'une semaine (voir front/roadmap.php pour
 * l'explication complète - une semaine à cheval sur deux mois doit être
 * rattachée à celui qui contient le plus de jours, pas systématiquement
 * au mois du lundi).
 */
function dsimanager_week_majority_month_conges(string $monday): array
{
    $counts = [];
    $dt = new DateTime($monday);
    for ($i = 0; $i < 7; $i++) {
        $key = $dt->format('n') . '-' . $dt->format('Y');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $dt->modify('+1 day');
    }
    arsort($counts);
    $majority_key = array_key_first($counts);
    [$month, $year] = explode('-', $majority_key);
    return [(int) $month, (int) $year];
}

$start_date  = dsimanager_monday_of_conges($_GET['start_date'] ?? date('Y-m-d', strtotime('-2 weeks')));
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

// Regroupement des semaines par mois (même logique que front/roadmap.php,
// pour un affichage cohérent des deux côtés).
$dsimanager_month_names = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];
$month_groups = [];
foreach ($weeks as $week) {
    [$month, $year] = dsimanager_week_majority_month_conges($week);
    $label = $dsimanager_month_names[$month] . ' ' . $year;
    if (count($month_groups) && end($month_groups)['label'] === $label) {
        $month_groups[array_key_last($month_groups)]['colspan']++;
    } else {
        $month_groups[] = ['label' => $label, 'colspan' => 1];
    }
}

global $DB;

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

$conges_values = [];
if (count($team)) {
    $result = $DB->request([
        'FROM'  => 'glpi_plugin_dsimanager_conges',
        'WHERE' => ['users_id' => array_column($team, 'users_id')],
    ]);
    foreach ($result as $row) {
        $conges_values[$row['users_id']][$row['week_date']] = [
            'days_off' => (int) $row['days_off'],
            'note'     => $row['note'] ?? '',
        ];
    }
}

// Semaines qui débutent un nouveau mois (pour un séparateur vertical visible
// sur toutes les lignes, pas seulement l'en-tête).
$month_start_weeks = [];
$prev_label = null;
foreach ($weeks as $week) {
    [$month, $year] = dsimanager_week_majority_month_conges($week);
    $label = $dsimanager_month_names[$month] . ' ' . $year;
    if ($label !== $prev_label) {
        $month_start_weeks[$week] = true;
    }
    $prev_label = $label;
}

Html::header(
    __('Gérer les congés', 'dsimanager'),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    RoadmapItem::class,
    'roadmap'
);

echo "<div class='firstbloc'>";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmap.php' class='btn btn-outline-secondary btn-sm'>"
   . __('Retour à la Roadmap', 'dsimanager') . "</a>";
echo "</div>";

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

if (!count($team)) {
    echo "<div class='firstbloc'><p>" . __('Aucun membre dans l\'équipe pour le moment.', 'dsimanager') . " ";
    echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_team.php'>" . __('Gérer l\'équipe', 'dsimanager') . "</a></p></div>";
    Html::footer();
    exit;
}

echo "<div class='firstbloc'>";
echo "<p class='text-muted'>" . __('0 à 5 jours par semaine (semaine ouvrée). 5 = case pleine sur la page principale. Une note courte peut être ajoutée sur chaque semaine.', 'dsimanager') . "</p>";
echo "<div style='overflow-x:auto;'>";
echo "<form method='post' action='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_save.php'>";
echo "<table class='tab_cadre_fixe'>";
echo "<tr><th></th>";
foreach ($month_groups as $group) {
    echo "<th colspan='{$group['colspan']}' style='font-size:0.75em; background:#f1f3f5; border-left:2px solid #adb5bd;'>" . htmlspecialchars($group['label']) . "</th>";
}
echo "</tr>";
echo "<tr><th>" . __('Personne', 'dsimanager') . "</th>";
foreach ($weeks as $week) {
    $month_sep = isset($month_start_weeks[$week]) ? "border-left:2px solid #adb5bd;" : "";
    echo "<th style='min-width:110px; font-size:0.75em; {$month_sep}'>" . dsimanager_week_label_conges($week) . "</th>";
}
echo "</tr>";

foreach ($team as $member) {
    $label = trim($member['firstname'] . ' ' . $member['realname']) ?: $member['login'];
    echo "<tr class='tab_bg_1'><td>" . htmlspecialchars($label) . "</td>";
    foreach ($weeks as $week) {
        $cell = $conges_values[$member['users_id']][$week] ?? ['days_off' => 0, 'note' => ''];
        $uid  = (int) $member['users_id'];
        $month_sep = isset($month_start_weeks[$week]) ? "border-left:2px solid #adb5bd;" : "";
        if ($canedit) {
            echo "<td style='{$month_sep}'>";
            echo "<select name='conges[{$uid}][{$week}][days_off]' style='width:50px;'>";
            for ($d = 0; $d <= 5; $d++) {
                $sel = $d === $cell['days_off'] ? 'selected' : '';
                echo "<option value='{$d}' {$sel}>{$d}</option>";
            }
            echo "</select> ";
            echo "<input type='text' name='conges[{$uid}][{$week}][note]' value=\"" . htmlspecialchars($cell['note']) . "\" placeholder='" . __('note', 'dsimanager') . "' style='width:50px; font-size:0.8em;'>";
            echo "</td>";
        } else {
            echo "<td class='center' style='{$month_sep}'>{$cell['days_off']}" . ($cell['note'] !== '' ? " - " . htmlspecialchars($cell['note']) : '') . "</td>";
        }
    }
    echo "</tr>";
}

echo "</table>";
if ($canedit) {
    echo "<button type='submit' name='save_conges' class='btn btn-primary' style='margin-top:10px;'>"
       . __('Enregistrer les congés', 'dsimanager') . "</button>";
}
Html::closeForm();
echo "</div>";
echo "</div>";

Html::footer();
