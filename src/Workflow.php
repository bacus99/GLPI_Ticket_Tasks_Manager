<?php

namespace GlpiPlugin\Tasksmanager;

use CommonDBTM;
use Plugin;
use Session;

/**
 * Workflow - a named, ordered sequence of GLPI task templates.
 *
 * When assigned to a ticket the first template's task is created immediately.
 * Each time a step's task is completed, the next template is instantiated
 * and the ticket assignment is updated to the new task's tech/group.
 */
class Workflow extends CommonDBTM
{
    public static $rightname = 'plugin_tasksmanager_workflows';

    /**
     * Suppress the auto-advance hook for the duration of an admin override
     * (Skip / Restart). When true, plugin_tasksmanager_item_update() returns
     * early instead of advancing the workflow on its own — otherwise we'd
     * double-advance because the admin action also marks the task Done,
     * which trips the same hook.
     */
    public static bool $suppressAutoAdvance = false;

    public static function getTypeName($nb = 0): string
    {
        return _n('Workflow', 'Workflows', $nb, 'tasksmanager');
    }

    public static function getIcon(): string
    {
        return 'ti ti-git-branch';
    }

    public static function getMenuContent(): array
    {
        $menu = [
            'title' => self::getTypeName(2),
            'page'  => Plugin::getWebDir('tasksmanager', false) . '/front/workflow.list.php',
            'icon'  => self::getIcon(),
        ];

        if (Session::haveRight(self::$rightname, UPDATE)) {
            $menu['links']['search'] = Plugin::getWebDir('tasksmanager', false) . '/front/workflow.list.php';
            $menu['links']['add']    = Plugin::getWebDir('tasksmanager', false) . '/front/workflow.form.php';
        }

        if (Session::haveRight(self::$rightname, READ)) {
            // Custom menu link to the analytics dashboard (chart icon).
            $menu['links']['<i class="ti ti-chart-bar"></i>'] =
                Plugin::getWebDir('tasksmanager', false) . '/front/analytics.php';
        }

        return $menu;
    }

