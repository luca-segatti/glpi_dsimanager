<?php

/**
 * Module Stock - fiche d'une commande (entrée / sortie / transfert /
 * ajustement) : en-tête, lignes, validation, annulation, correction.
 * Contrôleur unique : chaque action est distinguée par le nom du bouton
 * posté (même convention que front/roadmap_save.php).
 */

use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockDocument;
use GlpiPlugin\Dsimanager\StockKit;
use GlpiPlugin\Dsimanager\StockDisposalReason;
use GlpiPlugin\Dsimanager\StockOrder;
use GlpiPlugin\Dsimanager\StockOrderLine;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

include('../../../inc/includes.php');

// Pas de Session::checkCSRF() manuel : GLPI 11 le vérifie au niveau du
// noyau sur tout POST (voir piège CSRF), à condition de fermer chaque
// formulaire avec Html::closeForm().

$self = Plugin::getWebDir('dsimanager') . '/front/stockorder.form.php';

/** Message d'erreur échappé (peut contenir des noms d'actifs saisis). */
$fail = static function (string $msg): void {
    Session::addMessageAfterRedirect(htmlspecialchars($msg), false, ERROR);
};

/** Charge une commande et vérifie le droit demandé sur son type. */
$loadOrder = static function (int $id, int $bit): StockOrder {
    $order = new StockOrder();
    if (!$order->getFromDB($id) || !StockOrder::canDoOnType((int) $order->fields['type'], $bit)) {
        throw new AccessDeniedHttpException();
    }
    return $order;
};

// ---------------------------------------------------------------------
// Actions POST
// ---------------------------------------------------------------------

if (isset($_POST['add_order'])) {
    $type = (int) ($_POST['type'] ?? 0);
    if (!StockOrder::canDoOnType($type, CREATE)) {
        throw new AccessDeniedHttpException();
    }
    $order = new StockOrder();
    $id = $order->add($_POST);
    if ($id) {
        Html::redirect($self . '?id=' . (int) $id);
    }
    Html::back();
}

if (isset($_POST['update_order'])) {
    $order = $loadOrder((int) $_POST['id'], UPDATE);
    $order->update($_POST);
    Html::back();
}

if (isset($_POST['add_line'])) {
    $order = $loadOrder((int) $_POST['id'], UPDATE);
    $err = $order->addLine($_POST);
    if ($err !== '') {
        $fail($err);
    }
    Html::back();
}

if (isset($_POST['delete_line'])) {
    $order = $loadOrder((int) $_POST['id'], UPDATE);
    $err = $order->deleteLine((int) $_POST['delete_line']);
    if ($err !== '') {
        $fail($err);
    }
    Html::back();
}

if (isset($_POST['validate_order'])) {
    $order = $loadOrder((int) $_POST['id'], PLUGIN_DSIMANAGER_STOCK_VALIDATE);
    $errors = $order->validateOrder();
    if (count($errors)) {
        $fail(__('Validation refusée, rien n\'a été modifié :', 'dsimanager') . ' ' . implode(' / ', $errors));
    } else {
        Session::addMessageAfterRedirect(htmlspecialchars(sprintf(__('Commande %s validée : stock mis à jour.', 'dsimanager'), $order->getReference())));
        // Bon de remise / reprise joint automatiquement au ticket lié (sauf
        // pour une commande corrective, qui n'est pas une vraie remise).
        if ((int) $order->fields['tickets_id'] > 0 && (int) $order->fields['corrects_id'] === 0 && StockDocument::supportsOrder($order)) {
            try {
                StockDocument::attachToTicket($order);
                Session::addMessageAfterRedirect(htmlspecialchars(sprintf(__('Bon PDF joint au ticket #%d.', 'dsimanager'), (int) $order->fields['tickets_id'])));
            } catch (Throwable $e) {
                Session::addMessageAfterRedirect(htmlspecialchars(__('Commande validée, mais le bon n\'a pas pu être joint au ticket :', 'dsimanager') . ' ' . $e->getMessage()), false, WARNING);
            }
        }
    }
    Html::back();
}

if (isset($_POST['attach_doc'])) {
    $order = new StockOrder();
    if (!$order->getFromDB((int) $_POST['id']) || !StockOrder::canDoOnType((int) $order->fields['type'], UPDATE)) {
        throw new AccessDeniedHttpException();
    }
    try {
        if (StockDocument::attachToTicket($order) > 0) {
            Session::addMessageAfterRedirect(htmlspecialchars(sprintf(__('Bon PDF joint au ticket #%d.', 'dsimanager'), (int) $order->fields['tickets_id'])));
        } else {
            $fail(__('Aucun ticket lié à cette commande.', 'dsimanager'));
        }
    } catch (Throwable $e) {
        $fail($e->getMessage());
    }
    Html::back();
}

if (isset($_POST['apply_kit'])) {
    $order = $loadOrder((int) $_POST['id'], UPDATE);
    $issues = StockKit::applyToOrder($order, (int) ($_POST['kits_id'] ?? 0));
    if (count($issues)) {
        $fail(__('Profil appliqué avec réserves :', 'dsimanager') . ' ' . implode(' / ', $issues));
    } else {
        Session::addMessageAfterRedirect(htmlspecialchars(__('Profil de dotation appliqué : articles ajoutés. Ajoutez maintenant les actifs unitaires prévus.', 'dsimanager')));
    }
    Html::back();
}

