<?php

namespace GlpiPlugin\Dsimanager;

use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationInterface;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Serializer\DynamicExportDataField;
use Glpi\Form\Form;
use Throwable;
use Toolbox;

/**
 * Module Stock (Lot 4) - Destination de formulaire natif GLPI 11
 * "Commande de stock (brouillon)".
 *
 * Ajoutée dans l'onglet "Destinations" d'un formulaire (à côté de la
 * destination Ticket), elle crée à chaque soumission une commande de stock
 * EN BROUILLON, préremplie avec les réponses : bénéficiaire, service,
 * localisation, profil de dotation (articles ajoutés automatiquement).
 * Le brouillon est lié au ticket créé par le même formulaire ; il ne
 * modifie pas le stock tant qu'un technicien ne l'a pas validé.
 *
 * Toute erreur est journalisée (files/_log/dsimanager.log) sans jamais
 * bloquer la soumission du formulaire par l'utilisateur.
 */
class FormDestinationStockOrder implements FormDestinationInterface
{
    // ------------------------------------------------------------------
    // Identité dans l'éditeur de formulaires
    // ------------------------------------------------------------------

    public function getLabel(): string
    {
        return __('Commande de stock (brouillon)', 'dsimanager');
    }

    public function getIcon(): string
    {
        return 'ti ti-packages';
    }

    public function getWeight(): int
    {
        return 50;
    }

    public function useDefaultConfigLayout(): bool
    {
        // Boutons standards (Enregistrer / Supprimer / Dupliquer / Conditions).
        return true;
    }

    public static function getDefaultConfig(): array
    {
        return [
            'order_type'         => StockOrder::TYPE_OUT,
            'stocklocation'      => (int) (array_key_first(Stock::getLocations(true)) ?? 0),
            'beneficiary'        => 'ticket',
            'group'              => 'ticket',
            'location'           => 'ticket',
            'kit'                => 'none',
            'answers_in_comment' => 1,
        ];
    }

    // ------------------------------------------------------------------
    // Configuration (rendue dans l'éditeur de formulaire)
    // ------------------------------------------------------------------

