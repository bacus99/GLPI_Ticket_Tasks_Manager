<?php

/**
 * Tasks Manager - Workflow AJAX endpoint
 *
 * Response shape (per GLPI-Shared/rules/glpi-plugin-api.md):
 *   { ok: bool, error?: string, data?: object }
 *
 * HTTP status codes:
 *   200 — success
 *   400 — missing / invalid input
 *   403 — not allowed
 *   404 — referenced item not found
 *   500 — unexpected server error
 *
 * Actions (POST):
 *   add_step                  – add a task-template step to a workflow
 *   remove_step               – remove a step
 *   reorder_steps             – save new step order (array of step IDs)
 *   update_template_comment   – update the linked task template's comment
 *   save_step_rules           – persist the JSON conditional-routing rules for a step
 *   add_followup_step         – add a follow-up-only step (no task template)
 *   save_step_sla             – persist per-step SLA + escalation config
 *   update_step_followup_template – set/clear the step's ITILFollowupTemplate
 *   list_form_questions       – return [{id,label}] of all defined form questions
 *   apply_to_ticket           – assign a workflow to a ticket and create its first task
 *   remove_from_ticket        – cancel the active workflow on a ticket
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../hook.php');

use GlpiPlugin\Tasksmanager\Workflow;

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

/** Helper: respond and exit. */
function tm_respond(bool $ok, int $http_code = 200, ?string $error = null, array $data = []): void
{
    http_response_code($http_code);
    $out = ['ok' => $ok];
    if ($error !== null) {
        $out['error'] = $error;
    }
    if (!empty($data)) {
        $out['data'] = $data;
    }
    echo json_encode($out);
    exit;
}

$action = $_POST['action'] ?? '';

global $DB;