if (isset($_POST['set_line_link'])) {
    $order = $loadOrder((int) $_POST['id'], UPDATE);
    $err = $order->setLineLink((int) $_POST['set_line_link'], (int) ($_POST['link_lines_id'] ?? 0));
    if ($err !== '') {
        $fail($err);
    }
    Html::back();
}

if (isset($_POST['cancel_order'])) {
    $order = $loadOrder((int) $_POST['id'], UPDATE);
    if (!$order->cancelDraft()) {
        $fail(__('Seul un brouillon peut être annulé.', 'dsimanager'));
    }
    Html::back();
}

if (isset($_POST['correct_order'])) {
    $order = new StockOrder();
    if (!$order->getFromDB((int) $_POST['id']) || !StockOrder::canView()) {
        throw new AccessDeniedHttpException();
    }
    $result = $order->createCorrection();
    if (is_int($result)) {
        Session::addMessageAfterRedirect(htmlspecialchars(__('Commande corrective créée en brouillon : ajustez les lignes si besoin, puis validez.', 'dsimanager')));
        Html::redirect($self . '?id=' . $result);
    }
    $fail((string) $result);
    Html::back();
}

// ---------------------------------------------------------------------
// Affichage
// ---------------------------------------------------------------------

if (!StockOrder::canView()) {
    throw new AccessDeniedHttpException();
}

$order = new StockOrder();
$is_new = true;
if (!empty($_GET['id'])) {
    if (!$order->getFromDB((int) $_GET['id'])) {
        throw new NotFoundHttpException();
    }
    $is_new = false;
    $type = (int) $order->fields['type'];
    // Lecture : historique OU lecture sur le type concerné.
    if (!Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY, READ) && !StockOrder::canDoOnType($type, READ)) {
        throw new AccessDeniedHttpException();
    }
} else {
    $type = (int) ($_GET['type'] ?? 0);
    if (!StockOrder::canDoOnType($type, CREATE)) {
        throw new AccessDeniedHttpException();
    }
    $order->getEmpty();
    $order->fields['type']              = $type;
    $order->fields['status']            = StockOrder::STATUS_DRAFT;
    $order->fields['date_order']        = date('Y-m-d');
    $order->fields['users_id_executor'] = Session::getLoginUserID();
    $order->fields['users_id_requester'] = 0;
    // Préremplissage de l'emplacement : le premier emplacement actif.
    $first_loc = array_key_first(Stock::getLocations(true)) ?? 0;
    if (StockOrder::needsFrom($type)) {
        $order->fields['stocklocations_id_from'] = $first_loc;
    }
    if ($type === StockOrder::TYPE_DISPOSAL) {
        $order->fields['stocklocations_id_from'] = 0; // hors stock par défaut
    }
    if (StockOrder::needsTo($type)) {
        $order->fields['stocklocations_id_to'] = $type === StockOrder::TYPE_TRANSFER ? 0 : $first_loc;
    }
    // Création depuis un ticket : demandeur, bénéficiaire, service et
    // localisation repris du ticket.
    if (!empty($_GET['tickets_id'])) {
        foreach (StockOrder::getTicketPrefill((int) $_GET['tickets_id']) as $k => $v) {
            $order->fields[$k] = $v;
        }
    }
}

$is_draft    = (int) $order->fields['status'] === StockOrder::STATUS_DRAFT;
$can_edit    = $is_draft && StockOrder::canDoOnType($type, $is_new ? CREATE : UPDATE);
$can_valid   = !$is_new && $is_draft && StockOrder::canDoOnType($type, PLUGIN_DSIMANAGER_STOCK_VALIDATE);
$f           = $order->fields;
$locations   = Stock::getLocations(true);
$all_locs    = Stock::getLocations(false);

$title = $is_new
    ? sprintf(__('Nouvelle commande : %s', 'dsimanager'), StockOrder::getTypeLabel($type))
    : sprintf(__('Commande %s', 'dsimanager'), $order->getReference());

Html::header($title, $_SERVER['PHP_SELF'], 'dsimanager_stock', StockOrder::class);

Stock::showNavBar('history');

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h2 class='mb-3'>" . StockOrderLine::typeBadge($type) . " " . htmlspecialchars($title)
   . " <span class='badge ms-2' style='background:" . StockOrder::getStatusColor((int) $f['status']) . ";color:#fff;'>" . htmlspecialchars(StockOrder::getStatusLabel((int) $f['status'])) . "</span></h2>";

