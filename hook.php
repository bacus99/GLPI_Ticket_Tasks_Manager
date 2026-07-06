<?php

/**
 * Tasks Manager - Install/Uninstall hooks
 *
 * @license   GPL-3.0-or-later
 */


/**
 * Install hook - create database tables and initial config
 *
 * @return bool
 */
function plugin_tasksmanager_install(): bool
{
    global $DB;

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_taskstates
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_taskstates')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_taskstates` (
            `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`           INT UNSIGNED NOT NULL DEFAULT 0,
            `tickettasks_id`       INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_status`        VARCHAR(50)  NOT NULL DEFAULT 'pending',
            `priority`             TINYINT      NOT NULL DEFAULT 3,
            `due_date`             TIMESTAMP    NULL DEFAULT NULL,
            `assigned_users_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `assigned_groups_id`   INT UNSIGNED NOT NULL DEFAULT 0,
            `progress`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `notes`                TEXT         NULL,
            `ticket_workflows_id`  INT UNSIGNED NULL DEFAULT NULL,
            `workflow_step_order`  INT UNSIGNED NULL DEFAULT NULL,
            `date_creation`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tickets_id`          (`tickets_id`),
            KEY `tickettasks_id`      (`tickettasks_id`),
            KEY `plugin_status`       (`plugin_status`),
            KEY `assigned_users_id`   (`assigned_users_id`),
            KEY `assigned_groups_id`  (`assigned_groups_id`),
            KEY `due_date`            (`due_date`),
            KEY `ticket_workflows_id` (`ticket_workflows_id`),
            KEY `date_mod`            (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    } else {
        // Upgrade: add workflow tracking columns if missing
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_taskstates', 'ticket_workflows_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_taskstates`
                ADD `ticket_workflows_id` INT UNSIGNED NULL DEFAULT NULL AFTER `notes`,
                ADD `workflow_step_order` INT UNSIGNED NULL DEFAULT NULL AFTER `ticket_workflows_id`,
                ADD KEY `ticket_workflows_id` (`ticket_workflows_id`)");
        }
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_configs
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_configs` (
            `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `key`   VARCHAR(255) NOT NULL,
            `value` TEXT         NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");

        $DB->insert('glpi_plugin_tasksmanager_configs', ['key' => 'default_priority',      'value' => '3']);
        $DB->insert('glpi_plugin_tasksmanager_configs', ['key' => 'enable_notifications',  'value' => '1']);
        $DB->insert('glpi_plugin_tasksmanager_configs', [
            'key'   => 'statuses',
            'value' => json_encode(['pending', 'in_progress', 'blocked', 'review', 'done']),
        ]);
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_workflows
    // Named workflow definitions
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_workflows')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_workflows` (
            `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`                  VARCHAR(255) NOT NULL,
            `description`           TEXT         NULL,
            `is_active`             TINYINT      NOT NULL DEFAULT 1,
            `assign_ticket_to_task` TINYINT      NOT NULL DEFAULT 1,
            `groups_id_completion`  INT UNSIGNED NOT NULL DEFAULT 0,
            `solutiontemplates_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `name`                 (`name`),
            KEY `is_active`            (`is_active`),
            KEY `groups_id_completion` (`groups_id_completion`),
            KEY `solutiontemplates_id` (`solutiontemplates_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    } else {
        // Upgrade: add groups_id_completion if missing
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflows', 'groups_id_completion')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflows`
                ADD `groups_id_completion` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_active`,
                ADD KEY `groups_id_completion` (`groups_id_completion`)");
        }
        // Upgrade: add assign_ticket_to_task if missing
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflows', 'assign_ticket_to_task')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflows`
                ADD `assign_ticket_to_task` TINYINT NOT NULL DEFAULT 1 AFTER `is_active`");
        }
        // 1.7.0: solution template to surface on workflow completion.
        //   0 = no suggestion, >0 = SolutionTemplate id (see glpi_solutiontemplates)
        // Pure suggestion, NOT auto-applied — the tech still has to open
        // GLPI's solution form so all of GLPI's native safeguards
        // ("waiting for approval", "Do you really want to resolve or
        // close it?", etc.) keep firing.
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflows', 'solutiontemplates_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflows`
                ADD `solutiontemplates_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `groups_id_completion`,
                ADD KEY `solutiontemplates_id` (`solutiontemplates_id`)");
        }
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_workflow_steps
    // Ordered task-template steps belonging to a workflow
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_workflow_steps')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_workflow_steps` (
            `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `workflows_id`          INT UNSIGNED NOT NULL DEFAULT 0,
            `tasktemplates_id`      INT UNSIGNED NOT NULL DEFAULT 0,
            `step_order`            INT UNSIGNED NOT NULL DEFAULT 0,
            `description`           TEXT         NULL,
            `next_step_rules`       TEXT         NULL,
            `default_goto_step_id`  INT          NOT NULL DEFAULT 0,
            `sla_duration`          INT          NULL DEFAULT NULL,
            `sla_warning_pct`       TINYINT UNSIGNED NULL DEFAULT 75,
            `sla_breach_action`     VARCHAR(20)  NOT NULL DEFAULT 'notify',
            `sla_breach_groups_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `sla_breach_users_id`   INT UNSIGNED NOT NULL DEFAULT 0,
            `sla_use_calendar`      TINYINT      NOT NULL DEFAULT 0,
            `olas_id`               INT UNSIGNED NOT NULL DEFAULT 0,
            `itilfollowuptemplates_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `assign_groups_id`      INT UNSIGNED NOT NULL DEFAULT 0,
            `assign_users_id`       INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `workflows_id`  (`workflows_id`),
            KEY `step_and_order` (`workflows_id`, `step_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    } else {
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflow_steps', 'description')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflow_steps`
                ADD `description` TEXT NULL AFTER `step_order`");
        }
        // 1.5.0: conditional routing rules (JSON). Empty / null = fall through
        // to the next step by step_order (legacy behaviour).
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflow_steps', 'next_step_rules')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflow_steps`
                ADD `next_step_rules` TEXT NULL AFTER `description`");
        }
        // 1.5.0: else / default target when no rule matches.
        //   0  = sequential next step (legacy)
        //  -1  = end the workflow
        //  >0  = jump to that step id (forward-only; invalid → fall through)
        // Signed INT, because we need -1.
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflow_steps', 'default_goto_step_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflow_steps`
                ADD `default_goto_step_id` INT NOT NULL DEFAULT 0 AFTER `next_step_rules`");
        }
        // 1.8.0: per-step SLA + escalation.
        //   sla_duration       = max seconds the step may stay current
        //                        (NULL = no SLA on this step)
        //   sla_warning_pct    = warn at N% of the budget (NULL/0 = no warning)
        //   sla_breach_action  = notify | reassign | skip | priority_up
        //   sla_breach_groups_id / sla_breach_users_id = reassign targets
        //   sla_use_calendar   = honour the entity's working-hours calendar
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflow_steps', 'sla_duration')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflow_steps`
                ADD `sla_duration`         INT          NULL DEFAULT NULL  AFTER `default_goto_step_id`,
                ADD `sla_warning_pct`      TINYINT UNSIGNED NULL DEFAULT 75 AFTER `sla_duration`,
                ADD `sla_breach_action`    VARCHAR(20)  NOT NULL DEFAULT 'notify' AFTER `sla_warning_pct`,
                ADD `sla_breach_groups_id` INT UNSIGNED NOT NULL DEFAULT 0  AFTER `sla_breach_action`,
                ADD `sla_breach_users_id`  INT UNSIGNED NOT NULL DEFAULT 0  AFTER `sla_breach_groups_id`,
                ADD `sla_use_calendar`     TINYINT      NOT NULL DEFAULT 0  AFTER `sla_breach_users_id`");
        }
        // 1.8.1: optionally source the step's SLA budget + calendar from an
        // existing GLPI OLA instead of the custom sla_duration. 0 = custom.
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflow_steps', 'olas_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflow_steps`
                ADD `olas_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `sla_use_calendar`");
        }
        // 1.10.0: optional ITILFollowupTemplate ("answer template") that
        // auto-creates a templated followup on the ticket when the step
        // starts — paired with the existing TaskTemplate-driven task
        // creation. 0 = no answer.
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflow_steps', 'itilfollowuptemplates_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflow_steps`
                ADD `itilfollowuptemplates_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `olas_id`");
        }
        // 1.11.1: team a step assigns the ticket to. Task steps normally take
        // their team from the task template; a FOLLOW-UP-ONLY step has no
        // template, so it carries its own assign target here. The ticket is
        // reassigned to this group/user BEFORE the follow-up is posted, so
        // the follow-up notification reaches the new team (not the previous
        // task's team). 0 = leave the ticket's assignment unchanged.
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_workflow_steps', 'assign_groups_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_workflow_steps`
                ADD `assign_groups_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `itilfollowuptemplates_id`,
                ADD `assign_users_id`  INT UNSIGNED NOT NULL DEFAULT 0 AFTER `assign_groups_id`");
        }
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_ticket_workflows
    // Active workflow instance linked to a specific ticket
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_ticket_workflows')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_ticket_workflows` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `workflows_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `current_step`  INT UNSIGNED NOT NULL DEFAULT 0,
            `status`        VARCHAR(20)  NOT NULL DEFAULT 'active',
            `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tickets_id`   (`tickets_id`),
            KEY `workflows_id` (`workflows_id`),
            KEY `status`       (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_pending_workflows
    // Holds a desired workflow for a ticket that is awaiting approval.
    // Consumed and deleted once the approval is granted.
    // -------------------------------------------------------
    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_workflow_events
    // Audit log: every advance / complete / apply / remove with who, when,
    // and a JSON details payload (from/to step, group changes, etc.)
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_workflow_events')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_workflow_events` (
            `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`          INT UNSIGNED NOT NULL DEFAULT 0,
            `workflows_id`        INT UNSIGNED NOT NULL DEFAULT 0,
            `ticket_workflows_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `step_order`          INT UNSIGNED NULL DEFAULT NULL,
            `event_type`          VARCHAR(40)  NOT NULL,
            `users_id`            INT UNSIGNED NOT NULL DEFAULT 0,
            `details`             TEXT         NULL,
            `date_creation`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tickets_id`          (`tickets_id`),
            KEY `workflows_id`        (`workflows_id`),
            KEY `ticket_workflows_id` (`ticket_workflows_id`),
            KEY `event_type`          (`event_type`),
            KEY `date_creation`       (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    if (!$DB->tableExists('glpi_plugin_tasksmanager_pending_workflows')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_pending_workflows` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`    INT UNSIGNED NOT NULL,
            `workflows_id`  INT UNSIGNED NOT NULL,
            `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tickets_id` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    // Create plugin document directory
    $plugin_doc_dir = GLPI_PLUGIN_DOC_DIR . '/tasksmanager';
    if (!is_dir($plugin_doc_dir)) {
        mkdir($plugin_doc_dir, 0755, true);
    }

    // Register plugin rights and grant them to super-admin
    \GlpiPlugin\Tasksmanager\Profile::install();

    // Register the per-step SLA cron (5-min sweep). Idempotent —
    // CronTask::register no-ops if the task already exists.
    \CronTask::register(
        \GlpiPlugin\Tasksmanager\Sla::class,
        'WorkflowSla',
        \GlpiPlugin\Tasksmanager\Sla::CRON_FREQUENCY,
        [
            'state'    => \CronTask::STATE_WAITING,
            'mode'     => \CronTask::MODE_EXTERNAL,
            'comment'  => 'Tasks Manager per-step SLA & escalation',
        ]
    );

    return true;
}

/**
 * Uninstall hook - remove database tables and files
 *
 * @return bool
 */
function plugin_tasksmanager_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_tasksmanager_workflow_events',
        'glpi_plugin_tasksmanager_pending_workflows',
        'glpi_plugin_tasksmanager_ticket_workflows',
        'glpi_plugin_tasksmanager_workflow_steps',
        'glpi_plugin_tasksmanager_workflows',
        'glpi_plugin_tasksmanager_taskstates',
        'glpi_plugin_tasksmanager_configs',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $plugin_doc_dir = GLPI_PLUGIN_DOC_DIR . '/tasksmanager';
    if (is_dir($plugin_doc_dir)) {
        Toolbox::deleteDir($plugin_doc_dir);
    }

    // Drop plugin rights from every profile
    \GlpiPlugin\Tasksmanager\Profile::uninstall();

    // Remove our cron task(s) — matches GlpiPlugin\Tasksmanager\* itemtypes.
    \CronTask::unregister('Tasksmanager');

    return true;
}

// -------------------------------------------------------
// Hook callbacks for ticket task events
// -------------------------------------------------------

/**
 * Callback when a Ticket is added.
 * - No approval required  → apply the workflow immediately.
 * - Approval required     → store in pending_workflows; applied when approval is granted.
 */
function plugin_tasksmanager_ticket_add(Ticket $item): void
{
    $workflows_id = (int)($item->input['_plugin_tasksmanager_workflows_id'] ?? 0);
    if (!$workflows_id) {
        return;
    }

    global $DB;
    $tickets_id = $item->getID();

    // Store pending record (consumed by ticket_update when approval is granted)
    $DB->insert('glpi_plugin_tasksmanager_pending_workflows', [
        'tickets_id'   => $tickets_id,
        'workflows_id' => $workflows_id,
    ]);

    // Decide: apply now vs. wait for approval.
    //
    // $item->fields['global_validation'] can be stale here — when the ticket
    // is created via a Forms destination with ValidationField, the validation
    // request is created in post_addItem and the parent ticket's
    // global_validation may not yet reflect WAITING by the time our hook
    // runs. Three checks to be safe:
    //   1. Re-read global_validation directly from the DB.
    //   2. Count waiting TicketValidation rows (the request itself).
    //   3. Look at the prepared input for a `_validation` payload that
    //      Forms uses to schedule validations.
    // Any one of them indicating "approval needed" defers the apply.
    $validation_status = \CommonITILValidation::NONE;
    $reread = $DB->request([
        'SELECT' => ['global_validation'],
        'FROM'   => 'glpi_tickets',
        'WHERE'  => ['id' => $tickets_id],
        'LIMIT'  => 1,
    ]);
    if (count($reread) > 0) {
        $validation_status = (int)($reread->current()['global_validation'] ?? \CommonITILValidation::NONE);
    }

    $pending_validations = 0;
    if ($DB->tableExists('glpi_ticketvalidations')) {
        $pv = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => 'glpi_ticketvalidations',
            'WHERE' => ['tickets_id' => $tickets_id, 'status' => \CommonITILValidation::WAITING],
        ]);
        if (count($pv) > 0) {
            $pending_validations = (int)$pv->current()['cnt'];
        }
    }

    // Permissive check: any input key whose name contains "validation" /
    // "approval" is a strong hint that an approval request is being set up.
    // This catches GLPI 11 Forms (`_validation_targets`), legacy
    // (`_add_validation`), and any future / plugin-added keys we don't
    // know about yet.
    $validation_keys_seen = [];
    foreach (array_keys((array)($item->input ?? [])) as $k) {
        $lk = strtolower((string)$k);
        if (str_contains($lk, 'validation') || str_contains($lk, 'approval')) {
            // Only treat as a signal when the value is non-empty (Forms
            // sets `_add_validation = 0` as an unconditional marker).
            $val = $item->input[$k] ?? null;
            if (!empty($val)) {
                $validation_keys_seen[] = $k;
            }
        }
    }
    $input_has_validation = !empty($validation_keys_seen);

    $needs_approval = $validation_status !== \CommonITILValidation::NONE
                   || $pending_validations > 0
                   || $input_has_validation;

    // Audit: record the full diagnostic so we can see *exactly* which
    // signals fired (or didn't) for this ticket. The `input_keys` snapshot
    // is invaluable when a ticket created via a form path we didn't
    // anticipate slips through the gate.
    \GlpiPlugin\Tasksmanager\Workflow::logEvent(
        $needs_approval ? 'workflow_pending' : 'workflow_applied_immediate',
        $tickets_id,
        $workflows_id,
        0,
        null,
        [
            'global_validation'    => $validation_status,
            'pending_validations'  => $pending_validations,
            'input_has_validation' => $input_has_validation,
            'validation_keys_seen' => $validation_keys_seen,
            'input_keys'           => array_keys((array)($item->input ?? [])),
            'decision'             => $needs_approval ? 'defer' : 'apply',
        ]
    );

    if (!$needs_approval) {
        plugin_tasksmanager_apply_pending_workflow($tickets_id);
    }
    // Otherwise leave the pending record — plugin_tasksmanager_ticket_update
    // will consume it when global_validation transitions to ACCEPTED.

    // Belt-and-suspenders: ALSO register a shutdown-time check. By the time
    // PHP shuts down for this request, all Forms destination processing
    // (including ValidationField → TicketValidation row inserts and the
    // parent's global_validation update) has completed. If the synchronous
    // checks above all returned "no approval needed" for a ticket that
    // actually did get a validation set up later in post-add, the
    // shutdown re-check will be authoritative.
    //
    // Safe to run alongside the synchronous apply because
    // Workflow::applyToTicket() refuses to create a second active workflow.
    register_shutdown_function('plugin_tasksmanager_recheck_pending_apply', $tickets_id);
}