    public function renderConfigForm(Form $form, FormDestination $destination, array $config): string
    {
        $c = array_merge(self::getDefaultConfig(), $config);
        $questions = [];
        foreach ($form->getQuestions() as $qid => $q) {
            $type = (string) ($q->fields['type'] ?? '');
            $short = substr($type, (int) strrpos($type, '\\') + 1);
            $questions['q:' . (int) $qid] = sprintf(__('Question « %1$s » (%2$s)', 'dsimanager'), $q->fields['name'], str_replace('QuestionType', '', $short));
        }

        $select = static function (string $name, array $options, $value): string {
            $html = "<select name='config[" . htmlspecialchars($name) . "]' class='form-select'>";
            foreach ($options as $k => $v) {
                if (is_array($v)) {
                    $html .= "<optgroup label=\"" . htmlspecialchars((string) $k) . "\">";
                    foreach ($v as $k2 => $v2) {
                        $html .= "<option value=\"" . htmlspecialchars((string) $k2) . "\"" . ((string) $k2 === (string) $value ? ' selected' : '') . ">" . htmlspecialchars((string) $v2) . "</option>";
                    }
                    $html .= "</optgroup>";
                    continue;
                }
                $html .= "<option value=\"" . htmlspecialchars((string) $k) . "\"" . ((string) $k === (string) $value ? ' selected' : '') . ">" . htmlspecialchars((string) $v) . "</option>";
            }
            return $html . "</select>";
        };
        $qgroup = count($questions) ? [__('Réponse à une question', 'dsimanager') => $questions] : [];
        $row = static function (string $label, string $field, string $help = ''): string {
            return "<div class='mb-3'><label class='form-label fw-bold'>" . htmlspecialchars($label) . "</label>" . $field
                 . ($help !== '' ? "<div class='form-hint text-muted small'>" . htmlspecialchars($help) . "</div>" : '') . "</div>";
        };

        $kits = [];
        foreach (StockKit::getActiveKits() as $kid => $kname) {
            $kits['k:' . $kid] = $kname;
        }

        $html = "<div class='mt-3'>";
        $html .= "<div class='alert alert-info'><i class='ti ti-info-circle'></i> "
               . htmlspecialchars(__('À chaque réponse, une commande de stock est créée en BROUILLON et liée au ticket de ce formulaire. Le stock n\'est modifié qu\'après validation par un technicien (Stock > Historique ou onglet Stock du ticket).', 'dsimanager'))
               . "</div>";
        $html .= "<div class='row'><div class='col-md-6'>";
        $html .= $row(__('Type de commande', 'dsimanager'), $select('order_type', [
            StockOrder::TYPE_OUT => __('Sortie (remise de matériel)', 'dsimanager'),
            StockOrder::TYPE_IN  => __('Entrée (reprise de matériel)', 'dsimanager'),
        ], $c['order_type']));
        $html .= "</div><div class='col-md-6'>";
        $html .= $row(__('Emplacement de stock', 'dsimanager'), $select('stocklocation', Stock::getLocations(true), $c['stocklocation']), __('Origine pour une sortie, destination pour une entrée.', 'dsimanager'));
        $html .= "</div></div>";

        $html .= $row(__('Bénéficiaire', 'dsimanager'), $select('beneficiary', [
            'ticket' => __('Demandeur du ticket créé (par défaut)', 'dsimanager'),
            'author' => __('Personne qui remplit le formulaire', 'dsimanager'),
            'none'   => __('Aucun (choisi par le technicien)', 'dsimanager'),
        ] + $qgroup, $c['beneficiary']), __('Question de type Demandeur, Observateur ou Élément (Utilisateur).', 'dsimanager'));
        $html .= $row(__('Service (groupe)', 'dsimanager'), $select('group', [
            'ticket'      => __('Groupe demandeur du ticket, sinon groupe du bénéficiaire', 'dsimanager'),
            'beneficiary' => __('Groupe par défaut du bénéficiaire', 'dsimanager'),
            'none'        => __('Aucun', 'dsimanager'),
        ] + $qgroup, $c['group']), __('Question de type Élément (Groupe), ou liste dont le libellé est le nom du groupe.', 'dsimanager'));
        $html .= $row(__('Localisation des actifs après sortie', 'dsimanager'), $select('location', [
                'ticket' => __('Localisation du ticket', 'dsimanager'),
                'none'   => __('Réglage par défaut du module', 'dsimanager'),
            ] + $qgroup, $c['location']), __('Sorties uniquement. Question de type Élément (Lieu), ou liste dont le libellé est le nom du lieu.', 'dsimanager'));
        $html .= $row(__('Profil de dotation', 'dsimanager'), $select('kit', ['none' => __('Aucun', 'dsimanager')]
            + (count($kits) ? [__('Profil fixe', 'dsimanager') => $kits] : []) + $qgroup, $c['kit']),
            __('Avec une question : le libellé de la réponse doit être exactement le nom d\'un profil (Stock > Configuration > Profils de dotation). Les articles du profil sont ajoutés au brouillon ; les actifs unitaires restent à choisir par le technicien.', 'dsimanager'));
        $html .= "<div class='mb-2'><label class='form-check'><input type='hidden' name='config[answers_in_comment]' value='0'>"
               . "<input class='form-check-input' type='checkbox' name='config[answers_in_comment]' value='1'" . (!empty($c['answers_in_comment']) ? ' checked' : '') . ">"
               . "<span class='form-check-label'>" . htmlspecialchars(__('Recopier les réponses du formulaire dans le commentaire de la commande', 'dsimanager')) . "</span></label></div>";
        $html .= "</div>";
        return $html;
    }

    public function exportDynamicConfig(array $config): DynamicExportDataField
    {
        return new DynamicExportDataField($config, []);
    }