if (!$is_new && (int) $f['corrects_id'] > 0) {
    $orig = new StockOrder();
    if ($orig->getFromDB((int) $f['corrects_id'])) {
        echo "<div class='alert alert-warning'>" . __('Commande corrective de', 'dsimanager')
           . " <a href='$self?id=" . (int) $f['corrects_id'] . "'>" . $orig->getReference() . "</a></div>";
    }
}
if (!$is_new) {
    $corrections = $order->getCorrections();
    if (count($corrections)) {
        echo "<div class='alert alert-warning'>" . __('Corrigée par :', 'dsimanager') . " ";
        $links = [];
        foreach ($corrections as $c) {
            $links[] = "<a href='$self?id=" . (int) $c['id'] . "'>" . StockOrder::formatReference((int) $c['type'], (int) $c['id'])
                     . "</a> (" . htmlspecialchars(StockOrder::getStatusLabel((int) $c['status'])) . ")";
        }
        echo implode(', ', $links) . "</div>";
    }

    // Origine : formulaire natif GLPI
    $answerset_id = (int) ($f['forms_answerssets_id'] ?? 0);
    if ($answerset_id > 0 && class_exists(\Glpi\Form\AnswersSet::class)) {
        $as = new \Glpi\Form\AnswersSet();
        if ($as->getFromDB($answerset_id)) {
            echo "<div class='alert alert-info'><i class='ti ti-forms'></i> " . __('Créée depuis le formulaire :', 'dsimanager') . " "
               . ($as->canViewItem() ? "<a href='" . \Glpi\Form\AnswersSet::getFormURLWithID($answerset_id) . "'>" . htmlspecialchars((string) $as->fields['name']) . "</a>" : htmlspecialchars((string) $as->fields['name']))
               . "</div>";
        }
    }

    // Profil de dotation : avancement (prévu / présent)
    $progress = StockKit::getProgress($order);
    if (count($progress)) {
        $kit_name = Dropdown::getDropdownName(StockKit::getTable(), (int) $f['plugin_dsimanager_stockkits_id'], false, true, false);
        $complete = !count(array_filter($progress, static fn($p) => $p['present'] < $p['expected']));
        echo "<div class='alert " . ($complete ? 'alert-success' : 'alert-warning') . "'><div><strong><i class='ti ti-briefcase'></i> "
           . sprintf(__('Profil de dotation « %s »', 'dsimanager'), htmlspecialchars($kit_name)) . "</strong> : ";
        $parts = [];
        foreach ($progress as $p) {
            $ok = $p['present'] >= $p['expected'];
            $parts[] = "<span class='me-2 text-nowrap'><i class='ti " . ($ok ? 'ti-circle-check' : 'ti-alert-circle') . "'></i> "
                     . htmlspecialchars($p['label']) . " " . $p['present'] . "/" . $p['expected'] . "</span>";
        }
        echo implode(' ', $parts);
        if (!$complete && $is_draft) {
            echo "<div class='small mt-1'>" . __('Ajoutez les actifs unitaires prévus avant de valider (la validation reste possible si vous remettez autre chose).', 'dsimanager') . "</div>";
        }
        echo "</div></div>";
    }
}

// --- En-tête -----------------------------------------------------------

/** Affiche un <select> d'emplacement (inclut l'emplacement courant même inactif). */
$locSelect = static function (string $name, int $value) use ($locations, $all_locs): void {
    $opts = $locations;
    if ($value > 0 && !isset($opts[$value]) && isset($all_locs[$value])) {
        $opts[$value] = $all_locs[$value] . ' ' . __('(inactif)', 'dsimanager');
    }
    Dropdown::showFromArray($name, $opts, ['value' => $value, 'display_emptychoice' => true, 'width' => '100%']);
};

$userCell = static function (string $name, int $value, bool $edit): void {
    if ($edit) {
        User::dropdown(['name' => $name, 'value' => $value, 'right' => 'all', 'entity' => Session::getActiveEntity(), 'width' => '100%']);
    } else {
        echo $value ? htmlspecialchars(getUserName($value)) : '-';
    }
};

if ($can_edit) {
    echo "<form method='post' action='$self'>";
}
echo "<table class='tab_cadre_fixe'>";

echo "<tr class='tab_bg_1'>";
echo "<td style='width:20%'>" . __('Date', 'dsimanager') . "</td><td style='width:30%'>";
echo $can_edit
    ? "<input type='date' name='date_order' class='form-control' value='" . htmlspecialchars((string) $f['date_order']) . "' required>"
    : Html::convDate($f['date_order']);
echo "</td>";
echo "<td style='width:20%'>" . __('Demandeur', 'dsimanager') . "</td><td>";
$userCell('users_id_requester', (int) $f['users_id_requester'], $can_edit);
echo "</td></tr>";

echo "<tr class='tab_bg_1'>";
echo "<td>" . __('Exécutant', 'dsimanager') . "</td><td>";
$userCell('users_id_executor', (int) $f['users_id_executor'], $can_edit);
echo "</td>";
if (in_array($type, [StockOrder::TYPE_OUT, StockOrder::TYPE_IN], true)) {
    echo "<td>" . ($type === StockOrder::TYPE_OUT ? __('Utilisateur bénéficiaire', 'dsimanager') : __('Utilisateur restituant', 'dsimanager')) . "</td><td>";
    $userCell('users_id_beneficiary', (int) $f['users_id_beneficiary'], $can_edit);
    if ($type === StockOrder::TYPE_OUT && $can_edit) {
        echo "<div class='text-muted small'>" . __('Facultatif. Inscrit comme utilisateur des actifs sortis.', 'dsimanager') . "</div>";
    }
    echo "</td>";
} else {
    echo "<td colspan='2'></td>";
}
echo "</tr>";

if (in_array($type, [StockOrder::TYPE_OUT, StockOrder::TYPE_IN], true)) {
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Service (groupe)', 'dsimanager') . "</td><td>";
    if ($can_edit) {
        Group::dropdown(['name' => 'groups_id_beneficiary', 'value' => (int) $f['groups_id_beneficiary'], 'entity' => Session::getActiveEntity(), 'width' => '100%']);
    } else {
        echo (int) $f['groups_id_beneficiary'] ? htmlspecialchars(Dropdown::getDropdownName('glpi_groups', (int) $f['groups_id_beneficiary'])) : '-';
    }
    echo "</td>";
    if ($type === StockOrder::TYPE_IN) {
        echo "<td>" . __('Référence fournisseur', 'dsimanager') . "</td><td>";
        echo $can_edit
            ? Html::input('supplier_ref', ['value' => (string) $f['supplier_ref']])
            : htmlspecialchars((string) $f['supplier_ref']);
        echo "</td>";
    } else {
        echo "<td colspan='2'></td>";
    }
    echo "</tr>";
}