    /**
     * Return all steps for this workflow ordered by step_order, with template names resolved.
     *
     * @return array
     */
    public function getSteps(): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                'wfs.id',
                'wfs.step_order',
                'wfs.tasktemplates_id',
                'tt.name AS template_name',
                'tt.users_id_tech',
                'tt.groups_id_tech',
            ],
            'FROM'      => 'glpi_plugin_tasksmanager_workflow_steps AS wfs',
            'LEFT JOIN' => [
                'glpi_tasktemplates AS tt' => [
                    'ON' => ['wfs' => 'tasktemplates_id', 'tt' => 'id'],
                ],
            ],
            'WHERE'     => ['wfs.workflows_id' => $this->getID()],
            'ORDER'     => ['wfs.step_order ASC'],
        ]);

        $steps = [];
        foreach ($iterator as $row) {
            $steps[] = $row;
        }
        return $steps;
    }

    /**
     * Return all active workflows as id => name pairs, for use in dropdowns.
     */
    public static function getDropdownOptions(): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflows',
            'WHERE' => ['is_active' => 1],
            'ORDER' => ['name ASC'],
        ]);

        $options = [];
        foreach ($iterator as $row) {
            $options[(int)$row['id']] = $row['name'];
        }
        return $options;
    }

    /**
     * Apply a workflow to a ticket: create the ticket_workflow record and
     * instantiate the first step's task.
     *
     * Returns false if the ticket already has an active workflow, if the
     * workflow has no steps, or if the first task could not be created.
     */
    public static function applyToTicket(int $tickets_id, int $workflows_id): bool
    {
        global $DB;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE' => ['tickets_id' => $tickets_id, 'status' => 'active'],
        ]);
        if (count($existing) > 0) {
            return false;
        }

        $first_step_iter = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE' => ['workflows_id' => $workflows_id],
            'ORDER' => ['step_order ASC'],
            'LIMIT' => 1,
        ]);
        if (count($first_step_iter) === 0) {
            return false;
        }

        $first_step = $first_step_iter->current();

        $DB->insert('glpi_plugin_tasksmanager_ticket_workflows', [
            'tickets_id'    => $tickets_id,
            'workflows_id'  => $workflows_id,
            'current_step'  => (int)$first_step['step_order'],
            'status'        => 'active',
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $ticket_workflows_id = $DB->insertId();

        if (!self::applyStep($tickets_id, $first_step, $ticket_workflows_id)) {
            $DB->delete('glpi_plugin_tasksmanager_ticket_workflows', ['id' => $ticket_workflows_id]);
            return false;
        }

        self::logEvent(
            'workflow_applied',
            $tickets_id,
            $workflows_id,
            $ticket_workflows_id,
            (int)$first_step['step_order'],
            ['step_id' => (int)$first_step['id']]
        );

        // First step may be non-blocking (Information / Done template) —
        // advance past it right away rather than stalling on a task that
        // has no checkbox.
        self::chainIfNonBlocking($first_step, $ticket_workflows_id, $tickets_id, $workflows_id);

        return true;
    }

    /**
     * Create a TicketTask from a workflow step's template and record it in taskstates.
     */
    public static function applyStep(int $tickets_id, array $step, int $ticket_workflows_id): bool
    {
        global $DB;

        // Follow-up-only step (no task template): post just an ITILFollowup
        // and let the workflow flow straight through it. Always non-blocking.
        if ((int)($step['tasktemplates_id'] ?? 0) <= 0) {
            return self::applyFollowupOnlyStep($tickets_id, $step, $ticket_workflows_id);
        }

        $template = new \TaskTemplate();
        if (!$template->getFromDB((int)$step['tasktemplates_id'])) {
            return false;
        }

        // The task body comes from the linked task template's `content`.
        // (The "Add template comment" textarea in the workflow editor is a
        // shortcut to the template's `comment` field — admin-facing only,
        // not copied into the resulting task.)
        $content = $template->fields['content'] ?? '';
        if (empty(trim(strip_tags($content)))) {
            $content = $template->fields['name'] ?? __('Task', 'tasksmanager');
        }

        // Respect the template's task state instead of forcing "To do".
        //   0 = Information, 1 = To do, 2 = Done.
        // (1.3.2 used to force state 1 so tasks always showed a checkbox;
        // since 1.9.1 the state is preserved and non-To-do steps become
        // NON-BLOCKING: an Information/Done task has no actionable
        // checkbox, so the workflow advances past it immediately after
        // creation — see the chaining in advanceFrom() and the
        // chainIfNonBlocking() calls at every applyStep call site.)
        $task_state = (int)($template->fields['state'] ?? 1);
        if (!in_array($task_state, [0, 1, 2], true)) {
            $task_state = 1;
        }

        $input = [
            'tickets_id'  => $tickets_id,
            'content'     => $content,
            'name'        => $template->fields['name'] ?? '',
            'state'       => $task_state,
            'is_private'  => (int)($template->fields['is_private'] ?? 0),
            'actiontime'  => (int)($template->fields['actiontime'] ?? 0),
        ];

        // Task templates can store `-1` for users_id_tech / groups_id_tech to
        // mean "no specific user/group" (GLPI placeholder). Treat anything
        // <= 0 as empty — both for the task input and the actor swap below —
        // otherwise the INT UNSIGNED column rejects the insert with
        // "Out of range value for column 'users_id_tech'".
        $tpl_user  = (int)($template->fields['users_id_tech']  ?? 0);
        $tpl_group = (int)($template->fields['groups_id_tech'] ?? 0);
        if ($tpl_user  < 0) { $tpl_user  = 0; }
        if ($tpl_group < 0) { $tpl_group = 0; }

        if (!empty($template->fields['taskcategories_id'])) {
            $input['taskcategories_id'] = (int)$template->fields['taskcategories_id'];
        }
        if ($tpl_user > 0) {
            $input['users_id_tech'] = $tpl_user;
        }
        if ($tpl_group > 0) {
            $input['groups_id_tech'] = $tpl_group;
        }

        // IMPORTANT: swap the ticket's ASSIGN actors BEFORE creating the task,
        // so GLPI's "new task" notification (sent inside TicketTask::add())
        // goes to the new step's team, not the previous one. This applies to
        // EVERY step type, including non-blocking Information / Done steps —
        // those are commonly used as the final "hand the ticket to team X"
        // milestone, so they must set the ticket's team too.
        //
        // Skip only when the workflow has `assign_ticket_to_task = 0`.
        //
        // The swap is performed SILENTLY (swapAssignActors disables the
        // ticket-update notification): historically the swap's own
        // Ticket::update raised an "update" notification *before* the new
        // task existed, so it rendered with the previous task's content —
        // that was the "email shows the previous task" bug. The task's own
        // add_task notification (fired just below) is the one that should
        // email, and it carries the correct current-task content.
        if (($tpl_user > 0 || $tpl_group > 0) && self::shouldAssignTicketToTaskTeam($ticket_workflows_id)) {
            self::swapAssignActors($tickets_id, $tpl_user, $tpl_group);
        }

        // For NON-BLOCKING steps (Information / Done), suppress the task's
        // own add_task notification — it is unreliable here. GLPI renders
        // task notifications by listing ALL ticket tasks ordered
        // `date_mod DESC, id ASC`; a task auto-created in the SAME request
        // that just completed the previous task ties on the second, so the
        // lower-id (previous) task renders first → "email shows the
        // previous task's content". We instead deliver the step's message
        // through a FOLLOWUP below, which GLPI orders `date_mod DESC,
        // id DESC` (newest first) and therefore renders correctly.
        // Blocking "To do" steps are completed in their own later request,
        // so they don't tie — their task email is left untouched.
        $is_blocking = ($task_state === 1);
        if (!$is_blocking) {
            $input['_disablenotif'] = true;
        }

        $task        = new \TicketTask();
        $new_task_id = $task->add($input);

        if (!$new_task_id) {
            return false;
        }

        $ts_update = [
            'ticket_workflows_id' => $ticket_workflows_id,
            'workflow_step_order' => (int)$step['step_order'],
        ];
        if ($task_state !== 1) {
            // Non-blocking task (Information / already-Done) — nobody will
            // ever tick it, so reflect that in our own tracking too;
            // otherwise dashboards would show it as pending forever.
            $ts_update['plugin_status'] = 'done';
            $ts_update['progress']      = 100;
        }
        $DB->update(
            'glpi_plugin_tasksmanager_taskstates',
            $ts_update,
            ['tickettasks_id' => $new_task_id]
        );

        // Signal the browser that a workflow advance just happened. The JS
        // (public/js/workflow-refresh.js) listens for this header on any XHR
        // response and triggers a page reload — that way the auto-refresh
        // only fires for real workflow advances, never for answer/followup
        // edits or other timeline traffic.
        if (!headers_sent()) {
            header('X-TM-Workflow-Advanced: 1');
        }

        // Optional: also drop a templated ITILFollowup ("answer") on the
        // ticket — same pattern as the TaskTemplate above, but for the
        // followup timeline entry. Used to send standard status updates to
        // the requester at step transitions (e.g. "Step 2 started: the
        // Windows team is now configuring your VM").
        $new_followup_id = 0;
        $fup_template_id = (int)($step['itilfollowuptemplates_id'] ?? 0);
        if ($fup_template_id > 0) {
            $new_followup_id = self::applyFollowupTemplate($tickets_id, $fup_template_id);
        } elseif (!$is_blocking) {
            // Non-blocking step with no explicit answer template: mirror the
            // task content into a followup so the step's message still goes
            // out — reliably, with the correct content — since we suppressed
            // the (unreliable) task email above. The followup inherits the
            // template's privacy so an internal note stays internal.
            $fu = new \ITILFollowup();
            $new_followup_id = (int)$fu->add([
                'itemtype'   => 'Ticket',
                'items_id'   => $tickets_id,
                'content'    => $content,
                'is_private' => (int)($template->fields['is_private'] ?? 0),
            ]);
        }

        // Audit log
        // Look up the parent workflow id for context.
        $tw_lookup = $DB->request([
            'SELECT' => ['workflows_id'],
            'FROM'   => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE'  => ['id' => $ticket_workflows_id],
            'LIMIT'  => 1,
        ]);
        $wf_id_for_log = (count($tw_lookup) > 0) ? (int)$tw_lookup->current()['workflows_id'] : 0;

        self::logEvent(
            'step_started',
            $tickets_id,
            $wf_id_for_log,
            $ticket_workflows_id,
            (int)$step['step_order'],
            [
                'tickettasks_id'           => $new_task_id,
                'tasktemplates_id'         => (int)$step['tasktemplates_id'],
                'users_id_tech'            => $tpl_user,
                'groups_id_tech'           => $tpl_group,
                'itilfollowuptemplates_id' => $fup_template_id,
                'itilfollowups_id'         => $new_followup_id,
            ]
        );

        return true;
    }

    /**
     * Apply a FOLLOW-UP-ONLY step: a workflow step with no task template,
     * just an ITILFollowup ("answer") posted to the ticket. Always
     * non-blocking — the workflow flows straight through it. Used for pure
     * status-update milestones ("we've received your request and started
     * provisioning") that don't need anyone to tick a task.
     */
    private static function applyFollowupOnlyStep(int $tickets_id, array $step, int $ticket_workflows_id): bool
    {
        global $DB;

        // A follow-up has no team of its own, so its notification would go to
        // whoever the ticket is currently assigned to (the previous step's
        // team). If this step carries an assign target, reassign the ticket
        // FIRST (silently — swapAssignActors disables the update notif), so
        // the follow-up posted just below reaches the intended team.
        $assign_group = (int)($step['assign_groups_id'] ?? 0);
        $assign_user  = (int)($step['assign_users_id']  ?? 0);
        if (($assign_group > 0 || $assign_user > 0)
            && self::shouldAssignTicketToTaskTeam($ticket_workflows_id)
        ) {
            self::swapAssignActors($tickets_id, $assign_user, $assign_group);
        }

        $fup_template_id = (int)($step['itilfollowuptemplates_id'] ?? 0);
        $new_followup_id = $fup_template_id > 0
            ? self::applyFollowupTemplate($tickets_id, $fup_template_id)
            : 0;

        // Record a taskstate marker so the step counts as "started/done" in
        // the dashboard (Done badge, not Skipped) and so step ordering /
        // progress maths stay consistent. No real TicketTask exists, so
        // tickettasks_id stays 0.
        $DB->insert('glpi_plugin_tasksmanager_taskstates', [
            'tickets_id'          => $tickets_id,
            'tickettasks_id'      => 0,
            'plugin_status'       => 'done',
            'progress'            => 100,
            'priority'            => 3,
            'ticket_workflows_id' => $ticket_workflows_id,
            'workflow_step_order' => (int)$step['step_order'],
            'date_creation'       => date('Y-m-d H:i:s'),
        ]);

        // Same browser-refresh signal as a normal advance.
        if (!headers_sent()) {
            header('X-TM-Workflow-Advanced: 1');
        }

        $tw_lookup = $DB->request([
            'SELECT' => ['workflows_id'],
            'FROM'   => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE'  => ['id' => $ticket_workflows_id],
            'LIMIT'  => 1,
        ]);
        $wf_id_for_log = (count($tw_lookup) > 0) ? (int)$tw_lookup->current()['workflows_id'] : 0;

        self::logEvent(
            'step_started',
            $tickets_id,
            $wf_id_for_log,
            $ticket_workflows_id,
            (int)$step['step_order'],
            [
                'tickettasks_id'           => 0,
                'tasktemplates_id'         => 0,
                'followup_only'            => true,
                'itilfollowuptemplates_id' => $fup_template_id,
                'itilfollowups_id'         => $new_followup_id,
            ]
        );

        return true;
    }

    /**
     * Is this step non-blocking — i.e. nothing for a human to tick, so the
     * workflow should advance past it immediately?
     *   - follow-up-only step (no task template) → always non-blocking
     *   - task whose template state is Information/Done (not "To do")
     *   - missing/invalid template → treat as non-blocking so a broken
     *     step can't stall the workflow forever
     */
    private static function isStepNonBlocking(array $step): bool
    {
        if ((int)($step['tasktemplates_id'] ?? 0) <= 0) {
            return true;
        }
        $t = new \TaskTemplate();
        if (!$t->getFromDB((int)$step['tasktemplates_id'])) {
            return true;
        }
        return (int)($t->fields['state'] ?? 1) !== 1;
    }

    /**
     * Record an audit-log entry. Safe to call when the table is missing
     * (pre-1.3.14 installs that haven't run the upgrade yet) — just silently
     * skips the insert.
     *
     * Common event types:
     *   workflow_applied   — initial application of a workflow to a ticket
     *   step_started      — the next step's task was created
     *   workflow_completed — final step done; workflow marked completed
     *   workflow_removed   — user removed an in-flight workflow from the ticket
     */
    public static function logEvent(
        string $event_type,
        int $tickets_id,
        int $workflows_id = 0,
        int $ticket_workflows_id = 0,
        ?int $step_order = null,
        array $details = []
    ): void {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_tasksmanager_workflow_events')) {
            return;
        }

        $DB->insert('glpi_plugin_tasksmanager_workflow_events', [
            'tickets_id'          => $tickets_id,
            'workflows_id'        => $workflows_id,
            'ticket_workflows_id' => $ticket_workflows_id,
            'step_order'          => $step_order,
            'event_type'          => $event_type,
            'users_id'            => (int)(\Session::getLoginUserID(false) ?: 0),
            'details'             => $details ? json_encode($details) : null,
            'date_creation'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Advance the workflow one step from `$current_step_order`: resolve the
     * next step (honouring routing rules + the else/default target), apply
     * it, or complete the workflow when there is none. Logs the step_routed
     * trace either way.
     *
     * If the step just instantiated is NON-BLOCKING (its template's task
     * state is Information or Done — no actionable checkbox), recurses to
     * keep advancing. Termination is guaranteed because routing is
     * forward-only (resolveNextStep only ever returns a strictly greater
     * step_order); the depth guard is belt-and-suspenders.
     *
     * @return bool false when the next step's task could not be created.
     */
    public static function advanceFrom(
        int $ticket_workflows_id,
        int $tickets_id,
        int $workflows_id,
        int $current_step_order,
        int $depth = 0
    ): bool {
        global $DB;

        if ($depth > 100) {
            return false;
        }

        $trace = [];
        $next  = self::resolveNextStep($workflows_id, $current_step_order, $tickets_id, $trace);
        self::logEvent(
            'step_routed',
            $tickets_id,
            $workflows_id,
            $ticket_workflows_id,
            $current_step_order,
            $trace
        );

        if ($next === null) {
            self::completeWorkflow($ticket_workflows_id, $tickets_id, $workflows_id, $current_step_order);
            return true;
        }

        if (!self::applyStep($tickets_id, $next, $ticket_workflows_id)) {
            return false;
        }
        // Update current_step BEFORE any chaining so a recursive advance
        // never regresses the pointer.
        $DB->update(
            'glpi_plugin_tasksmanager_ticket_workflows',
            ['current_step' => (int)$next['step_order']],
            ['id' => $ticket_workflows_id]
        );

        // Keep going past non-blocking steps (follow-up-only, or
        // Information/Done task templates).
        if (self::isStepNonBlocking($next)) {
            return self::advanceFrom(
                $ticket_workflows_id,
                $tickets_id,
                $workflows_id,
                (int)$next['step_order'],
                $depth + 1
            );
        }
        return true;
    }

    /**
     * Mark a workflow instance completed: status update, optional
     * completion-group reassignment, browser-refresh header, audit event.
     * (Extracted from the ITEM_UPDATE hook so non-blocking step chains can
     * complete a workflow through the same path.)
     */
    public static function completeWorkflow(
        int $ticket_workflows_id,
        int $tickets_id,
        int $workflows_id,
        int $final_step_order
    ): void {
        global $DB;

        $DB->update(
            'glpi_plugin_tasksmanager_ticket_workflows',
            ['status' => 'completed'],
            ['id' => $ticket_workflows_id]
        );

        $completion_group     = 0;
        $solutiontemplates_id = 0;
        $wf_iter = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflows',
            'WHERE' => ['id' => $workflows_id],
            'LIMIT' => 1,
        ]);
        if (count($wf_iter) > 0) {
            $wf                   = $wf_iter->current();
            $completion_group     = (int)($wf['groups_id_completion'] ?? 0);
            $solutiontemplates_id = (int)($wf['solutiontemplates_id'] ?? 0);
            if ($completion_group > 0) {
                self::swapAssignActors($tickets_id, 0, $completion_group);
            }
        }

        // Signal the browser to reload so the user sees the completion
        // banner + the "Recommended solution" button instead of the stale
        // "step N in progress" view.
        if (!headers_sent()) {
            header('X-TM-Workflow-Advanced: 1');
        }

        self::logEvent(
            'workflow_completed',
            $tickets_id,
            $workflows_id,
            $ticket_workflows_id,
            $final_step_order,
            [
                'completion_group'     => $completion_group,
                'solutiontemplates_id' => $solutiontemplates_id,
            ]
        );
    }

    /**
     * If the step's template creates a non-blocking task (state Information
     * or Done — nothing for a human to tick), advance the workflow past it
     * immediately. Used by the applyStep call sites that sit outside
     * advanceFrom (first step on apply, skip, restart).
     */
    private static function chainIfNonBlocking(
        array $step,
        int $ticket_workflows_id,
        int $tickets_id,
        int $workflows_id
    ): void {
        if (!self::isStepNonBlocking($step)) {
            return; // "To do" task blocks until a human checks the box.
        }
        self::advanceFrom($ticket_workflows_id, $tickets_id, $workflows_id, (int)$step['step_order']);
    }

    /**
     * Resolve which step should follow `$current_step_order` for the given ticket.
     *
     * Reads `next_step_rules` on the current step (JSON array). Each rule is
     * evaluated in order against the ticket; the first match's `goto_step_id`
     * wins. If no rule matches (or the column is empty), falls back to the
     * sequentially next step by `step_order` — the legacy linear behaviour.
     *
     * Rule shape:
     *   { "field": "content" | "name" | "form:<question_id>",
     *     "op":    "contains" | "not_contains" | "eq" | "neq",
     *     "value": "<string>",
     *     "goto_step_id": <int> }
     *
     * Loop guard: a rule may only route to a step whose `step_order` is
     * **greater than** the current step's — backward jumps are silently
     * ignored to prevent infinite loops.
     *
     * @return array|null Step row (id, step_order, tasktemplates_id, …) or
     *                    null when the workflow has no more steps.
     */
    public static function resolveNextStep(
        int $workflows_id,
        int $current_step_order,
        int $tickets_id,
        array &$trace = []
    ): ?array {
        global $DB;

        // Diagnostic trace — populated as we go so the caller can log a
        // step_routed audit event explaining the routing decision.
        $trace = [
            'current_step_order' => $current_step_order,
            'rules_count'        => 0,
            'evaluations'        => [],
            'decision'           => '',
            'next_step_id'       => 0,
            'next_step_order'    => null,
        ];

        // Load the current step (to read its rules + default target)
        $cur_iter = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE' => [
                'workflows_id' => $workflows_id,
                'step_order'   => $current_step_order,
            ],
            'LIMIT' => 1,
        ]);

        $rules        = [];
        $default_goto = 0; //  0 = linear next, -1 = end workflow, >0 = step id
        if (count($cur_iter) > 0) {
            $cur_row = $cur_iter->current();
            $raw     = $cur_row['next_step_rules'] ?? null;
            if (!empty($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $rules = $decoded;
                }
            }
            $default_goto = (int)($cur_row['default_goto_step_id'] ?? 0);
        }

        $trace['rules_count'] = is_array($rules) ? count($rules) : 0;

        // If we have rules, try them in order
        if (!empty($rules)) {
            foreach ($rules as $idx => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $field = (string)($rule['field']        ?? '');
                $op    = (string)($rule['op']           ?? '');
                $value = (string)($rule['value']        ?? '');
                $goto  = (int)   ($rule['goto_step_id'] ?? 0);

                $eval = [
                    'rule_index'   => $idx,
                    'field'        => $field,
                    'op'           => $op,
                    'value'        => $value,
                    'goto_step_id' => $goto,
                    'actual'       => null,
                    'matched'      => false,
                    'skip_reason'  => null,
                ];

                if ($field === '' || $op === '' || $goto <= 0) {
                    $eval['skip_reason'] = 'invalid_rule';
                    $trace['evaluations'][] = $eval;
                    continue;
                }

                $actual = self::getFieldValue($field, $tickets_id);
                // Truncate to keep the audit row small but useful
                $eval['actual'] = $actual === null
                    ? null
                    : mb_substr((string)$actual, 0, 200);

                if ($actual === null) {
                    $eval['skip_reason'] = 'field_unresolved';
                    $trace['evaluations'][] = $eval;
                    continue;
                }
                if (!self::evalOperator($op, $actual, $value)) {
                    $eval['skip_reason'] = 'op_no_match';
                    $trace['evaluations'][] = $eval;
                    continue;
                }

                $eval['matched'] = true;

                // Match — look up the goto step, but only accept forward jumps
                $goto_iter = $DB->request([
                    'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
                    'WHERE' => [
                        'id'           => $goto,
                        'workflows_id' => $workflows_id,
                        'step_order'   => ['>', $current_step_order],
                    ],
                    'LIMIT' => 1,
                ]);
                if (count($goto_iter) > 0) {
                    $row = $goto_iter->current();
                    $eval['goto_resolved'] = true;
                    $trace['evaluations'][] = $eval;
                    $trace['decision']        = 'rule_match';
                    $trace['next_step_id']    = (int)$row['id'];
                    $trace['next_step_order'] = (int)$row['step_order'];
                    return $row;
                }
                // Invalid / backward goto — fall through to the next rule
                $eval['goto_resolved'] = false;
                $eval['skip_reason']   = 'goto_invalid_or_backward';
                $trace['evaluations'][] = $eval;
            }
        }

        // No rule matched (or no rules at all) — consult the "else" default
        // before falling back to the linear next step.
        if ($default_goto === -1) {
            // Explicit "end the workflow"
            $trace['decision'] = 'default_end';
            return null;
        }
        if ($default_goto > 0) {
            $goto_iter = $DB->request([
                'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
                'WHERE' => [
                    'id'           => $default_goto,
                    'workflows_id' => $workflows_id,
                    'step_order'   => ['>', $current_step_order],
                ],
                'LIMIT' => 1,
            ]);
            if (count($goto_iter) > 0) {
                $row = $goto_iter->current();
                $trace['decision']        = 'default_goto';
                $trace['next_step_id']    = (int)$row['id'];
                $trace['next_step_order'] = (int)$row['step_order'];
                return $row;
            }
            // Invalid (deleted / backward) — silently fall through to linear
            $trace['default_goto_invalid'] = $default_goto;
        }

        // Linear next step by step_order (default, legacy behaviour)
        $next_iter = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE' => [
                'workflows_id' => $workflows_id,
                'step_order'   => ['>', $current_step_order],
            ],
            'ORDER' => ['step_order ASC'],
            'LIMIT' => 1,
        ]);
        if (count($next_iter) === 0) {
            $trace['decision'] = 'workflow_end';
            return null;
        }
        $row = $next_iter->current();
        $trace['decision']        = 'linear';
        $trace['next_step_id']    = (int)$row['id'];
        $trace['next_step_order'] = (int)$row['step_order'];
        return $row;
    }

    /**
     * Resolve a rule's `field` reference to its current value on the ticket.
     *
     * Supported fields:
     *   content              — ticket body (HTML stripped, lowercased)
     *   name                 — ticket title (lowercased)
     *   form:<question_id>   — answer from the GLPI Form that created the ticket
     *
     * Returns null when the field can't be resolved (missing ticket, missing
     * form integration, no answer). A null actual value means the rule is
     * skipped — it cannot match.
     */
    private static function getFieldValue(string $field, int $tickets_id): ?string
    {
        if (str_starts_with($field, 'form:')) {
            $question_id = (int)substr($field, 5);
            if ($question_id <= 0) {
                return null;
            }
            return self::getFormAnswer($tickets_id, $question_id);
        }

        // Ticket field
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return null;
        }
        switch ($field) {
            case 'content':
                return mb_strtolower(trim(strip_tags((string)($ticket->fields['content'] ?? ''))));
            case 'name':
                return mb_strtolower(trim((string)($ticket->fields['name'] ?? '')));
            default:
                // Allow any ticket column as a last-resort, lowercased string
                $raw = $ticket->fields[$field] ?? null;
                return $raw === null ? null : mb_strtolower((string)$raw);
        }
    }

    /**
     * Look up the answer value for `$question_id` in the AnswersSet that
     * produced `$tickets_id`. Best-effort: returns null when GLPI Forms is
     * not installed, the ticket wasn't created from a form, or the question
     * has no answer.
     */
    private static function getFormAnswer(int $tickets_id, int $question_id): ?string
    {
        global $DB;

        // GLPI 11 table names (verified against install/mysql/glpi-11.0.4-empty.sql):
        //   glpi_forms_destinations_answerssets_formdestinationitems
        //     ↳ id, forms_answerssets_id, itemtype, items_id
        //   glpi_forms_answerssets
        //     ↳ id, forms_forms_id, …, `answers` JSON
        // The CommonDBRelation class is Glpi\Form\Destination\AnswersSet_FormDestinationItem.
        // Earlier 1.5.x tried `glpi_forms_answerssets_formdestinationitems` which
        // does not exist — the actual name uses the `destinations_` plural prefix.
        $link_table = 'glpi_forms_destinations_answerssets_formdestinationitems';
        if (!$DB->tableExists($link_table) || !$DB->tableExists('glpi_forms_answerssets')) {
            return null;
        }

        $iter = $DB->request([
            'SELECT' => ['forms_answerssets_id'],
            'FROM'   => $link_table,
            'WHERE'  => ['itemtype' => 'Ticket', 'items_id' => $tickets_id],
            'LIMIT'  => 1,
        ]);
        if (count($iter) === 0) {
            return null;
        }
        $answers_id = (int)$iter->current()['forms_answerssets_id'];
        if ($answers_id <= 0) {
            return null;
        }

        $a_iter = $DB->request([
            'SELECT' => ['answers'],
            'FROM'   => 'glpi_forms_answerssets',
            'WHERE'  => ['id' => $answers_id],
            'LIMIT'  => 1,
        ]);
        if (count($a_iter) === 0) {
            return null;
        }
        $raw = $a_iter->current()['answers'] ?? null;
        if (empty($raw)) {
            return null;
        }
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $a) {
            if ((int)($a['question_id'] ?? 0) !== $question_id) {
                continue;
            }
            $raw = $a['raw_answer'] ?? null;

            // Prefer GLPI's own formatter: for Radio / Dropdown / Item
            // questions the raw_answer is an option UUID or a foreign-key
            // record, not the human-readable label. formatRawAnswer() does
            // the lookup (Translation → option label → fk-resolved item
            // name) so a rule of "form:16 contains PRD" works against the
            // visible "PRD" label, not the internal "1686595752" UUID.
            $resolved = self::formatFormAnswer((int)$question_id, $a, $raw);
            if ($resolved !== null) {
                return mb_strtolower(trim(strip_tags((string)$resolved)));
            }

            // Fallback: raw value, flattened to a string. Works for
            // ShortText / LongText / Number / Date question types where
            // raw_answer already IS the user-visible value.
            if (is_array($raw)) {
                // Multi-value (checkbox group) or fk record — join for
                // substring/eq comparisons.
                $flat = [];
                array_walk_recursive($raw, function ($v) use (&$flat) {
                    if (is_scalar($v)) { $flat[] = (string)$v; }
                });
                $raw = implode(',', $flat);
            } elseif ($raw === null) {
                return null;
            }
            return mb_strtolower(trim(strip_tags((string)$raw)));
        }
        return null;
    }

    /**
     * Format a single answer through GLPI's own QuestionType machinery so
     * UUIDs become labels, item refs become item names, etc. Returns null
     * if the GLPI Forms classes aren't loadable or the question is gone.
     *
     * @param int          $question_id  The question we're trying to read
     * @param array        $answer_row   The raw decoded JSON entry for this question
     * @param mixed        $raw          Pre-extracted raw_answer (same as $answer_row['raw_answer'])
     */
    private static function formatFormAnswer(int $question_id, array $answer_row, mixed $raw): ?string
    {
        if (!class_exists('\\Glpi\\Form\\Question')) {
            return null;
        }
        try {
            // Try to load the live Question — gives us the option map for
            // Radio/Dropdown lookups. If the question has been deleted, we
            // can still try Answer::fromDecodedJsonData which uses the
            // stored question_label/raw_question_type but is less accurate
            // (no current option labels).
            $question = \Glpi\Form\Question::getById($question_id);
            if ($question === false) {
                return null;
            }
            $type = $question->getQuestionType();
            if ($type === null) {
                return null;
            }
            // formatRawAnswer() may HTML-escape some types — we strip tags
            // upstream, so this is fine.
            return (string)$type->formatRawAnswer($raw, $question);
        } catch (\Throwable $e) {
            // Never let a Forms quirk break the workflow advance — fall
            // back to the raw-value path on the caller side.
            return null;
        }
    }

    /**
     * Evaluate a rule operator. `$actual` is already lowercased; we lowercase
     * `$value` here so comparisons are case-insensitive end-to-end.
     */
    private static function evalOperator(string $op, string $actual, string $value): bool
    {
        $needle = mb_strtolower(trim($value));
        switch ($op) {
            case 'contains':     return $needle !== '' && str_contains($actual, $needle);
            case 'not_contains': return $needle === '' || !str_contains($actual, $needle);
            case 'eq':           return $actual === $needle;
            case 'neq':          return $actual !== $needle;
            default:             return false;
        }
    }

    /**
     * Force-advance a workflow past its current step (admin override).
     *
     * Marks any in-progress task at the current step as Done (so it disappears
     * from the user's queue), then either:
     *   - creates the next step's task and updates current_step, or
     *   - marks the workflow `completed` if there's no next step.
     *
     * Returns true on success, false when the workflow isn't active or the
     * advance fails.
     */
    public static function skipCurrentStep(int $ticket_workflows_id): bool
    {
        global $DB;

        $tw = self::loadActiveTicketWorkflow($ticket_workflows_id);
        if ($tw === null) {
            return false;
        }
        $tickets_id         = (int)$tw['tickets_id'];
        $workflows_id       = (int)$tw['workflows_id'];
        $current_step_order = (int)$tw['current_step'];

        // Suppress the auto-advance hook for the duration of this method —
        // markCurrentTaskDone() below sets state=2 on the TicketTask, which
        // would otherwise trigger plugin_tasksmanager_item_update() and
        // double-advance the workflow.
        self::$suppressAutoAdvance = true;
        try {
            self::markCurrentTaskDone($tickets_id, $ticket_workflows_id, $current_step_order);

            // Find the next step — honours conditional routing rules
            // configured on the current step (1.5.0+).
            $routing_trace = [];
            $next_step = self::resolveNextStep($workflows_id, $current_step_order, $tickets_id, $routing_trace);

            self::logEvent(
                'step_routed',
                $tickets_id,
                $workflows_id,
                $ticket_workflows_id,
                $current_step_order,
                $routing_trace + ['triggered_by' => 'skip']
            );

            if ($next_step === null) {
                // No next step — complete the workflow.
                $DB->update(
                    'glpi_plugin_tasksmanager_ticket_workflows',
                    ['status' => 'completed'],
                    ['id' => $ticket_workflows_id]
                );
                self::logEvent(
                    'step_skipped',
                    $tickets_id,
                    $workflows_id,
                    $ticket_workflows_id,
                    $current_step_order,
                    ['outcome' => 'workflow_completed']
                );
                return true;
            }

            if (!self::applyStep($tickets_id, $next_step, $ticket_workflows_id)) {
                return false;
            }
            $DB->update(
                'glpi_plugin_tasksmanager_ticket_workflows',
                ['current_step' => (int)$next_step['step_order']],
                ['id' => $ticket_workflows_id]
            );

            self::logEvent(
                'step_skipped',
                $tickets_id,
                $workflows_id,
                $ticket_workflows_id,
                $current_step_order,
                ['advanced_to' => (int)$next_step['step_order']]
            );

            // Landed on a non-blocking step? Keep advancing. (Safe inside
            // the suppress flag — chaining is our own code path, not the
            // ITEM_UPDATE hook the flag gates.)
            self::chainIfNonBlocking($next_step, $ticket_workflows_id, $tickets_id, $workflows_id);

            return true;
        } finally {
            self::$suppressAutoAdvance = false;
        }
    }

    /**
     * Re-instantiate the current step's task (admin override).
     *
     * Useful when the existing task was lost, deleted, or assigned to the wrong
     * person. The current TicketTask is marked Done (so it doesn't show twice)
     * and a fresh copy is created with the template's tech/group. current_step
     * stays the same.
     */
    public static function restartCurrentStep(int $ticket_workflows_id): bool
    {
        global $DB;

        $tw = self::loadActiveTicketWorkflow($ticket_workflows_id);
        if ($tw === null) {
            return false;
        }
        $tickets_id         = (int)$tw['tickets_id'];
        $workflows_id       = (int)$tw['workflows_id'];
        $current_step_order = (int)$tw['current_step'];

        // Look up the current step's row
        $step_iter = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE' => [
                'workflows_id' => $workflows_id,
                'step_order'   => $current_step_order,
            ],
            'LIMIT' => 1,
        ]);
        if (count($step_iter) === 0) {
            return false;
        }
        $step = $step_iter->current();

        // Suppress the auto-advance hook — same reason as skipCurrentStep:
        // markCurrentTaskDone() trips item_update which would advance the
        // workflow before we can re-instantiate at the same step.
        self::$suppressAutoAdvance = true;
        try {
            self::markCurrentTaskDone($tickets_id, $ticket_workflows_id, $current_step_order);

            if (!self::applyStep($tickets_id, $step, $ticket_workflows_id)) {
                return false;
            }

            self::logEvent(
                'step_restarted',
                $tickets_id,
                $workflows_id,
                $ticket_workflows_id,
                $current_step_order
            );

            // Restarting a non-blocking step re-posts its task and moves on.
            self::chainIfNonBlocking($step, $ticket_workflows_id, $tickets_id, $workflows_id);

            return true;
        } finally {
            self::$suppressAutoAdvance = false;
        }
    }

    /** Load an active ticket_workflow row by id, or null if not found / not active. */
    private static function loadActiveTicketWorkflow(int $ticket_workflows_id): ?array
    {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE' => ['id' => $ticket_workflows_id, 'status' => 'active'],
            'LIMIT' => 1,
        ]);
        if (count($iter) === 0) {
            return null;
        }
        return $iter->current();
    }

    /**
     * Resolve the parent ticket id for a ticket-workflow row.
     *
     * Returns 0 if the row doesn't exist OR the workflow isn't active.
     * Used by the AJAX boundary (`skip_current_step`, `restart_current_step`)
     * to look up the ticket before checking per-ticket authorization —
     * `ticket_workflows_id` values are sequential auto-increments and
     * enumerable, so gating on the ticket itself is the only way to
     * enforce GLPI's entity / actor visibility on these endpoints.
     */
    public static function getTicketIdForWorkflow(int $ticket_workflows_id): int
    {
        $row = self::loadActiveTicketWorkflow($ticket_workflows_id);
        return $row ? (int)$row['tickets_id'] : 0;
    }

    /** Mark the workflow-tracked task at the given step as Done. Best-effort. */
    private static function markCurrentTaskDone(int $tickets_id, int $ticket_workflows_id, int $step_order): void
    {
        global $DB;

        $ts = $DB->request([
            'SELECT' => ['tickettasks_id'],
            'FROM'   => 'glpi_plugin_tasksmanager_taskstates',
            'WHERE'  => [
                'tickets_id'          => $tickets_id,
                'ticket_workflows_id' => $ticket_workflows_id,
                'workflow_step_order' => $step_order,
            ],
            'LIMIT'  => 1,
        ]);
        if (count($ts) === 0) {
            return;
        }
        $tickettasks_id = (int)$ts->current()['tickettasks_id'];
        if ($tickettasks_id <= 0) {
            return;
        }
        $task = new \TicketTask();
        if ($task->getFromDB($tickettasks_id) && (int)$task->fields['state'] !== 2) {
            $task->update(['id' => $tickettasks_id, 'state' => 2]);
        }
    }

    /**
     * Duplicate a workflow (metadata + all steps) and return the new id.
     * The copy's name has " (copy)" appended; pre-existing in-flight
     * ticket_workflows are NOT copied — only the definition is duplicated.
     */
    public static function duplicate(int $source_workflow_id): int
    {
        global $DB;

        $src = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflows',
            'WHERE' => ['id' => $source_workflow_id],
            'LIMIT' => 1,
        ]);
        if (count($src) === 0) {
            return 0;
        }
        $row = $src->current();

        $DB->insert('glpi_plugin_tasksmanager_workflows', [
            'name'                  => ($row['name'] ?? 'Workflow') . ' ' . __('(copy)', 'tasksmanager'),
            'description'           => $row['description'] ?? null,
            'is_active'             => (int)($row['is_active'] ?? 1),
            'assign_ticket_to_task' => (int)($row['assign_ticket_to_task'] ?? 1),
            'groups_id_completion'  => (int)($row['groups_id_completion'] ?? 0),
            'date_creation'         => date('Y-m-d H:i:s'),
        ]);
        $new_id = (int)$DB->insertId();
        if ($new_id <= 0) {
            return 0;
        }

        // Copy steps preserving step_order
        $steps = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE' => ['workflows_id' => $source_workflow_id],
            'ORDER' => ['step_order ASC'],
        ]);
        foreach ($steps as $step) {
            $DB->insert('glpi_plugin_tasksmanager_workflow_steps', [
                'workflows_id'     => $new_id,
                'tasktemplates_id' => (int)$step['tasktemplates_id'],
                'step_order'       => (int)$step['step_order'],
                'date_creation'    => date('Y-m-d H:i:s'),
            ]);
        }

        return $new_id;
    }

    /**
     * Return whether the workflow that owns the given ticket_workflow
     * record wants the ticket reassigned to each step's task team.
     * Defaults to true if the column is missing or the lookup fails, so
     * existing behaviour is preserved.
     */
    public static function shouldAssignTicketToTaskTeam(int $ticket_workflows_id): bool
    {
        global $DB;

        $iter = $DB->request([
            'SELECT'    => ['wf.assign_ticket_to_task'],
            'FROM'      => 'glpi_plugin_tasksmanager_ticket_workflows AS tw',
            'LEFT JOIN' => [
                'glpi_plugin_tasksmanager_workflows AS wf' => ['ON' => ['tw' => 'workflows_id', 'wf' => 'id']],
            ],
            'WHERE'     => ['tw.id' => $ticket_workflows_id],
            'LIMIT'     => 1,
        ]);
        if (count($iter) === 0) {
            return true;
        }
        $row = $iter->current();
        // Column might not exist on stale schemas — null/missing → default ON.
        if (!array_key_exists('assign_ticket_to_task', $row) || $row['assign_ticket_to_task'] === null) {
            return true;
        }
        return (int)$row['assign_ticket_to_task'] === 1;
    }

    /**
     * Replace the ticket's ASSIGN actors (tech + group) while preserving
     * requesters and observers untouched.
     *
     * Goes through Ticket::update(['_actors' => …]) — the canonical GLPI 11
     * path — so the actor-service cache is invalidated and the sidebar
     * reflects the new assignment immediately on reload. Direct inserts to
     * glpi_groups_tickets / glpi_users_tickets bypass that cache and leave
     * the sidebar showing the old assignment.
     *
     * Pass 0 (or a falsy value) for $new_user_id / $new_group_id to skip
     * adding that actor type.
     */
    public static function swapAssignActors(int $tickets_id, int $new_user_id, int $new_group_id): void
    {
        global $DB;

        $actors = ['requester' => [], 'observer' => [], 'assign' => []];

        // Preserve current requesters and observers — both users and groups
        $type_map = [
            \CommonITILActor::REQUESTER => 'requester',
            \CommonITILActor::OBSERVER  => 'observer',
        ];

        foreach ($type_map as $type_const => $type_key) {
            foreach ($DB->request([
                'FROM'  => 'glpi_tickets_users',
                'WHERE' => ['tickets_id' => $tickets_id, 'type' => $type_const],
            ]) as $row) {
                $actors[$type_key][] = [
                    'itemtype'          => 'User',
                    'items_id'          => (int)$row['users_id'],
                    'use_notification'  => $row['use_notification'] ?? 1,
                    'alternative_email' => $row['alternative_email'] ?? '',
                ];
            }
            foreach ($DB->request([
                'FROM'  => 'glpi_groups_tickets',
                'WHERE' => ['tickets_id' => $tickets_id, 'type' => $type_const],
            ]) as $row) {
                $actors[$type_key][] = [
                    'itemtype' => 'Group',
                    'items_id' => (int)$row['groups_id'],
                ];
            }
        }

        // Build the new ASSIGN actor set
        if ($new_user_id > 0) {
            $actors['assign'][] = [
                'itemtype'          => 'User',
                'items_id'          => $new_user_id,
                'use_notification'  => 1,
                'alternative_email' => '',
            ];
        }
        if ($new_group_id > 0) {
            $actors['assign'][] = [
                'itemtype' => 'Group',
                'items_id' => $new_group_id,
            ];
        }

        // `_disablenotif` suppresses the ticket-update notification for this
        // actor swap (CommonITILObject honours it — see notification gate in
        // post_updateItem). Without it, GLPI emails a ticket-update message
        // *before* the step's new task exists, so it renders with the
        // previous task's content. We only want the task's own add_task
        // notification (which has the correct, current content) to fire.
        $ticket = new \Ticket();
        $ticket->update([
            'id'             => $tickets_id,
            '_actors'        => $actors,
            '_disablenotif'  => true,
        ]);
    }

    /**
     * Instantiate an ITILFollowup on the ticket from an
     * ITILFollowupTemplate. Returns the new followup id, or 0 on failure.
     *
     * Mirrors what GLPI does when a tech picks a followup template from
     * the timeline — content + is_private + requesttypes_id come straight
     * from the template row (`glpi_itilfollowuptemplates`).
     *
     * Best-effort: swallows any exception so a template glitch (deleted
     * template, missing field) doesn't abort the workflow advance. The
     * step itself remains successful even if the followup couldn't be
     * created — the audit log records `itilfollowups_id = 0` for that case.
     */
    public static function applyFollowupTemplate(int $tickets_id, int $template_id): int
    {
        if ($template_id <= 0) {
            return 0;
        }

        try {
            $tpl = new \ITILFollowupTemplate();
            if (!$tpl->getFromDB($template_id)) {
                return 0;
            }

            $content = (string)($tpl->fields['content'] ?? '');
            if (trim(strip_tags($content)) === '') {
                return 0; // Empty template — don't post a blank followup.
            }

            $input = [
                'itemtype'   => 'Ticket',
                'items_id'   => $tickets_id,
                'content'    => $content,
                'is_private' => (int)($tpl->fields['is_private'] ?? 0),
            ];
            // Optional fields the template can carry.
            if (!empty($tpl->fields['requesttypes_id'])) {
                $input['requesttypes_id'] = (int)$tpl->fields['requesttypes_id'];
            }
            if (!empty($tpl->fields['pendingreasons_id'])) {
                $input['pendingreasons_id'] = (int)$tpl->fields['pendingreasons_id'];
            }

            $fup = new \ITILFollowup();
            $new_id = $fup->add($input);
            return $new_id ? (int)$new_id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