switch ($action) {

    // ── Add a template step ───────────────────────────────────────────────
    case 'add_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $workflows_id     = (int)($_POST['workflows_id']     ?? 0);
        $tasktemplates_id = (int)($_POST['tasktemplates_id'] ?? 0);

        if (!$workflows_id || !$tasktemplates_id) {
            tm_respond(false, 400, 'Missing parameters');
        }

        $last = $DB->request([
            'SELECT' => ['step_order'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE'  => ['workflows_id' => $workflows_id],
            'ORDER'  => ['step_order DESC'],
            'LIMIT'  => 1,
        ]);
        $step_order = (count($last) > 0 ? (int)$last->current()['step_order'] : 0) + 10;

        $DB->insert('glpi_plugin_tasksmanager_workflow_steps', [
            'workflows_id'     => $workflows_id,
            'tasktemplates_id' => $tasktemplates_id,
            'step_order'       => $step_order,
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);

        tm_respond(true, 200, null, [
            'step_id'    => (int)$DB->insertId(),
            'step_order' => $step_order,
        ]);

    // ── Add a follow-up-only step (no task, just an ITILFollowup) ─────────
    case 'add_followup_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $workflows_id            = (int)($_POST['workflows_id'] ?? 0);
        $itilfollowuptemplates_id = (int)($_POST['itilfollowuptemplates_id'] ?? 0);
        $assign_groups_id        = (int)($_POST['assign_groups_id'] ?? 0);

        if (!$workflows_id || !$itilfollowuptemplates_id) {
            tm_respond(false, 400, 'Missing parameters');
        }

        $last = $DB->request([
            'SELECT' => ['step_order'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE'  => ['workflows_id' => $workflows_id],
            'ORDER'  => ['step_order DESC'],
            'LIMIT'  => 1,
        ]);
        $step_order = (count($last) > 0 ? (int)$last->current()['step_order'] : 0) + 10;

        $DB->insert('glpi_plugin_tasksmanager_workflow_steps', [
            'workflows_id'             => $workflows_id,
            'tasktemplates_id'         => 0, // follow-up-only marker
            'itilfollowuptemplates_id' => $itilfollowuptemplates_id,
            'assign_groups_id'         => $assign_groups_id > 0 ? $assign_groups_id : 0,
            'step_order'               => $step_order,
            'date_creation'            => date('Y-m-d H:i:s'),
        ]);

        tm_respond(true, 200, null, [
            'step_id'    => (int)$DB->insertId(),
            'step_order' => $step_order,
        ]);

    // ── Change the team a follow-up-only step assigns the ticket to ───────
    case 'save_followup_team':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $step_id          = (int)($_POST['step_id'] ?? 0);
        $assign_groups_id = (int)($_POST['assign_groups_id'] ?? 0);
        if (!$step_id) {
            tm_respond(false, 400, 'Missing step_id');
        }
        $DB->update(
            'glpi_plugin_tasksmanager_workflow_steps',
            ['assign_groups_id' => $assign_groups_id > 0 ? $assign_groups_id : 0],
            ['id' => $step_id]
        );
        tm_respond(true, 200, null, ['assign_groups_id' => $assign_groups_id]);

    // ── Update the linked task template's `comment` field ─────────────────
    // (Bound to the description textarea on the workflow editor — changes
    // here apply to every workflow that references the same template.)
    case 'update_template_comment':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $tasktemplates_id = (int)($_POST['tasktemplates_id'] ?? 0);
        $comment          = (string)($_POST['comment'] ?? '');
        if (!$tasktemplates_id) {
            tm_respond(false, 400, 'Missing tasktemplates_id');
        }

        $tpl = new TaskTemplate();
        if (!$tpl->getFromDB($tasktemplates_id)) {
            tm_respond(false, 404, 'Template not found');
        }
        $tpl->update([
            'id'      => $tasktemplates_id,
            'comment' => $comment,
        ]);
        tm_respond(true);

    // ── Save conditional-routing rules for a step (1.5.0+) ────────────────
    // Body:
    //   step_id              : int
    //   rules                : JSON-encoded array of { field, op, value, goto_step_id }
    //   default_goto_step_id : int (0 = linear next, -1 = end workflow, >0 = step id)
    case 'save_step_rules':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $step_id      = (int)($_POST['step_id'] ?? 0);
        $rules_raw    = (string)($_POST['rules'] ?? '');
        // Use a sentinel so we can tell "0 sent" vs "field omitted" — if the
        // caller didn't send the key at all, we leave the column alone.
        $default_sent = array_key_exists('default_goto_step_id', $_POST);
        $default_goto = $default_sent ? (int)$_POST['default_goto_step_id'] : 0;

        if (!$step_id) {
            tm_respond(false, 400, 'Missing step_id');
        }

        $decoded = json_decode($rules_raw, true);
        if ($rules_raw !== '' && !is_array($decoded)) {
            tm_respond(false, 400, 'Invalid rules JSON');
        }

        // Normalise / whitelist: drop unknown fields, coerce types, skip empty
        $allowed_ops = ['contains', 'not_contains', 'eq', 'neq'];
        $clean = [];
        foreach ((array)$decoded as $r) {
            if (!is_array($r)) { continue; }
            $field = trim((string)($r['field'] ?? ''));
            $op    = (string)($r['op']           ?? '');
            $value = (string)($r['value']        ?? '');
            $goto  = (int)   ($r['goto_step_id'] ?? 0);
            if ($field === '' || $goto <= 0) { continue; }
            if (!in_array($op, $allowed_ops, true)) { continue; }
            $clean[] = [
                'field'        => $field,
                'op'           => $op,
                'value'        => $value,
                'goto_step_id' => $goto,
            ];
        }

        // Clamp default_goto: anything other than -1 / 0 / a positive step id
        // gets coerced to 0 (linear). The resolver re-validates forward-only
        // at evaluation time, so an invalid step id here is harmless.
        if ($default_goto < -1) { $default_goto = 0; }

        $update = ['next_step_rules' => empty($clean) ? null : json_encode($clean)];
        if ($default_sent) {
            $update['default_goto_step_id'] = $default_goto;
        }

        $DB->update(
            'glpi_plugin_tasksmanager_workflow_steps',
            $update,
            ['id' => $step_id]
        );
        tm_respond(true, 200, null, [
            'rule_count'           => count($clean),
            'default_goto_step_id' => $default_goto,
        ]);

    // ── Save per-step SLA config (1.8.0+) ─────────────────────────────────
    // Body:
    //   step_id              : int
    //   sla_duration         : int seconds (0/empty => clear SLA on this step)
    //   sla_warning_pct      : int 0..100
    //   sla_breach_action    : notify | reassign | skip | priority_up
    //   sla_breach_groups_id : int (reassign target group)
    //   sla_breach_users_id  : int (reassign target tech)
    //   sla_use_calendar     : 0|1
    case 'save_step_sla':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $step_id = (int)($_POST['step_id'] ?? 0);
        if (!$step_id) {
            tm_respond(false, 400, 'Missing step_id');
        }

        $duration = (int)($_POST['sla_duration'] ?? 0);
        if ($duration < 0) { $duration = 0; }

        $warn = (int)($_POST['sla_warning_pct'] ?? 75);
        if ($warn < 0)   { $warn = 0; }
        if ($warn > 100) { $warn = 100; }

        $action = (string)($_POST['sla_breach_action'] ?? \GlpiPlugin\Tasksmanager\Sla::ACTION_NOTIFY);
        if (!in_array($action, \GlpiPlugin\Tasksmanager\Sla::ACTIONS, true)) {
            $action = \GlpiPlugin\Tasksmanager\Sla::ACTION_NOTIFY;
        }

        $breach_group = (int)($_POST['sla_breach_groups_id'] ?? 0);
        $breach_user  = (int)($_POST['sla_breach_users_id']  ?? 0);
        if ($breach_group < 0) { $breach_group = 0; }
        if ($breach_user  < 0) { $breach_user  = 0; }

        $use_calendar = !empty($_POST['sla_use_calendar']) ? 1 : 0;
        $olas_id      = (int)($_POST['olas_id'] ?? 0);
        if ($olas_id < 0) { $olas_id = 0; }

        // When an OLA is referenced, the budget + calendar come from it, so
        // we clear the custom duration to avoid ambiguity (engine prefers
        // olas_id when > 0, but keeping the columns consistent avoids stale
        // values surfacing in the editor). duration 0 + olas_id 0 = no SLA.
        $DB->update(
            'glpi_plugin_tasksmanager_workflow_steps',
            [
                'sla_duration'         => ($olas_id === 0 && $duration > 0) ? $duration : null,
                'sla_warning_pct'      => $warn,
                'sla_breach_action'    => $action,
                'sla_breach_groups_id' => $breach_group,
                'sla_breach_users_id'  => $breach_user,
                'sla_use_calendar'     => $use_calendar,
                'olas_id'              => $olas_id,
            ],
            ['id' => $step_id]
        );
        tm_respond(true, 200, null, ['sla_duration' => $duration, 'olas_id' => $olas_id]);

    // ── Set / clear the per-step ITILFollowupTemplate ─────────────────────
    // Body:
    //   step_id                  : int
    //   itilfollowuptemplates_id : int (0 = clear)
    case 'update_step_followup_template':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $step_id = (int)($_POST['step_id'] ?? 0);
        $fup_id  = (int)($_POST['itilfollowuptemplates_id'] ?? 0);
        if ($fup_id < 0) { $fup_id = 0; }
        if (!$step_id) {
            tm_respond(false, 400, 'Missing step_id');
        }

        // Light existence check when a non-zero template id is set, so the
        // editor catches stale references early rather than failing
        // silently at apply time.
        if ($fup_id > 0) {
            $exists = $DB->request([
                'COUNT' => 'c',
                'FROM'  => 'glpi_itilfollowuptemplates',
                'WHERE' => ['id' => $fup_id],
            ])->current()['c'] ?? 0;
            if ((int)$exists === 0) {
                tm_respond(false, 404, 'ITILFollowupTemplate not found');
            }
        }

        $DB->update(
            'glpi_plugin_tasksmanager_workflow_steps',
            ['itilfollowuptemplates_id' => $fup_id],
            ['id' => $step_id]
        );
        tm_respond(true, 200, null, ['itilfollowuptemplates_id' => $fup_id]);

    // ── List form questions (for the rule-field dropdown) ────────────────
    // Returns [{id, label, form_name}] — used to populate the field picker
    // when building "answer to form question equals …" rules.
    //
    // If `workflows_id` is supplied, we first look up which forms reference
    // *this* workflow (via their Ticket destination's WorkflowField config)
    // and restrict the question list to those forms. This keeps the
    // dropdown small and relevant — you only see questions that can
    // actually be evaluated on tickets created by this workflow.
    //
    // If no form references the workflow yet (e.g. you're building rules
    // ahead of wiring up the destination), falls back to ALL questions
    // and flags `filtered_by_workflow=false` so the UI can show a hint.
    case 'list_form_questions':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $workflows_id = (int)($_POST['workflows_id'] ?? 0);

        // Step 1: figure out which forms reference this workflow.
        //
        // The config column on glpi_forms_destinations_formdestinations is a
        // JSON object keyed by the slugified FQCN of each ConfigField
        // (AbstractConfigField::getKey() returns Toolbox::slugify(static::class)
        // and is declared `final`, so our class constant `KEY` is NOT what
        // GLPI uses). For our WorkflowField the real key is something like
        // `glpiplugin-tasksmanager-form-destination-workflowfield`.
        //
        // We compute it dynamically via getKey() so this stays correct if
        // the class moves or GLPI changes its slugify scheme. Also scan all
        // top-level config keys as a defensive sweep — catches the workflow
        // id no matter how GLPI keyed it.
        $form_ids_filter = null;
        if ($workflows_id > 0
            && $DB->tableExists('glpi_forms_destinations_formdestinations')
            && class_exists('\\GlpiPlugin\\Tasksmanager\\Form\\Destination\\WorkflowField')
        ) {
            $wf_field_key = \GlpiPlugin\Tasksmanager\Form\Destination\WorkflowField::getKey();

            $form_ids = [];
            foreach ($DB->request([
                'SELECT' => ['forms_forms_id', 'config'],
                'FROM'   => 'glpi_forms_destinations_formdestinations',
            ]) as $row) {
                $cfg = json_decode((string)($row['config'] ?? ''), true);
                if (!is_array($cfg)) {
                    continue;
                }

                // Primary check: the canonical slugified key
                $val = $cfg[$wf_field_key]['value'] ?? null;

                // Defensive sweep: any field whose value matches our id and
                // whose key plausibly belongs to us (contains "tasksmanager"
                // or "workflow") — so we never mistake another plugin's
                // field for ours.
                if ((int)$val !== $workflows_id) {
                    foreach ($cfg as $k => $v) {
                        if (!is_array($v) || !isset($v['value'])) continue;
                        if ((int)$v['value'] !== $workflows_id)   continue;
                        $lk = strtolower((string)$k);
                        if (str_contains($lk, 'tasksmanager') || str_contains($lk, 'workflow')) {
                            $val = $v['value'];
                            break;
                        }
                    }
                }

                if ((int)$val === $workflows_id) {
                    $form_ids[(int)$row['forms_forms_id']] = true;
                }
            }
            if (!empty($form_ids)) {
                $form_ids_filter = array_keys($form_ids);
            }
        }

        $out = [];
        if ($DB->tableExists('glpi_forms_questions')) {
            $can_join_form = $DB->tableExists('glpi_forms_sections')
                          && $DB->tableExists('glpi_forms_forms');

            if ($can_join_form) {
                $req = [
                    'SELECT'    => [
                        'q.id', 'q.name',
                        'f.name AS form_name',
                    ],
                    'FROM'      => 'glpi_forms_questions AS q',
                    'LEFT JOIN' => [
                        'glpi_forms_sections AS s' => [
                            'ON' => ['q' => 'forms_sections_id', 's' => 'id'],
                        ],
                        'glpi_forms_forms AS f' => [
                            'ON' => ['s' => 'forms_forms_id', 'f' => 'id'],
                        ],
                    ],
                    'ORDER'     => ['form_name ASC', 'q.id ASC'],
                ];
                if ($form_ids_filter !== null) {
                    $req['WHERE'] = ['f.id' => $form_ids_filter];
                }
            } else {
                $req = [
                    'SELECT' => ['q.id', 'q.name'],
                    'FROM'   => 'glpi_forms_questions AS q',
                    'ORDER'  => ['q.id ASC'],
                ];
            }
            foreach ($DB->request($req) as $row) {
                $out[] = [
                    'id'        => (int)$row['id'],
                    'label'     => (string)($row['name'] ?? ''),
                    'form_name' => (string)($row['form_name'] ?? ''),
                ];
            }
        }
        tm_respond(true, 200, null, [
            'questions'            => $out,
            'filtered_by_workflow' => $form_ids_filter !== null,
            'matching_form_count'  => $form_ids_filter !== null ? count($form_ids_filter) : 0,
        ]);

    // ── Remove a step ─────────────────────────────────────────────────────
    case 'remove_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $step_id = (int)($_POST['step_id'] ?? 0);
        if (!$step_id) {
            tm_respond(false, 400, 'Missing step_id');
        }
        $DB->delete('glpi_plugin_tasksmanager_workflow_steps', ['id' => $step_id]);
        tm_respond(true);

    // ── Save new order ────────────────────────────────────────────────────
    case 'reorder_steps':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $workflows_id = (int)($_POST['workflows_id'] ?? 0);
        $order        = json_decode($_POST['order'] ?? '[]', true);

        if (!$workflows_id || !is_array($order)) {
            tm_respond(false, 400, 'Invalid parameters');
        }
        foreach ($order as $position => $step_id) {
            $DB->update(
                'glpi_plugin_tasksmanager_workflow_steps',
                ['step_order' => ($position + 1) * 10],
                ['id' => (int)$step_id, 'workflows_id' => $workflows_id]
            );
        }
        tm_respond(true);

    // ── Apply workflow to a ticket ─────────────────────────────────────────
    case 'apply_to_ticket':
        Session::checkRight('ticket', UPDATE);

        $tickets_id   = (int)($_POST['tickets_id']   ?? 0);
        $workflows_id = (int)($_POST['workflows_id'] ?? 0);

        if (!$tickets_id || !$workflows_id) {
            tm_respond(false, 400, 'Missing parameters');
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
            tm_respond(false, 403, 'Access denied');
        }

        if (!Workflow::applyToTicket($tickets_id, $workflows_id)) {
            tm_respond(false, 500, __(
                'Could not apply workflow. The ticket may already have an active workflow, the workflow may have no steps, or the first task template is invalid.',
                'tasksmanager'
            ));
        }

        tm_respond(true);

    // ── Admin: skip the current step ──────────────────────────────────────
    case 'skip_current_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $ticket_workflows_id = (int)($_POST['ticket_workflows_id'] ?? 0);
        if (!$ticket_workflows_id) {
            tm_respond(false, 400, 'Missing ticket_workflows_id');
        }

        // Per-ticket authorization. The `plugin_tasksmanager_workflows
        // UPDATE` right gates *who* can manage workflows in general; it
        // does NOT bound the user to tickets they can actually touch.
        // Resolve the parent ticket and gate on Ticket::can(UPDATE) so
        // a workflow manager scoped to Entity A can't skip / mutate
        // active workflows on tickets in Entity B by enumerating the
        // sequential ticket_workflows_id values.
        $tickets_id = Workflow::getTicketIdForWorkflow($ticket_workflows_id);
        if ($tickets_id <= 0) {
            tm_respond(false, 404, 'Workflow not found or not active');
        }
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
            tm_respond(false, 403, 'Access denied');
        }

        if (!Workflow::skipCurrentStep($ticket_workflows_id)) {
            tm_respond(false, 500, 'Skip failed (workflow may not be active)');
        }
        tm_respond(true);

    // ── Admin: restart the current step ───────────────────────────────────
    case 'restart_current_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $ticket_workflows_id = (int)($_POST['ticket_workflows_id'] ?? 0);
        if (!$ticket_workflows_id) {
            tm_respond(false, 400, 'Missing ticket_workflows_id');
        }

        // Same per-ticket authorization as skip_current_step. Without
        // this gate, a workflow manager could restart steps on tickets
        // outside their entity scope and trigger task creation /
        // re-assignment side-effects without ever being able to view
        // the ticket itself.
        $tickets_id = Workflow::getTicketIdForWorkflow($ticket_workflows_id);
        if ($tickets_id <= 0) {
            tm_respond(false, 404, 'Workflow not found or not active');
        }
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
            tm_respond(false, 403, 'Access denied');
        }

        if (!Workflow::restartCurrentStep($ticket_workflows_id)) {
            tm_respond(false, 500, 'Restart failed (workflow may not be active)');
        }
        tm_respond(true);

    // ── Remove active workflow from ticket ────────────────────────────────
    case 'remove_from_ticket':
        Session::checkRight('ticket', UPDATE);

        $tickets_id = (int)($_POST['tickets_id'] ?? 0);
        if (!$tickets_id) {
            tm_respond(false, 400, 'Missing tickets_id');
        }

        // Per-ticket authorization. The profile-level `ticket UPDATE`
        // check above doesn't bound the user to tickets in their entity
        // scope. Mirror the apply_to_ticket pattern: load the parent
        // ticket and verify canUpdateItem() — that walks the actor and
        // entity checks, refusing IDOR attempts (e.g. an Entity-A user
        // cancelling an active workflow on a ticket in Entity B).
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
            tm_respond(false, 403, 'Access denied');
        }

        // Capture context before cancelling so we can log it
        $active = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE' => ['tickets_id' => $tickets_id, 'status' => 'active'],
            'LIMIT' => 1,
        ]);
        $tw_id_for_log = 0;
        $wf_id_for_log = 0;
        $step_for_log  = null;
        if (count($active) > 0) {
            $row = $active->current();
            $tw_id_for_log = (int)$row['id'];
            $wf_id_for_log = (int)$row['workflows_id'];
            $step_for_log  = (int)$row['current_step'];
        }

        $DB->update(
            'glpi_plugin_tasksmanager_ticket_workflows',
            ['status' => 'cancelled'],
            ['tickets_id' => $tickets_id, 'status' => 'active']
        );

        if ($tw_id_for_log > 0) {
            Workflow::logEvent(
                'workflow_removed',
                $tickets_id,
                $wf_id_for_log,
                $tw_id_for_log,
                $step_for_log
            );
        }
        tm_respond(true);

    default:
        tm_respond(false, 400, 'Unknown action');
}