if ($type === StockOrder::TYPE_DISPOSAL) {
    $reasons = StockDisposalReason::getReasons();
    $rid = (int) ($f['plugin_dsimanager_stockdisposalreasons_id'] ?? 0);
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Motif de sortie', 'dsimanager') . " *</td><td>";
    if ($can_edit) {
        Dropdown::showFromArray('plugin_dsimanager_stockdisposalreasons_id', $reasons, ['value' => $rid, 'display_emptychoice' => true, 'width' => '100%']);
        if (Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE)) {
            echo "<div class='small'><a href='" . Plugin::getWebDir('dsimanager') . "/front/stockdisposalreason.php'>" . __('Gérer la liste des motifs', 'dsimanager') . "</a></div>";
        }
    } else {
        echo htmlspecialchars($reasons[$rid] ?? '-');
    }
    echo "</td>";
    echo "<td>" . __('Référence (bordereau, contrat...)', 'dsimanager') . "</td><td>";
    echo $can_edit ? Html::input('supplier_ref', ['value' => (string) $f['supplier_ref']]) : htmlspecialchars((string) $f['supplier_ref']);
    echo "</td></tr>";
    echo "<tr class='tab_bg_1'><td colspan='4' class='text-muted small'><i class='ti ti-info-circle'></i> "
       . __('À la validation, chaque actif passe au statut « Sorti du parc » et est placé dans la corbeille de GLPI (restaurable). Les articles en quantité sont retirés de l\'emplacement d\'origine. Une commande corrective restaure les actifs en stock.', 'dsimanager')
       . "</td></tr>";
}

echo "<tr class='tab_bg_1'>";
if (StockOrder::allowsFrom($type)) {
    echo "<td>" . ($type === StockOrder::TYPE_DISPOSAL ? __('Emplacement d\'origine (facultatif)', 'dsimanager') : __('Emplacement d\'origine', 'dsimanager')) . "</td><td>";
    if ($can_edit) {
        $locSelect('stocklocations_id_from', (int) $f['stocklocations_id_from']);
    } else {
        echo htmlspecialchars(Stock::getLocationName((int) $f['stocklocations_id_from']));
    }
    echo "</td>";
} else {
    echo "<td colspan='2'></td>";
}
if (StockOrder::needsTo($type)) {
    echo "<td>" . ($type === StockOrder::TYPE_ADJUST ? __('Emplacement inventorié', 'dsimanager') : __('Emplacement de destination', 'dsimanager')) . "</td><td>";
    if ($can_edit) {
        $locSelect('stocklocations_id_to', (int) $f['stocklocations_id_to']);
    } else {
        echo htmlspecialchars(Stock::getLocationName((int) $f['stocklocations_id_to']));
    }
    echo "</td>";
} else {
    echo "<td colspan='2'></td>";
}
echo "</tr>";

echo "<tr class='tab_bg_1'>";
echo "<td>" . __('Ticket lié', 'dsimanager') . "</td><td>";
$tid = (int) ($f['tickets_id'] ?? 0);
if ($can_edit) {
    echo "<div class='d-flex align-items-center gap-2'><span>#</span><input type='number' min='0' name='tickets_id' class='form-control' style='max-width:140px' value='" . ($tid ?: '') . "' placeholder='" . __('N° de ticket', 'dsimanager') . "'>";
    if ($tid > 0) {
        echo "<a href='" . Ticket::getFormURLWithID($tid) . "'><i class='ti ti-external-link'></i></a>";
    }
    echo "</div>";
} else {
    echo $tid > 0 ? "<a href='" . Ticket::getFormURLWithID($tid) . "'>#" . $tid . "</a>" : '-';
}
echo "</td>";
if ($type === StockOrder::TYPE_OUT) {
    echo "<td>" . __('Localisation des actifs après sortie', 'dsimanager') . "</td><td>";
    $loc_out = (int) ($f['locations_id'] ?? 0);
    if ($can_edit) {
        Location::dropdown(['name' => 'locations_id', 'value' => $loc_out, 'entity' => Session::getActiveEntity(), 'width' => '100%']);
        echo "<div class='text-muted small'>" . __('Vide : réglage par défaut du module.', 'dsimanager') . "</div>";
    } else {
        echo $loc_out > 0 ? htmlspecialchars(Dropdown::getDropdownName('glpi_locations', $loc_out, false, true, false)) : __('Réglage par défaut', 'dsimanager');
    }
    echo "</td>";
} else {
    echo "<td colspan='2'></td>";
}
echo "</tr>";

echo "<tr class='tab_bg_1'><td>" . __('Commentaire', 'dsimanager') . "</td><td colspan='3'>";
if ($can_edit) {
    echo "<textarea name='comment' class='form-control' rows='2'>" . htmlspecialchars((string) $f['comment']) . "</textarea>";
} else {
    echo nl2br(htmlspecialchars((string) $f['comment']));
}
echo "</td></tr>";