    public static function prepareDynamicConfigDataForImport(array $config, DatabaseMapper $mapper): array
    {
        return $config;
    }

    // ------------------------------------------------------------------
    // Création à la soumission
    // ------------------------------------------------------------------

    public function createDestinationItems(Form $form, AnswersSet $answers_set, array $config): array
    {
        try {
            $c = array_merge(self::getDefaultConfig(), $config);
            $type = (int) $c['order_type'] === StockOrder::TYPE_IN ? StockOrder::TYPE_IN : StockOrder::TYPE_OUT;
            $loc  = (int) $c['stocklocation'];
            if (!isset(Stock::getLocations(true)[$loc])) {
                $loc = (int) (array_key_first(Stock::getLocations(true)) ?? 0);
            }

            $input = [
                'type'                 => $type,
                'date_order'           => date('Y-m-d'),
                'entities_id'          => (int) ($form->fields['entities_id'] ?? 0),
                'users_id_requester'   => (int) $answers_set->fields['users_id'],
                'stocklocations_id_from' => $type === StockOrder::TYPE_OUT ? $loc : 0,
                'stocklocations_id_to'   => $type === StockOrder::TYPE_IN ? $loc : 0,
                'comment'              => $this->buildComment($form, $answers_set, !empty($c['answers_in_comment'])),
            ];

            $beneficiary = $this->resolveUser((string) $c['beneficiary'], $answers_set);
            if ($beneficiary > 0) {
                $input['users_id_beneficiary'] = $beneficiary;
            }
            $group = $this->resolveGroup((string) $c['group'], $answers_set, $beneficiary);
            if ($group > 0) {
                $input['groups_id_beneficiary'] = $group;
            }
            if ($type === StockOrder::TYPE_OUT) {
                $location = $this->resolveLocation((string) $c['location'], $answers_set);
                if ($location > 0) {
                    $input['locations_id'] = $location;
                }
            }

            $order = new StockOrder();
            if (!$order->add($input)) {
                throw new \RuntimeException('StockOrder::add() a échoué');
            }
            global $DB;
            $DB->update(StockOrder::getTable(), ['forms_answerssets_id' => (int) $answers_set->getID()], ['id' => $order->getID()]);
            $order->getFromDB($order->getID());

            $kit = $this->resolveKit((string) $c['kit'], $answers_set);
            if ($kit > 0) {
                $issues = StockKit::applyToOrder($order, $kit);
                if (count($issues)) {
                    $this->appendComment($order, __('Profil appliqué avec réserves :', 'dsimanager') . ' ' . implode(' / ', $issues));
                }
            } elseif (str_starts_with((string) $c['kit'], 'q:')) {
                $text = $this->answerText($answers_set, (string) $c['kit']);
                if ($text !== '') {
                    $this->appendComment($order, sprintf(__('Aucun profil de dotation nommé « %s » : matériel à saisir manuellement.', 'dsimanager'), $text));
                }
            }
            return [$order];
        } catch (Throwable $e) {
            Toolbox::logInFile('dsimanager', sprintf("Formulaire #%d : création du brouillon de stock impossible : %s\n", (int) $form->getID(), $e->getMessage()));
            return [];
        }
    }