/**
 * End-of-request safety net. Runs after PHP shutdown, when all post-add
 * processing is durable in the DB. Reads the live state and:
 *   - applies the pending workflow if no approval is pending and one isn't
 *     already active, or
 *   - leaves the pending record alone if an approval is now visible (the
 *     ticket_update hook will pick it up on ACCEPT)
 */
function plugin_tasksmanager_recheck_pending_apply(int $tickets_id): void
{
    global $DB;

    if (!$DB || !$tickets_id) {
        return;
    }

    // Pending record still there?
    $pending = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_pending_workflows',
        'WHERE' => ['tickets_id' => $tickets_id],
        'LIMIT' => 1,
    ]);
    if (count($pending) === 0) {
        return; // Already consumed (or never existed)
    }

    // Already has an active workflow?
    $active = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
        'WHERE' => ['tickets_id' => $tickets_id, 'status' => 'active'],
        'LIMIT' => 1,
    ]);
    if (count($active) > 0) {
        return; // Workflow already running — nothing to do
    }

    // Final authoritative read of validation state.
    $gv = \CommonITILValidation::NONE;
    $row = $DB->request([
        'SELECT' => ['global_validation'],
        'FROM'   => 'glpi_tickets',
        'WHERE'  => ['id' => $tickets_id],
        'LIMIT'  => 1,
    ]);
    if (count($row) > 0) {
        $gv = (int)($row->current()['global_validation'] ?? \CommonITILValidation::NONE);
    }

    $waiting = 0;
    if ($DB->tableExists('glpi_ticketvalidations')) {
        $pv = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => 'glpi_ticketvalidations',
            'WHERE' => ['tickets_id' => $tickets_id, 'status' => \CommonITILValidation::WAITING],
        ]);
        if (count($pv) > 0) {
            $waiting = (int)$pv->current()['cnt'];
        }
    }

    $needs_approval = $gv !== \CommonITILValidation::NONE || $waiting > 0;

    // Audit the shutdown-time decision so a divergence from the
    // synchronous check is visible in the History card.
    $row_pending = $pending->current();
    $workflows_id = (int)($row_pending['workflows_id'] ?? 0);
    \GlpiPlugin\Tasksmanager\Workflow::logEvent(
        $needs_approval ? 'workflow_pending_recheck' : 'workflow_applied_recheck',
        $tickets_id,
        $workflows_id,
        0,
        null,
        [
            'global_validation_at_shutdown'  => $gv,
            'waiting_validations_at_shutdown'=> $waiting,
            'decision'                       => $needs_approval ? 'defer' : 'apply',
        ]
    );

    if (!$needs_approval) {
        plugin_tasksmanager_apply_pending_workflow($tickets_id);
    }
}