if (!$is_new) {
    echo "<tr class='tab_bg_2'><td colspan='4' class='text-muted small'>";
    echo sprintf(__('Créée par %1$s le %2$s', 'dsimanager'), htmlspecialchars(getUserName((int) $f['users_id_creator'])), Html::convDateTime($f['date_creation']));
    if ((int) $f['status'] === StockOrder::STATUS_VALIDATED) {
        echo " — " . sprintf(__('Validée par %1$s le %2$s', 'dsimanager'), htmlspecialchars(getUserName((int) $f['users_id_validator'])), Html::convDateTime($f['date_validation']));
    }
    echo "</td></tr>";
}

if ($can_edit) {
    echo "<tr class='tab_bg_2'><td colspan='4' class='center'>";
    echo "<input type='hidden' name='type' value='" . (int) $type . "'>";
    if ($is_new) {
        echo "<button type='submit' name='add_order' value='1' class='btn btn-primary'>" . __('Créer le brouillon', 'dsimanager') . "</button>";
        echo "<div class='text-muted small mt-1'>" . __('Le brouillon n\'a aucun effet sur le stock : ajoutez ensuite les lignes, puis validez.', 'dsimanager') . "</div>";
    } else {
        echo "<input type='hidden' name='id' value='" . (int) $order->getID() . "'>";
        echo "<button type='submit' name='update_order' value='1' class='btn btn-primary'>" . __('Enregistrer l\'en-tête', 'dsimanager') . "</button>";
    }
    echo "</td></tr>";
}
echo "</table>";
if ($can_edit) {
    Html::closeForm();
}
echo "</div></div>";

if ($is_new) {
    Html::footer();
    return;
}

// --- Lignes ------------------------------------------------------------

$lines = $order->getLines();
$order_id = (int) $order->getID();
$from = (int) $f['stocklocations_id_from'];
$to   = (int) $f['stocklocations_id_to'];

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __('Matériel', 'dsimanager') . " <span class='badge' style='background:#6c757d;color:#fff;'>" . count($lines) . "</span></h3>";