    public function postCreateDestinationItems(Form $form, AnswersSet $answers_set, FormDestination $destination, array $created_items): void
    {
        try {
            $mine = $created_items[$destination->getID()] ?? [];
            $order = $mine[0] ?? null;
            if (!$order instanceof StockOrder || $order->isNewItem()) {
                return;
            }
            // Ticket créé par une autre destination du même formulaire.
            $ticket = null;
            foreach ($created_items as $dest_id => $items) {
                foreach ($items as $item) {
                    if ($item instanceof \Ticket && !$item->isNewItem()) {
                        $ticket = $item;
                        break 2;
                    }
                }
            }
            if ($ticket === null) {
                return;
            }
            $c = array_merge(self::getDefaultConfig(), $destination->getConfig());
            $prefill = self::getTicketValues((int) $ticket->getID());
            $update = ['tickets_id' => (int) $ticket->getID(), 'entities_id' => (int) $ticket->fields['entities_id']];
            if ($c['beneficiary'] === 'ticket' && (int) $order->fields['users_id_beneficiary'] === 0 && $prefill['users_id'] > 0) {
                $update['users_id_beneficiary'] = $prefill['users_id'];
            }
            $beneficiary = (int) ($update['users_id_beneficiary'] ?? $order->fields['users_id_beneficiary']);
            if ((int) $order->fields['groups_id_beneficiary'] === 0) {
                if ($c['group'] === 'ticket') {
                    $g = $prefill['groups_id'] > 0 ? $prefill['groups_id'] : self::getUserDefaultGroup($beneficiary);
                    if ($g > 0) {
                        $update['groups_id_beneficiary'] = $g;
                    }
                } elseif ($c['group'] === 'beneficiary' && $beneficiary > 0) {
                    $g = self::getUserDefaultGroup($beneficiary);
                    if ($g > 0) {
                        $update['groups_id_beneficiary'] = $g;
                    }
                }
            }
            if ((int) $order->fields['type'] === StockOrder::TYPE_OUT && $c['location'] === 'ticket' && (int) $order->fields['locations_id'] === 0 && $prefill['locations_id'] > 0) {
                $update['locations_id'] = $prefill['locations_id'];
            }
            global $DB;
            // Écriture directe : la commande reste un brouillon, et l'auteur
            // du formulaire n'a en général aucun droit sur le module.
            $DB->update(StockOrder::getTable(), $update, ['id' => $order->getID()]);
        } catch (Throwable $e) {
            Toolbox::logInFile('dsimanager', sprintf("Formulaire #%d : liaison du brouillon au ticket impossible : %s\n", (int) $form->getID(), $e->getMessage()));
        }
    }

    // ------------------------------------------------------------------
    // Lecture des réponses
    // ------------------------------------------------------------------

    private function getAnswer(AnswersSet $set, string $source): ?\Glpi\Form\Answer
    {
        if (!str_starts_with($source, 'q:')) {
            return null;
        }
        return $set->getAnswerByQuestionId((int) substr($source, 2));
    }