/**
 * Callback when a Ticket is updated.
 * Applies the pending workflow as soon as global_validation changes to ACCEPTED.
 */
function plugin_tasksmanager_ticket_update(Ticket $item): void
{
    if (!array_key_exists('global_validation', $item->oldvalues ?? [])) {
        return;
    }
    if ((int)$item->fields['global_validation'] !== \CommonITILValidation::ACCEPTED) {
        return;
    }
    plugin_tasksmanager_apply_pending_workflow($item->getID());
}

/**
 * Consume a pending workflow record and apply it to the ticket.
 */
function plugin_tasksmanager_apply_pending_workflow(int $tickets_id): void
{
    global $DB;

    $iter = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_pending_workflows',
        'WHERE' => ['tickets_id' => $tickets_id],
        'LIMIT' => 1,
    ]);
    if (count($iter) === 0) {
        return;
    }

    $row = $iter->current();
    $DB->delete('glpi_plugin_tasksmanager_pending_workflows', ['tickets_id' => $tickets_id]);
    \GlpiPlugin\Tasksmanager\Workflow::applyToTicket($tickets_id, (int)$row['workflows_id']);
}

/**
 * Callback when a TicketTask is added — create a basic taskstate record.
 */
function plugin_tasksmanager_item_add(TicketTask $item): void
{
    global $DB;

    // Skip if a taskstate was already created by the workflow engine
    $existing = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_taskstates',
        'WHERE' => ['tickettasks_id' => $item->getID()],
        'LIMIT' => 1,
    ]);
    if (count($existing) > 0) {
        return;
    }

    $DB->insert('glpi_plugin_tasksmanager_taskstates', [
        'tickets_id'     => $item->fields['tickets_id'],
        'tickettasks_id' => $item->getID(),
        'plugin_status'  => 'pending',
        'priority'       => 3,
        'date_creation'  => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Callback when a TicketTask is updated.
 * When a task is marked done (state=2) and it belongs to a workflow step,
 * this automatically creates the next step's task on the ticket and replaces
 * the ticket's ASSIGN actors with the new task's template tech/group.
 * Paired with js/workflow-refresh.js which reloads the page after the inline
 * checkbox XHR succeeds, so the user can't click Save with stale form data.
 */
function plugin_tasksmanager_item_update(TicketTask $item): void
{
    global $DB;

    // Skip auto-advance when an admin action (Skip / Restart) is in progress
    // — that action marks the task Done itself and drives the advance, so
    // letting this hook also advance produces a duplicate step.
    if (\GlpiPlugin\Tasksmanager\Workflow::$suppressAutoAdvance) {
        return;
    }

    // Only act when state is explicitly being set to 2 in this specific update.
    // Do NOT fall back to $item->fields['state']: that would fire again on a
    // ticket Save that re-submits the task when it is already done.
    if (!isset($item->input['state']) || (int)$item->input['state'] !== 2) {
        return;
    }

    // Sync our internal status tracking for the dashboard
    $DB->update(
        'glpi_plugin_tasksmanager_taskstates',
        ['plugin_status' => 'done', 'progress' => 100],
        ['tickettasks_id' => $item->getID()]
    );

    // Check whether this task belongs to an active workflow step
    $taskstate_iter = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_taskstates',
        'WHERE' => [
            'tickettasks_id'      => $item->getID(),
            'ticket_workflows_id' => ['>', 0],
        ],
        'LIMIT' => 1,
    ]);
    if (count($taskstate_iter) === 0) {
        return;
    }

    $taskstate           = $taskstate_iter->current();
    $ticket_workflows_id = (int)$taskstate['ticket_workflows_id'];
    $current_step_order  = (int)$taskstate['workflow_step_order'];

    // Load the ticket workflow record and verify it is still active
    $tw_iter = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
        'WHERE' => ['id' => $ticket_workflows_id, 'status' => 'active'],
        'LIMIT' => 1,
    ]);
    if (count($tw_iter) === 0) {
        return;
    }

    $ticket_workflow = $tw_iter->current();
    $workflows_id    = (int)$ticket_workflow['workflows_id'];
    $tickets_id      = (int)$ticket_workflow['tickets_id'];

    // Double-advance guard: if current_step is already past this task's step,
    // a prior update has already advanced (e.g. checkbox XHR before a Save).
    if ((int)$ticket_workflow['current_step'] > $current_step_order) {
        return;
    }

    // Advance: resolve the next step (routing rules + else/default target),
    // apply it, or complete the workflow. This logic lives in
    // Workflow::advanceFrom since 1.10.1 so that non-blocking steps
    // (Information / Done task templates — nothing for a human to tick)
    // can chain through the exact same path, including the completion
    // handling (status, completion group, refresh header, audit event)
    // that used to be inlined here.
    \GlpiPlugin\Tasksmanager\Workflow::advanceFrom(
        $ticket_workflows_id,
        $tickets_id,
        $workflows_id,
        $current_step_order
    );
}