if (count($lines)) {
    echo "<table class='tab_cadre_fixehov'>";
    echo "<tr><th>" . __('Type', 'dsimanager') . "</th><th>" . __('Désignation', 'dsimanager') . "</th><th>" . __('N° de série / IMEI / ligne', 'dsimanager')
       . "</th><th>" . __('N° d\'inventaire', 'dsimanager') . "</th><th>"
       . ($type === StockOrder::TYPE_ADJUST ? __('Compté (avant)', 'dsimanager') : __('Quantité', 'dsimanager'))
       . "</th><th>" . __('Commentaire', 'dsimanager') . "</th>" . ($can_edit ? "<th></th>" : '') . "</tr>";
    foreach ($lines as $line) {
        $is_unit = (int) $line['kind'] === StockOrderLine::KIND_UNIT;
        echo "<tr class='tab_bg_1'>";
        if ($is_unit) {
            $itemtype = (string) $line['itemtype'];
            echo "<td>" . htmlspecialchars(Stock::getUnitTypeLabel($itemtype, (int) $line['items_id'])) . "</td>";
            echo "<td>" . Stock::getAssetLink($itemtype, (int) $line['items_id'], (string) $line['label']);
            if ((int) $line['items_id'] === 0) {
                echo " <span class='badge' style='background:#4299e1;color:#fff;'>" . __('nouvel actif (créé à la validation)', 'dsimanager') . "</span>";
            }
            // Ligne téléphonique associée (sortie) : choix parmi les lignes
            // téléphoniques de la même commande.
            if ($itemtype !== 'Line' && $type === StockOrder::TYPE_OUT) {
                $line_opts = [];
                foreach ($lines as $other) {
                    if ((int) $other['kind'] === StockOrderLine::KIND_UNIT && (string) $other['itemtype'] === 'Line' && (int) $other['items_id'] > 0) {
                        $line_opts[(int) $other['items_id']] = $other['serial'] . ($other['label'] !== $other['serial'] ? ' (' . $other['label'] . ')' : '');
                    }
                }
                $current = (int) $line['lines_id'];
                if ($can_edit && count($line_opts)) {
                    echo "<form method='post' action='$self' class='d-flex align-items-center gap-1 mt-1' style='margin:0'>";
                    echo "<input type='hidden' name='id' value='" . (int) $order->getID() . "'>";
                    echo "<span class='text-muted small'>" . __('Ligne :', 'dsimanager') . "</span>";
                    echo "<select name='link_lines_id' class='form-select form-select-sm' style='max-width:220px' onchange='this.form.submit()'>";
                    echo "<option value='0'>" . __('(aucune)', 'dsimanager') . "</option>";
                    foreach ($line_opts as $lid => $ltxt) {
                        echo "<option value='$lid'" . ($lid === $current ? ' selected' : '') . ">" . htmlspecialchars((string) $ltxt) . "</option>";
                    }
                    echo "</select>";
                    echo "<input type='hidden' name='set_line_link' value='" . (int) $line['id'] . "'>";
                    echo "<noscript><button type='submit' class='btn btn-sm btn-outline-secondary'>OK</button></noscript>";
                    Html::closeForm();
                } elseif ($current > 0 && isset($line_opts[$current])) {
                    echo "<div class='text-muted small'><i class='ti ti-sim'></i> " . htmlspecialchars((string) $line_opts[$current]) . "</div>";
                }
            }
            echo "</td>";
            echo "<td>" . htmlspecialchars((string) $line['serial']) . "</td>";
            echo "<td>" . htmlspecialchars((string) $line['otherserial']) . "</td>";
            echo "<td>1</td>";
        } else {
            $is_kit = Stock::isBundle((int) $line['plugin_dsimanager_stockarticles_id']);
            echo "<td>" . ($is_kit ? __('Kit', 'dsimanager') : __('Article', 'dsimanager')) . "</td>";
            echo "<td>" . htmlspecialchars((string) $line['label']);
            if ($is_kit) {
                $snap = json_decode((string) ($line['components'] ?? ''), true);
                echo "<div class='text-muted small'>" . htmlspecialchars(Stock::getBundleSummary((int) $line['plugin_dsimanager_stockarticles_id'], is_array($snap) && count($snap) ? $snap : null)) . "</div>";
            }
            echo "</td><td></td><td></td>";
            if ($type === StockOrder::TYPE_ADJUST) {
                $before = $line['expected_quantity'] !== null ? (int) $line['expected_quantity'] : Stock::getLevel((int) $line['plugin_dsimanager_stockarticles_id'], $to);
                $delta  = (int) $line['quantity'] - $before;
                $sign   = $delta > 0 ? '+' : '';
                echo "<td>" . (int) $line['quantity'] . " <span class='text-muted'>(" . $before . ", " . $sign . $delta . ")</span></td>";
            } else {
                echo "<td>" . (int) $line['quantity'] . "</td>";
            }
        }
        echo "<td>" . htmlspecialchars((string) $line['comment']) . "</td>";
        if ($can_edit) {
            // Un formulaire par cellule (jamais réparti sur plusieurs <td> : piège n°14).
            echo "<td><form method='post' action='$self' style='margin:0'>";
            echo "<input type='hidden' name='id' value='$order_id'>";
            echo "<button type='submit' name='delete_line' value='" . (int) $line['id'] . "' class='btn btn-sm btn-outline-danger' title=\"" . __('Retirer', 'dsimanager') . "\"><i class='ti ti-trash'></i></button>";
            Html::closeForm();
            echo "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='text-muted'>" . __('Aucune ligne pour l\'instant.', 'dsimanager') . "</p>";
}

// --- Ajout de lignes (brouillon uniquement) ----------------------------

if ($can_edit) {
    echo "<div class='row mt-3'>";

    // Actifs unitaires
    if ($type !== StockOrder::TYPE_ADJUST) {
        echo "<div class='col-lg-6 mb-3'><div class='border rounded p-3 h-100'>";
        echo "<h4><i class='ti ti-device-mobile'></i> " . __('Ajouter un actif unitaire', 'dsimanager') . "</h4>";
        echo "<form method='post' action='$self'>";
        echo "<input type='hidden' name='id' value='$order_id'>";
        echo "<input type='hidden' name='kind' value='" . StockOrderLine::KIND_UNIT . "'>";
        if ($type === StockOrder::TYPE_IN) {
            echo "<div class='mb-2'><label class='form-label'>" . __('Type d\'actif', 'dsimanager') . "</label>";
            Dropdown::showFromArray('itemtype', Stock::getUnitItemtypes(), ['value' => 'Phone', 'width' => '100%']);
            echo "</div>";
            echo "<div class='mb-2'><label class='form-label'>" . __('N° de série / IMEI (ou numéro de ligne)', 'dsimanager') . " *</label>"
               . Html::input('serial', ['required' => true, 'autocomplete' => 'off']) . "</div>";
            echo "<div class='mb-2'><label class='form-label'>" . __('Désignation', 'dsimanager') . "</label>"
               . Html::input('label', ['placeholder' => __('Facultatif si l\'actif existe déjà dans l\'inventaire', 'dsimanager')]) . "</div>";
            echo "<div class='mb-2'><label class='form-label'>" . __('N° d\'inventaire', 'dsimanager') . "</label>" . Html::input('otherserial') . "</div>";
            echo "<p class='text-muted small'>" . __('Si un actif porte déjà ce numéro dans l\'inventaire, il est réutilisé (jamais recréé). Sinon il est créé à la validation.', 'dsimanager') . "</p>";
        } elseif ($type === StockOrder::TYPE_DISPOSAL && $from <= 0) {
            echo "<div class='mb-2'><label class='form-label'>" . __('N° de série / IMEI / numéro de ligne ou n° d\'inventaire', 'dsimanager') . " *</label>"
               . Html::input('serial', ['required' => true, 'autocomplete' => 'off']) . "</div>";
            echo "<p class='text-muted small'>" . __('Tout actif du parc (hors corbeille), en stock ou non. Pour choisir dans une liste d\'actifs en stock, renseignez l\'emplacement d\'origine.', 'dsimanager') . "</p>";
        } else {
            $in_order = [];
            foreach ($lines as $line) {
                if ((int) $line['kind'] === StockOrderLine::KIND_UNIT) {
                    $in_order[$line['itemtype'] . '|' . (int) $line['items_id']] = true;
                }
            }
            $opts = [];
            foreach (Stock::getUnitsInStock($from) as $u) {
                $key = $u['itemtype'] . '|' . $u['items_id'];
                if (isset($in_order[$key])) {
                    continue;
                }
                $opts[$u['type_label']][$key] = trim($u['name'] . ' — ' . $u['identifier'] . ($u['otherserial'] !== '' ? ' — ' . $u['otherserial'] : ''));
            }
            echo "<div class='mb-2'><label class='form-label'>" . sprintf(__('Actif en stock (%s)', 'dsimanager'), htmlspecialchars(Stock::getLocationName($from))) . "</label>";
            if (count($opts)) {
                // Liste groupée par type ; la recherche-en-tapant du widget
                // GLPI permet de retrouver un appareil par son numéro.
                Dropdown::showFromArray('unit_ref', $opts, ['display_emptychoice' => true, 'width' => '100%']);
            } else {
                echo "<div class='text-muted'>" . __('Aucun actif unitaire disponible à cet emplacement.', 'dsimanager') . "</div>";
            }
            echo "</div>";
            if ($type === StockOrder::TYPE_DISPOSAL) {
                echo "<div class='mb-2'><label class='form-label'>" . __('… ou un actif hors stock : n° de série / IMEI / n° d\'inventaire', 'dsimanager') . "</label>"
                   . Html::input('serial', ['autocomplete' => 'off']) . "</div>";
            }
        }
        echo "<div class='mb-2'><label class='form-label'>" . __('Commentaire de ligne', 'dsimanager') . "</label>" . Html::input('line_comment') . "</div>";
        echo "<button type='submit' name='add_line' value='1' class='btn btn-success btn-sm'><i class='ti ti-plus'></i> " . __('Ajouter', 'dsimanager') . "</button>";
        Html::closeForm();
        echo "</div></div>";
    }

    // Articles quantitatifs
    echo "<div class='col-lg-6 mb-3'><div class='border rounded p-3 h-100'>";
    echo "<h4><i class='ti ti-box'></i> " . __('Ajouter un article (quantité)', 'dsimanager') . "</h4>";
    $articles = Stock::getArticles(true);
    if (!count($articles)) {
        echo "<p class='text-muted'>" . __('Aucun article dans le catalogue.', 'dsimanager');
        if (Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, CREATE)) {
            echo " <a href='" . Plugin::getWebDir('dsimanager') . "/front/stockarticle.form.php'>" . __('En créer un', 'dsimanager') . "</a>";
        }
        echo "</p>";
    } else {
        $ref_loc = StockOrder::allowsFrom($type) ? $from : $to;
        $levels = Stock::getLevelsMap();
        $opts = [];
        foreach ($articles as $aid => $a) {
            if ($a['is_bundle'] && $type === StockOrder::TYPE_ADJUST) {
                continue; // un kit ne s'inventorie pas
            }
            $qty = $a['is_bundle'] ? Stock::getBundleAvailability($aid, $ref_loc, $levels) : ($levels[$aid][$ref_loc] ?? 0);
            $group = $a['category'] !== '' ? $a['category'] : __('Sans catégorie', 'dsimanager');
            $suffix = '';
            if (StockOrder::allowsFrom($type)) {
                $suffix = ' (' . sprintf(__('dispo : %d', 'dsimanager'), $qty) . ')';
            } elseif ($type === StockOrder::TYPE_ADJUST) {
                $suffix = ' (' . sprintf(__('stock actuel : %d', 'dsimanager'), $qty) . ')';
            }
            $opts[$group][$aid] = $a['name'] . $suffix;
        }
        echo "<form method='post' action='$self'>";
        echo "<input type='hidden' name='id' value='$order_id'>";
        echo "<input type='hidden' name='kind' value='" . StockOrderLine::KIND_QTY . "'>";
        echo "<div class='mb-2'><label class='form-label'>" . __('Article', 'dsimanager') . "</label>";
        // Groupe "Kits" en dernier
        $kits_label = __('Kits', 'dsimanager');
        if (isset($opts[$kits_label])) {
            $kits_opts = $opts[$kits_label];
            unset($opts[$kits_label]);
            $opts[$kits_label] = $kits_opts;
        }
        Dropdown::showFromArray('articles_id', $opts, ['display_emptychoice' => true, 'width' => '100%']);
        echo "</div>";
        if ($type === StockOrder::TYPE_DISPOSAL && $from <= 0) {
            echo "<p class='text-warning small'>" . __('Renseignez l\'emplacement d\'origine pour supprimer des articles en quantité.', 'dsimanager') . "</p>";
        }
        $qlabel = $type === StockOrder::TYPE_ADJUST ? __('Quantité comptée', 'dsimanager') : __('Quantité', 'dsimanager');
        $qmin   = $type === StockOrder::TYPE_ADJUST ? 0 : 1;
        echo "<div class='mb-2'><label class='form-label'>" . $qlabel . "</label>"
           . "<input type='number' name='quantity' class='form-control' min='$qmin' value='" . ($type === StockOrder::TYPE_ADJUST ? 0 : 1) . "' required></div>";
        echo "<div class='mb-2'><label class='form-label'>" . __('Commentaire de ligne', 'dsimanager') . "</label>" . Html::input('line_comment') . "</div>";
        echo "<button type='submit' name='add_line' value='1' class='btn btn-success btn-sm'><i class='ti ti-plus'></i> " . __('Ajouter', 'dsimanager') . "</button>";
        Html::closeForm();
    }
    echo "</div></div>";

    echo "</div>";
}
echo "</div></div>";

// --- Profil de dotation (brouillon) -------------------------------------
$kits = StockKit::getActiveKits();
if ($can_edit && count($kits) && in_array($type, [StockOrder::TYPE_OUT, StockOrder::TYPE_IN], true)) {
    echo "<div class='card mb-3'><div class='card-body'>";
    echo "<form method='post' action='$self' class='d-flex flex-wrap align-items-center gap-2' style='margin:0'>";
    echo "<input type='hidden' name='id' value='$order_id'>";
    echo "<span><i class='ti ti-briefcase'></i> " . __('Appliquer un profil de dotation', 'dsimanager') . "</span>";
    echo "<select name='kits_id' class='form-select' style='max-width:320px'>";
    foreach ($kits as $kid => $kname) {
        $sel = (int) ($f['plugin_dsimanager_stockkits_id'] ?? 0) === $kid ? ' selected' : '';
        echo "<option value='$kid'$sel title=\"" . htmlspecialchars(StockKit::getSummary($kid)) . "\">" . htmlspecialchars($kname) . "</option>";
    }
    echo "</select>";
    echo "<button type='submit' name='apply_kit' value='1' class='btn btn-outline-primary'>" . __('Appliquer', 'dsimanager') . "</button>";
    echo "<span class='text-muted small'>" . __('Ajoute les articles du profil (sans doublon) et affiche les actifs unitaires à prévoir.', 'dsimanager') . "</span>";
    Html::closeForm();
    echo "</div></div>";
}

// --- Actions -----------------------------------------------------------

echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap gap-2 align-items-center'>";
if ($can_valid) {
    echo "<form method='post' action='$self' style='margin:0' onsubmit=\"return confirm('" . htmlspecialchars(addslashes(__('Valider cette commande ? Le stock et les actifs seront mis à jour, et la commande ne sera plus modifiable.', 'dsimanager')), ENT_QUOTES) . "');\">";
    echo "<input type='hidden' name='id' value='$order_id'>";
    echo "<button type='submit' name='validate_order' value='1' class='btn btn-success'><i class='ti ti-check'></i> " . __('Valider la commande', 'dsimanager') . "</button>";
    Html::closeForm();
} elseif ($is_draft) {
    echo "<span class='text-muted'>" . __('Brouillon en attente de validation par une personne habilitée.', 'dsimanager') . "</span>";
}
if ($can_edit) {
    echo "<form method='post' action='$self' style='margin:0' onsubmit=\"return confirm('" . htmlspecialchars(addslashes(__('Annuler ce brouillon ? Il restera visible dans l\'historique comme « Annulée ».', 'dsimanager')), ENT_QUOTES) . "');\">";
    echo "<input type='hidden' name='id' value='$order_id'>";
    echo "<button type='submit' name='cancel_order' value='1' class='btn btn-outline-danger'><i class='ti ti-x'></i> " . __('Annuler le brouillon', 'dsimanager') . "</button>";
    Html::closeForm();
}
if ((int) $f['status'] === StockOrder::STATUS_VALIDATED && $type !== StockOrder::TYPE_ADJUST && Stock::canAny(CREATE)) {
    echo "<form method='post' action='$self' style='margin:0' onsubmit=\"return confirm('" . htmlspecialchars(addslashes(__('Créer une commande corrective (mouvement inverse, en brouillon) ?', 'dsimanager')), ENT_QUOTES) . "');\">";
    echo "<input type='hidden' name='id' value='$order_id'>";
    echo "<button type='submit' name='correct_order' value='1' class='btn btn-outline-warning'><i class='ti ti-arrow-back-up'></i> " . __('Créer une commande corrective', 'dsimanager') . "</button>";
    Html::closeForm();
}
if (StockDocument::supportsOrder($order)) {
    $doc_label = $type === StockOrder::TYPE_OUT ? __('Bon de remise (PDF)', 'dsimanager') : __('Bon de reprise (PDF)', 'dsimanager');
    echo "<a class='btn btn-outline-primary' target='_blank' href='" . Plugin::getWebDir('dsimanager') . "/front/stockdoc.php?id=$order_id'><i class='ti ti-file-type-pdf'></i> " . $doc_label . "</a>";
    if ((int) $f['tickets_id'] > 0 && (int) $f['status'] === StockOrder::STATUS_VALIDATED && StockOrder::canDoOnType($type, UPDATE)) {
        $again = (int) $f['documents_id'] > 0;
        echo "<form method='post' action='$self' style='margin:0'>";
        echo "<input type='hidden' name='id' value='$order_id'>";
        echo "<button type='submit' name='attach_doc' value='1' class='btn btn-outline-secondary'><i class='ti ti-paperclip'></i> "
           . ($again ? __('Joindre à nouveau au ticket', 'dsimanager') : __('Joindre au ticket', 'dsimanager')) . "</button>";
        Html::closeForm();
    }
}
if ((int) $f['tickets_id'] > 0) {
    echo "<a class='btn btn-outline-secondary ms-auto' href='" . Ticket::getFormURLWithID((int) $f['tickets_id']) . "'>" . sprintf(__('← Ticket #%d', 'dsimanager'), (int) $f['tickets_id']) . "</a>";
    echo "<a class='btn btn-outline-secondary' href='" . Plugin::getWebDir('dsimanager') . "/front/stockorder.php'>" . __('Historique', 'dsimanager') . "</a>";
} else {
    echo "<a class='btn btn-outline-secondary ms-auto' href='" . Plugin::getWebDir('dsimanager') . "/front/stockorder.php'>" . __('← Historique', 'dsimanager') . "</a>";
}
echo "</div></div>";

Html::footer();