    /** Éléments GLPI contenus dans une réponse : [[itemtype, items_id], ...]. */
    private function answerItems(?\Glpi\Form\Answer $answer): array
    {
        if ($answer === null) {
            return [];
        }
        $raw = $answer->getRawAnswer();
        if (!is_array($raw)) {
            return [];
        }
        if (isset($raw['itemtype'], $raw['items_id'])) {
            return [[(string) $raw['itemtype'], (int) $raw['items_id']]];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_array($v) && isset($v['itemtype'], $v['items_id'])) {
                $out[] = [(string) $v['itemtype'], (int) $v['items_id']];
            }
        }
        return $out;
    }

    /** Texte lisible d'une réponse (libellé des options choisies, etc.). */
    private function answerText(AnswersSet $set, string $source): string
    {
        $answer = $this->getAnswer($set, $source);
        if ($answer === null) {
            return '';
        }
        try {
            $formatted = (string) $answer->getFormattedAnswer();
        } catch (Throwable $e) {
            $raw = $answer->getRawAnswer();
            $formatted = is_scalar($raw) ? (string) $raw : '';
        }
        return trim(html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function firstOfType(array $items, string $itemtype): int
    {
        foreach ($items as [$t, $id]) {
            if (strcasecmp($t, $itemtype) === 0 && $id > 0) {
                return $id;
            }
        }
        return 0;
    }

    private function resolveUser(string $source, AnswersSet $set): int
    {
        if ($source === 'author') {
            return (int) $set->fields['users_id'];
        }
        return $this->firstOfType($this->answerItems($this->getAnswer($set, $source)), 'User');
    }

    private function resolveGroup(string $source, AnswersSet $set, int $beneficiary): int
    {
        if ($source === 'beneficiary') {
            return self::getUserDefaultGroup($beneficiary);
        }
        $id = $this->firstOfType($this->answerItems($this->getAnswer($set, $source)), 'Group');
        return $id > 0 ? $id : self::findDropdownByText('glpi_groups', $this->answerText($set, $source));
    }

    private function resolveLocation(string $source, AnswersSet $set): int
    {
        $id = $this->firstOfType($this->answerItems($this->getAnswer($set, $source)), 'Location');
        return $id > 0 ? $id : self::findDropdownByText('glpi_locations', $this->answerText($set, $source));
    }

    private function resolveKit(string $source, AnswersSet $set): int
    {
        if (str_starts_with($source, 'k:')) {
            $id = (int) substr($source, 2);
            return isset(StockKit::getActiveKits()[$id]) ? $id : 0;
        }
        $id = $this->firstOfType($this->answerItems($this->getAnswer($set, $source)), StockKit::class);
        return $id > 0 ? $id : StockKit::findByName($this->answerText($set, $source));
    }

    private static function findDropdownByText(string $table, string $text): int
    {
        global $DB;
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 255) {
            return 0;
        }
        foreach (['completename', 'name'] as $field) {
            $row = $DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => [$field => $text], 'LIMIT' => 1])->current();
            if ($row) {
                return (int) $row['id'];
            }
        }
        return 0;
    }

    private static function getUserDefaultGroup(int $users_id): int
    {
        if ($users_id <= 0) {
            return 0;
        }
        $user = new \User();
        return $user->getFromDB($users_id) ? (int) ($user->fields['groups_id'] ?? 0) : 0;
    }

    /**
     * Demandeur, groupe demandeur et localisation d'un ticket, lus sans
     * contrôle de droits (traitement automatique côté serveur).
     */
    public static function getTicketValues(int $tickets_id): array
    {
        global $DB;
        $out = ['users_id' => 0, 'groups_id' => 0, 'locations_id' => 0];
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return $out;
        }
        $out['locations_id'] = (int) $ticket->fields['locations_id'];
        $u = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tickets_id, 'type' => \CommonITILActor::REQUESTER, 'users_id' => ['>', 0]], 'ORDER' => 'id ASC', 'LIMIT' => 1])->current();
        $out['users_id'] = $u ? (int) $u['users_id'] : 0;
        $g = $DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $tickets_id, 'type' => \CommonITILActor::REQUESTER], 'ORDER' => 'id ASC', 'LIMIT' => 1])->current();
        $out['groups_id'] = $g ? (int) $g['groups_id'] : 0;
        return $out;
    }

    private function buildComment(Form $form, AnswersSet $set, bool $with_answers): string
    {
        $lines = [sprintf(__('Brouillon créé automatiquement par le formulaire « %1$s » (%2$s).', 'dsimanager'), $form->fields['name'], $set->fields['name'] ?? '')];
        if ($with_answers) {
            foreach ($set->getAnswers() as $answer) {
                $text = '';
                try {
                    $text = trim(html_entity_decode(strip_tags((string) $answer->getFormattedAnswer()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                } catch (Throwable $e) {
                    $raw = $answer->getRawAnswer();
                    $text = is_scalar($raw) ? (string) $raw : '';
                }
                if ($text !== '') {
                    $lines[] = '- ' . $answer->getQuestionLabel() . ' : ' . mb_substr($text, 0, 300);
                }
            }
        }
        return implode("\n", $lines);
    }

    private function appendComment(StockOrder $order, string $text): void
    {
        global $DB;
        $comment = trim((string) $order->fields['comment'] . "\n" . $text);
        $DB->update(StockOrder::getTable(), ['comment' => $comment], ['id' => $order->getID()]);
        $order->fields['comment'] = $comment;
    }
}