/**
 * Thin wrapper so legacy callers and the AJAX endpoint can use the class method.
 */
function plugin_tasksmanager_apply_workflow_step(int $tickets_id, array $step, int $ticket_workflows_id): bool
{
    return \GlpiPlugin\Tasksmanager\Workflow::applyStep($tickets_id, $step, $ticket_workflows_id);
}

/**
 * Timeline-actions hook callback.
 *
 * Renders a "Recommended solution" button into the ticket / change /
 * problem timeline footer (alongside GLPI's native Add buttons) when:
 *   - the ITIL item is a Ticket
 *   - it has a `completed` workflow row in ticket_workflows
 *   - that workflow has a `solutiontemplates_id > 0` configured
 *   - the SolutionTemplate row still exists
 *
 * Clicking the button calls `window.tmOpenSolutionWithTemplate(id, name)`
 * (exposed by public/js/workflow-refresh.js), which opens GLPI's standard
 * solution form and pre-selects the template via Select2 — same as the
 * "Use this template" button on our Workflow tab banner. We never call
 * `ITILSolution::add()` ourselves, so every native GLPI safeguard
 * ("waiting for approval", "do you really want to resolve…", validation
 * gates) stays in the flow.
 *
 * @param array $params Hook params (item, rand).
 */
function plugin_tasksmanager_timeline_actions(array $params): void
{
    global $DB;

    $item = $params['item'] ?? null;
    if (!($item instanceof \Ticket)) {
        return;
    }

    $tickets_id = (int)$item->getID();
    if ($tickets_id <= 0) {
        return;
    }

    // Suppress when the ticket is already solved or closed — the solution
    // has either been applied or the ticket is past the point of
    // accepting one, so nudging the tech toward "Add solution" would be
    // misleading. Uses CommonITILObject's status constants (SOLVED=5,
    // CLOSED=6) so the check survives any future enum renumbering.
    $status = (int)($item->fields['status'] ?? 0);
    if (
        $status === \CommonITILObject::SOLVED
        || $status === \CommonITILObject::CLOSED
    ) {
        return;
    }

    // Most recent completed workflow on this ticket that has a suggested
    // solution template configured. If multiple workflows have run
    // historically, the latest one's template wins — usually what the
    // tech wants.
    $iter = $DB->request([
        'SELECT'    => [
            'wf.solutiontemplates_id',
            'st.name AS st_name',
        ],
        'FROM'      => 'glpi_plugin_tasksmanager_ticket_workflows AS tw',
        'LEFT JOIN' => [
            'glpi_plugin_tasksmanager_workflows AS wf' =>
                ['ON' => ['tw' => 'workflows_id', 'wf' => 'id']],
            'glpi_solutiontemplates AS st' =>
                ['ON' => ['wf' => 'solutiontemplates_id', 'st' => 'id']],
        ],
        'WHERE' => [
            'tw.tickets_id' => $tickets_id,
            'tw.status'     => 'completed',
            'wf.solutiontemplates_id' => ['>', 0],
        ],
        'ORDER' => ['tw.date_mod DESC'],
        'LIMIT' => 1,
    ]);

    if (count($iter) === 0) {
        return;
    }
    $row = $iter->current();
    $st_id   = (int)$row['solutiontemplates_id'];
    $st_name = (string)($row['st_name'] ?? '');
    if ($st_id <= 0 || $st_name === '') {
        return;
    }

    // Render — output goes inline into the footer template's flow at the
    // end of `<ul class="legacy-timeline-actions">` (the default position
    // for TIMELINE_ACTIONS-hook output in GLPI 11). Wrapped in <li> for
    // valid HTML inside the parent <ul>.
    //
    // Colour: rgb(200, 234, 253) (very light pastel blue) per UX request.
    // Text is set to a dark slate (#0b3b58) for AA-level contrast against
    // the pale background. A subtle pulsing animation draws the tech's
    // attention without being aggressive (1.6s cycle, ≤ 1Hz — safely
    // below the WCAG 2.3.1 flash threshold).
    $label = htmlspecialchars(__('Recommended solution', 'tasksmanager'), ENT_QUOTES);
    $title = htmlspecialchars(
        sprintf(
            __('Open the solution form with the workflow\'s suggested template "%s" pre-selected. GLPI\'s native warnings still apply when you save.', 'tasksmanager'),
            $st_name
        ),
        ENT_QUOTES
    );
    $name_attr = htmlspecialchars($st_name, ENT_QUOTES);

    echo '<li class="tm-recommended-solution-li" style="list-style:none;display:inline-block">';
    echo '<button type="button"'
        . ' class="btn ms-2 tm-btn-recommended-solution tm-pulse"'
        . ' id="tm-btn-recommended-solution-' . $tickets_id . '"'
        . ' data-tm-tpl-id="' . $st_id . '"'
        . ' data-tm-tpl-name="' . $name_attr . '"'
        . ' title="' . $title . '">'
        . '<i class="ti ti-file-text me-1"></i>' . $label
        . '</button>';
    echo '</li>';

    // Inline click handler. Uses the shared helper exposed by
    // workflow-refresh.js; falls back to a corner toast if the helper
    // can't find GLPI's solution block.
    $toast_label = json_encode(sprintf(__('Pick this solution template: %s', 'tasksmanager'), $st_name));
    echo '<script>(function(){'
        . 'const btn = document.getElementById("tm-btn-recommended-solution-' . $tickets_id . '");'
        . 'if (!btn || btn.dataset.tmBound) return;'
        . 'btn.dataset.tmBound = "1";'
        . 'btn.addEventListener("click", function () {'
        .   'const id   = parseInt(btn.dataset.tmTplId, 10) || 0;'
        .   'const name = btn.dataset.tmTplName || "";'
        // Stop the pulse once the tech has acknowledged the suggestion —
        // single deliberate click is what the animation was nudging
        // toward, no reason to keep flashing afterwards.
        .   'btn.classList.remove("tm-pulse");'
        .   'const ok = (typeof window.tmOpenSolutionWithTemplate === "function")'
        .              ' && window.tmOpenSolutionWithTemplate(id, name);'
        .   'if (ok) return;'
        .   'const t = document.createElement("div");'
        .   't.className = "alert alert-info shadow-lg position-fixed";'
        .   't.style.cssText = "bottom:24px;right:24px;z-index:10000;max-width:380px;cursor:pointer";'
        .   't.textContent = ' . $toast_label . ';'
        .   't.addEventListener("click", () => t.remove());'
        .   'document.body.appendChild(t);'
        .   'setTimeout(() => { if (t.parentNode) t.remove(); }, 6000);'
        . '});'
        . '})();</script>';

    // Scoped styles. Two pieces:
    //   1. Base look of the button (pale blue + dark slate text).
    //   2. tm-pulse keyframe animation — alternates background brightness
    //      and a soft halo box-shadow at ~0.6Hz. The `prefers-reduced-
    //      motion` media query disables the animation for users who've
    //      requested calmer UI (WCAG 2.3.3 / browser accessibility setting).
    echo '<style>'
        . '.tm-btn-recommended-solution {'
        .   'background-color:rgb(200, 234, 253) !important;'
        .   'border-color:rgb(200, 234, 253) !important;'
        .   'color:#0b3b58 !important;'
        .   'font-weight:500;'
        . '}'
        . '.tm-btn-recommended-solution:hover, .tm-btn-recommended-solution:focus {'
        .   'background-color:rgb(170, 218, 246) !important;'
        .   'border-color:rgb(170, 218, 246) !important;'
        .   'color:#0b3b58 !important;'
        . '}'
        . '@keyframes tm-pulse {'
        .   '0%, 100% {'
        .     'background-color:rgb(200, 234, 253);'
        .     'box-shadow:0 0 0 0 rgba(81, 174, 219, 0.55);'
        .   '}'
        .   '50% {'
        .     'background-color:rgb(170, 218, 246);'
        .     'box-shadow:0 0 0 8px rgba(81, 174, 219, 0);'
        .   '}'
        . '}'
        . '.tm-btn-recommended-solution.tm-pulse {'
        .   'animation:tm-pulse 1.6s ease-in-out infinite;'
        . '}'
        . '@media (prefers-reduced-motion: reduce) {'
        .   '.tm-btn-recommended-solution.tm-pulse { animation:none; }'
        . '}'
        . '</style>';
}

