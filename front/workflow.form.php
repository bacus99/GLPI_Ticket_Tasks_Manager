<?php

/**
 * Tasks Manager - Workflow editor
 */

use GlpiPlugin\Tasksmanager\Workflow;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    Html::displayNotFoundError();
}

Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

global $DB;

$workflow_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_new      = ($workflow_id === 0);

// ── Save workflow metadata ─────────────────────────────────────────────────
if (isset($_POST['save_workflow'])) {
    $name                  = trim($_POST['name'] ?? '');
    $is_active             = isset($_POST['is_active'])             ? 1 : 0;
    $assign_ticket_to_task = isset($_POST['assign_ticket_to_task']) ? 1 : 0;
    $groups_id_completion  = (int)($_POST['groups_id_completion'] ?? 0);
    $solutiontemplates_id  = (int)($_POST['solutiontemplates_id']  ?? 0);

    if ($name === '') {
        Session::addMessageAfterRedirect(__('Name is required.', 'tasksmanager'), true, ERROR);
        Html::redirect($_SERVER['REQUEST_URI']);
    }

    if ($is_new) {
        $DB->insert('glpi_plugin_tasksmanager_workflows', [
            'name'                  => $name,
            'is_active'             => $is_active,
            'assign_ticket_to_task' => $assign_ticket_to_task,
            'groups_id_completion'  => $groups_id_completion,
            'solutiontemplates_id'  => $solutiontemplates_id,
            'date_creation'         => date('Y-m-d H:i:s'),
        ]);
        $workflow_id = $DB->insertId();
        Session::addMessageAfterRedirect(__('Workflow created.', 'tasksmanager'), true, INFO);
        Html::redirect('workflow.form.php?id=' . $workflow_id);
    } else {
        $DB->update('glpi_plugin_tasksmanager_workflows',
            [
                'name'                  => $name,
                'is_active'             => $is_active,
                'assign_ticket_to_task' => $assign_ticket_to_task,
                'groups_id_completion'  => $groups_id_completion,
                'solutiontemplates_id'  => $solutiontemplates_id,
            ],
            ['id' => $workflow_id]
        );
        Session::addMessageAfterRedirect(__('Workflow saved.', 'tasksmanager'), true, INFO);
        Html::redirect($_SERVER['REQUEST_URI']);
    }
}

// ── Load existing workflow ─────────────────────────────────────────────────
if (!$is_new) {
    $wf_iter = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_workflows',
        'WHERE' => ['id' => $workflow_id],
        'LIMIT' => 1,
    ]);
    if (count($wf_iter) === 0) {
        Html::displayNotFoundError();
    }
    $wf_data = $wf_iter->current();

    $steps = iterator_to_array($DB->request([
        'SELECT'    => [
            'wfs.id', 'wfs.step_order', 'wfs.tasktemplates_id',
            'wfs.next_step_rules', 'wfs.default_goto_step_id',
            'wfs.sla_duration', 'wfs.sla_warning_pct', 'wfs.sla_breach_action',
            'wfs.sla_breach_groups_id', 'wfs.sla_breach_users_id', 'wfs.sla_use_calendar',
            'wfs.olas_id',
            'wfs.itilfollowuptemplates_id',
            'wfs.assign_groups_id',
            'tt.name AS tpl_name',
            'tt.comment AS tpl_comment',
        ],
        'FROM'      => 'glpi_plugin_tasksmanager_workflow_steps AS wfs',
        'LEFT JOIN' => [
            'glpi_tasktemplates AS tt' => ['ON' => ['wfs' => 'tasktemplates_id', 'tt' => 'id']],
        ],
        'WHERE' => ['wfs.workflows_id' => $workflow_id],
        'ORDER' => ['wfs.step_order ASC'],
    ]));
    // Re-key so $i is positional (the iterator preserves row IDs as keys).
    $steps = array_values($steps);
} else {
    $wf_data = [
        'name'                  => '',
        'is_active'             => 1,
        'assign_ticket_to_task' => 1,
        'groups_id_completion'  => 0,
        'solutiontemplates_id'  => 0,
    ];
    $steps   = [];
}

// Computed once regardless of branch: the URL the task-template links
// resolve to. Previously only set inside the !$is_new block, which
// produced "Undefined variable $tasktemplate_base_url" warnings when
// rendering the "new workflow" page (no existing steps to link, but
// the template references the var unconditionally further down).
$tasktemplate_base_url = TaskTemplate::getFormURL();

// All task templates for the "add step" dropdown
$all_templates = iterator_to_array($DB->request([
    'FROM'  => 'glpi_tasktemplates',
    'ORDER' => ['name ASC'],
]));

// Assignable groups for the SLA "reassign on breach" picker. Emitted once
// into a JS array and reused by every step's SLA panel (existing + newly
// added), so we avoid per-step AJAX Select2 widgets that don't clone well.
$assign_groups = [];
foreach ($DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => 'glpi_groups',
    'WHERE'  => ['is_assign' => 1],
    'ORDER'  => ['name ASC'],
]) as $g) {
    $assign_groups[] = ['id' => (int)$g['id'], 'name' => (string)$g['name']];
}

// ITILFollowupTemplate list for the per-step "answer template" picker.
// Used to drop a templated followup on the ticket when the step starts.
$fup_templates = [];
$fup_name_by_id = [];
if ($DB->tableExists('glpi_itilfollowuptemplates')) {
    foreach ($DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_itilfollowuptemplates',
        'ORDER'  => ['name ASC'],
    ]) as $t) {
        $fup_templates[] = ['id' => (int)$t['id'], 'name' => (string)$t['name']];
        $fup_name_by_id[(int)$t['id']] = (string)$t['name'];
    }
}

// GLPI OLAs (operational level agreements) for the "Duration source =
// Service Level" option. We surface name + a human duration so the admin
// can pick the right one. TTR-type OLAs are the natural fit for a step
// deadline, but we list all and label the type.
$olas_list = [];
if ($DB->tableExists('glpi_olas')) {
    foreach ($DB->request([
        'SELECT' => ['id', 'name', 'type', 'number_time', 'definition_time'],
        'FROM'   => 'glpi_olas',
        'ORDER'  => ['name ASC'],
    ]) as $o) {
        $olas_list[] = [
            'id'    => (int)$o['id'],
            'name'  => (string)$o['name'],
            // 0 = TTO (time to own), 1 = TTR (time to resolve)
            'type'  => (int)$o['type'] === 1 ? 'TTR' : 'TTO',
            'num'   => (int)$o['number_time'],
            'unit'  => (string)$o['definition_time'],
        ];
    }
}

// Reusable SLA toggle + panel markup. Values are NOT baked in here — each
// step carries its config in a data-sla attribute, and renderSlaInto()
// (JS) populates these inputs on first open. Emitting the markup once and
// reusing it for both PHP-rendered existing steps and the JS add-template
// keeps the two in lockstep.
ob_start();
?>
<button type="button" class="btn btn-sm btn-link p-0 text-decoration-none tm-toggle-sla ms-2"
        onclick="tmToggleSla(this)">
    <i class="ti ti-chevron-right me-1"></i>
    <span class="tm-sla-label"><?= __('SLA', 'tasksmanager') ?></span>
</button>
<div class="tm-flow-sla" style="display:none">
    <div class="d-flex flex-wrap gap-1 align-items-center">
        <span class="small text-muted"><?= __('Duration source', 'tasksmanager') ?></span>
        <select class="form-select form-select-sm tm-sla-source" style="max-width:180px"
                onchange="tmSlaSourceChanged(this)">
            <option value="custom"><?= __('Custom', 'tasksmanager') ?></option>
            <option value="ola"><?= __('GLPI Service Level (OLA)', 'tasksmanager') ?></option>
        </select>
        <select class="form-select form-select-sm tm-sla-ola" style="max-width:260px;display:none"
                onchange="tmSaveSla(this)"></select>
    </div>
    <div class="d-flex flex-wrap gap-1 align-items-center mt-1 tm-sla-custom-only">
        <span class="small text-muted"><?= __('Max duration', 'tasksmanager') ?></span>
        <input type="number" min="0" class="form-control form-control-sm tm-sla-amount"
               style="max-width:90px" onchange="tmSaveSla(this)">
        <select class="form-select form-select-sm tm-sla-unit" style="max-width:120px"
                onchange="tmSaveSla(this)">
            <option value="60"><?= __('minutes', 'tasksmanager') ?></option>
            <option value="3600"><?= __('hours', 'tasksmanager') ?></option>
            <option value="86400"><?= __('days', 'tasksmanager') ?></option>
        </select>
    </div>
    <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
        <span class="small text-muted"><?= __('Warn at', 'tasksmanager') ?></span>
        <input type="number" min="0" max="100" class="form-control form-control-sm tm-sla-warn"
               style="max-width:70px" onchange="tmSaveSla(this)">
        <span class="small text-muted">%</span>
    </div>
    <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
        <span class="small text-muted"><?= __('On breach', 'tasksmanager') ?></span>
        <select class="form-select form-select-sm tm-sla-action" style="max-width:190px"
                onchange="tmSlaActionChanged(this)">
            <option value="notify"><?= __('notify (add followup)', 'tasksmanager') ?></option>
            <option value="reassign"><?= __('reassign to group', 'tasksmanager') ?></option>
            <option value="skip"><?= __('skip step', 'tasksmanager') ?></option>
            <option value="priority_up"><?= __('raise priority', 'tasksmanager') ?></option>
        </select>
        <select class="form-select form-select-sm tm-sla-group" style="max-width:200px;display:none"
                onchange="tmSaveSla(this)"></select>
    </div>
    <div class="form-check mt-1 tm-sla-custom-only">
        <input type="checkbox" class="form-check-input tm-sla-cal" onchange="tmSaveSla(this)">
        <label class="form-check-label small">
            <?= __('Count working hours only (entity calendar)', 'tasksmanager') ?>
        </label>
    </div>
    <div class="text-muted small mt-1">
        <i class="ti ti-info-circle me-1"></i>
        <?= __('Set duration to 0 (or no Service Level) to disable the SLA for this step. A background task checks every 5 minutes.', 'tasksmanager') ?>
        <span class="tm-sla-status ms-2"></span>
    </div>
</div>
<?php
$sla_block_html = ob_get_clean();

// Per-step ITILFollowupTemplate picker — sibling of the SLA block, reused
// by both PHP-rendered existing steps and the JS add-template. Inline (no
// collapse) because it's a single dropdown.
ob_start();
?>
<div class="d-flex flex-wrap gap-1 align-items-center mt-2 pt-2 border-top">
    <span class="small text-muted"><i class="ti ti-message-circle me-1"></i><?= __('Answer template', 'tasksmanager') ?></span>
    <select class="form-select form-select-sm tm-fup-tpl" style="max-width:260px"
            onchange="tmSaveFupTpl(this)">
        <!-- options populated by JS from TM_FUP_TEMPLATES -->
    </select>
    <span class="tm-fup-status text-muted small ms-2"></span>
    <div class="text-muted small w-100">
        <i class="ti ti-info-circle me-1"></i>
        <?= __('Optional. When set, an ITILFollowup is auto-created on the ticket with this template\'s content each time the step starts — for example to send a standard status update to the requester.', 'tasksmanager') ?>
    </div>
</div>
<?php
$fup_block_html = ob_get_clean();

$ajax_url = Plugin::getWebDir('tasksmanager') . '/ajax/workflow.php';

Html::header(
    $is_new ? __('New Workflow', 'tasksmanager') : __('Edit Workflow', 'tasksmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    Workflow::class
);

// Ensure SortableJS is available for the flowchart drag-reorder.
// Loads from jsDelivr (fast, no install footprint) with a fallback to the
// GLPI-bundled copy (path varies by install — see GLPI-Shared conventions).
global $CFG_GLPI;
?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"
        crossorigin="anonymous"
        onerror="(function(){var s=document.createElement('script');s.src='<?= $CFG_GLPI['root_doc'] ?>/public/lib/sortablejs.js';document.head.appendChild(s);})();"></script>
<?php
?>
<div class="container-fluid mt-3" style="max-width:800px">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="ti ti-git-branch me-2"></i>
            <?= $is_new ? __('New Workflow', 'tasksmanager') : htmlspecialchars($wf_data['name']) ?>
        </h2>
        <a href="workflow.list.php" class="btn btn-outline-secondary btn-sm">
            <i class="ti ti-arrow-left me-1"></i><?= __('Back to list', 'tasksmanager') ?>
        </a>
    </div>

    <!-- Workflow metadata -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><?= __('Workflow details', 'tasksmanager') ?></h5></div>
        <div class="card-body">
            <form method="post" action="">
                <input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">
                <div class="mb-3">
                    <label class="form-label fw-bold" for="wf-name">
                        <?= __('Name') ?> <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="wf-name" name="name" class="form-control"
                           value="<?= htmlspecialchars($wf_data['name']) ?>" required>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="wf-active" name="is_active"
                           <?= $wf_data['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wf-active"><?= __('Active') ?></label>
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="wf-assign-ticket"
                           name="assign_ticket_to_task"
                           <?= !empty($wf_data['assign_ticket_to_task']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wf-assign-ticket">
                        <?= __('Assign the ticket to each step\'s task team', 'tasksmanager') ?>
                    </label>
                    <div class="text-muted small">
                        <?= __('When checked, advancing the workflow swaps the ticket\'s assigned tech/group to match the new step\'s task template. Uncheck to leave the ticket\'s assignment untouched and only set the new task\'s tech/group.', 'tasksmanager') ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold" for="wf-completion-group">
                        <?= __('Reassign ticket to group on completion', 'tasksmanager') ?>
                    </label>
                    <div class="text-muted small mb-1">
                        <?= __('When the last step is marked done, the ticket\'s assigned group becomes this one. Leave empty to keep the last step\'s group.', 'tasksmanager') ?>
                    </div>
                    <?php
                    Group::dropdown([
                        'name'      => 'groups_id_completion',
                        'value'     => (int)($wf_data['groups_id_completion'] ?? 0),
                        'condition' => ['is_assign' => 1],
                        'display_emptychoice' => true,
                    ]);
                    ?>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold" for="wf-solutiontemplate">
                        <?= __('Suggested solution template on completion', 'tasksmanager') ?>
                    </label>
                    <div class="text-muted small mb-1">
                        <?= __('When the workflow ends, the Workflow tab shows a banner naming this template so the tech can pick it from GLPI\'s standard solution dropdown. We do NOT auto-create the solution — GLPI\'s native warnings (waiting for approval, "do you really want to resolve or close this?") still gate the actual close.', 'tasksmanager') ?>
                    </div>
                    <?php
                    SolutionTemplate::dropdown([
                        'name'                => 'solutiontemplates_id',
                        'value'               => (int)($wf_data['solutiontemplates_id'] ?? 0),
                        'display_emptychoice' => true,
                    ]);
                    ?>
                </div>

                <button type="submit" name="save_workflow" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i><?= __('Save') ?>
                </button>
            </form>
        </div>
    </div>

<?php if (!$is_new): ?>
    <!-- Steps builder -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="ti ti-list-numbers me-1"></i><?= __('Workflow steps', 'tasksmanager') ?>
            </h5>
        </div>
        <div class="card-body">

            <p class="text-muted small mb-3">
                <i class="ti ti-info-circle me-1"></i>
                <?= __('When a step\'s task is completed, the next step\'s task is automatically added to the ticket. Drag the handle on the left to reorder steps.', 'tasksmanager') ?>
            </p>

            <!-- Vertical flowchart -->
            <div id="tm-flow" class="tm-flow mb-3">
                <?php if (empty($steps)): ?>
                    <div id="tm-empty-state" class="tm-flow-empty text-muted text-center py-4">
                        <i class="ti ti-arrow-down me-1"></i>
                        <?= __('No steps yet. Pick a task template below to add the first step.', 'tasksmanager') ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($steps as $i => $step):
                        $hasComment   = !empty(trim(strip_tags($step['tpl_comment'] ?? '')));
                        $rules_decoded = !empty($step['next_step_rules'])
                            ? (json_decode($step['next_step_rules'], true) ?: [])
                            : [];
                        $rules_count   = is_array($rules_decoded) ? count($rules_decoded) : 0;
                        $sla_cfg = [
                            'duration'    => (int)($step['sla_duration'] ?? 0),
                            'warning_pct' => $step['sla_warning_pct'] === null ? 75 : (int)$step['sla_warning_pct'],
                            'action'      => (string)($step['sla_breach_action'] ?? 'notify'),
                            'group'       => (int)($step['sla_breach_groups_id'] ?? 0),
                            'calendar'    => (int)($step['sla_use_calendar'] ?? 0),
                            'olas_id'     => (int)($step['olas_id'] ?? 0),
                        ];
                    ?>
                    <div class="tm-flow-step"
                         data-step-id="<?= (int)$step['id'] ?>"
                         data-step-order="<?= (int)$step['step_order'] ?>"
                         data-tasktemplates-id="<?= (int)$step['tasktemplates_id'] ?>"
                         data-rules='<?= htmlspecialchars(json_encode($rules_decoded ?: []), ENT_QUOTES) ?>'
                         data-default-goto="<?= (int)($step['default_goto_step_id'] ?? 0) ?>"
                         data-sla='<?= htmlspecialchars(json_encode($sla_cfg), ENT_QUOTES) ?>'
                         data-followup-tpl="<?= (int)($step['itilfollowuptemplates_id'] ?? 0) ?>">
                        <div class="tm-flow-card">
                            <div class="tm-flow-handle" title="<?= __('Drag to reorder', 'tasksmanager') ?>">
                                <i class="ti ti-grip-vertical"></i>
                            </div>
                            <div class="tm-flow-num"><span class="tm-step-num"><?= $i + 1 ?></span></div>
                            <div class="tm-flow-body">
                                <div class="tm-flow-title">
                                    <?php if (!empty($step['tasktemplates_id'])): ?>
                                        <a href="<?= $tasktemplate_base_url ?>?id=<?= (int)$step['tasktemplates_id'] ?>"
                                           target="_blank" rel="noopener"
                                           title="<?= __('Open this task template in a new tab', 'tasksmanager') ?>">
                                            <?= htmlspecialchars($step['tpl_name'] ?? '—') ?>
                                            <i class="ti ti-external-link ms-1 text-muted small"></i>
                                        </a>
                                    <?php else: ?>
                                        <?php // Follow-up-only step (no task template) ?>
                                        <span class="badge bg-azure-lt me-1">
                                            <i class="ti ti-message-2 me-1"></i><?= __('Follow-up', 'tasksmanager') ?>
                                        </span>
                                        <?= htmlspecialchars(
                                            $fup_name_by_id[(int)($step['itilfollowuptemplates_id'] ?? 0)]
                                            ?? __('(no template)', 'tasksmanager')
                                        ) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (empty($step['tasktemplates_id'])): ?>
                                    <?php // Team this follow-up step assigns the ticket to (so the
                                          // follow-up notification reaches the right group). ?>
                                    <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
                                        <span class="small text-muted"><?= __('Assign ticket to', 'tasksmanager') ?></span>
                                        <select class="form-select form-select-sm tm-fup-team" style="max-width:240px"
                                                data-step-id="<?= (int)$step['id'] ?>"
                                                onchange="tmSaveFollowupTeam(this)">
                                            <option value="0"><?= __('-- Keep current team --', 'tasksmanager') ?></option>
                                            <?php foreach ($assign_groups as $g): ?>
                                                <option value="<?= (int)$g['id'] ?>"
                                                    <?= (int)($step['assign_groups_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($g['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="tm-fup-team-status small text-muted ms-1"></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($step['tasktemplates_id'])): ?>
                                <button type="button"
                                        class="btn btn-sm btn-link p-0 text-decoration-none tm-toggle-desc"
                                        onclick="tmToggleDesc(this)">
                                    <i class="ti ti-chevron-right me-1"></i>
                                    <span class="tm-desc-label">
                                        <?= $hasComment
                                            ? __('Edit template comment', 'tasksmanager')
                                            : __('Add template comment', 'tasksmanager') ?>
                                    </span>
                                </button>
                                <div class="tm-flow-desc" style="display:none">
                                    <textarea class="form-control form-control-sm tm-desc-textarea" rows="3"
                                              placeholder="<?= __('Editing this updates the linked task template\'s comment field.', 'tasksmanager') ?>"
                                              onblur="tmSaveDesc(this)"
                                    ><?= htmlspecialchars($step['tpl_comment'] ?? '') ?></textarea>
                                    <div class="text-muted small mt-1">
                                        <i class="ti ti-info-circle me-1"></i>
                                        <?= __('This is the task template\'s comment field. Changes here apply to every workflow that uses the same template.', 'tasksmanager') ?>
                                        <span class="tm-desc-status ms-2"></span>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <button type="button"
                                        class="btn btn-sm btn-link p-0 text-decoration-none tm-toggle-rules ms-2"
                                        onclick="tmToggleRules(this)">
                                    <i class="ti ti-chevron-right me-1"></i>
                                    <span class="tm-rules-label"><?=
                                        sprintf(__('Routing rules (%d)', 'tasksmanager'), $rules_count)
                                    ?></span>
                                </button>
                                <div class="tm-flow-rules" style="display:none">
                                    <div class="tm-rules-list"></div>
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                onclick="tmAddRule(this)">
                                            <i class="ti ti-plus me-1"></i><?= __('Add rule', 'tasksmanager') ?>
                                        </button>
                                        <span class="tm-rules-status text-muted small"></span>
                                    </div>
                                    <div class="tm-rules-else d-flex flex-wrap gap-1 align-items-center mt-2 pt-2 border-top">
                                        <span class="small text-muted"><?= __('If no rule matches', 'tasksmanager') ?></span>
                                        <select class="form-select form-select-sm tm-rules-else-mode" style="max-width:200px"
                                                onchange="tmDefaultGotoChanged(this)">
                                            <option value="next"><?= __('go to next step (linear)', 'tasksmanager') ?></option>
                                            <option value="goto"><?= __('go to specific step…',     'tasksmanager') ?></option>
                                            <option value="end"><?=  __('end the workflow',         'tasksmanager') ?></option>
                                        </select>
                                        <select class="form-select form-select-sm tm-rules-else-goto" style="max-width:240px; display:none"
                                                onchange="tmSaveRulesFromBtn(this)">
                                            <!-- populated by JS from gotoOptions() -->
                                        </select>
                                    </div>
                                    <div class="text-muted small mt-1">
                                        <i class="ti ti-info-circle me-1"></i>
                                        <?= __('Rules are tried in order. The first match wins. Backward jumps are ignored to prevent loops.', 'tasksmanager') ?>
                                    </div>
                                </div>
                                <?php if (!empty($step['tasktemplates_id'])): ?>
                                    <?= $sla_block_html ?>
                                <?php endif; ?>
                                <?= $fup_block_html ?>
                            </div>
                            <div class="tm-flow-actions">
                                <button type="button" class="btn btn-sm btn-outline-danger px-1"
                                        title="<?= __('Remove') ?>" onclick="tmRemoveStep(this)">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="tm-flow-connector"><i class="ti ti-chevron-down"></i></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Add task step -->
            <div class="d-flex gap-2 align-items-center">
                <select id="tm-new-tpl" class="form-select form-select-sm" style="max-width:400px">
                    <option value=""><?= __('-- Select a task template to add --', 'tasksmanager') ?></option>
                    <?php foreach ($all_templates as $tpl): ?>
                        <option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary btn-sm" onclick="tmAddStep()">
                    <i class="ti ti-plus me-1"></i><?= __('Add task step', 'tasksmanager') ?>
                </button>
            </div>

            <!-- Add follow-up-only step (no task — just a message to the requester) -->
            <div class="d-flex gap-2 align-items-center mt-2 flex-wrap">
                <select id="tm-new-fup" class="form-select form-select-sm" style="max-width:340px">
                    <option value=""><?= __('-- Select a follow-up template to add --', 'tasksmanager') ?></option>
                    <?php foreach ($fup_templates as $ft): ?>
                        <option value="<?= (int)$ft['id'] ?>"><?= htmlspecialchars($ft['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="tm-new-fup-team" class="form-select form-select-sm" style="max-width:240px"
                        title="<?= __('Optional: assign the ticket to this team before posting the follow-up', 'tasksmanager') ?>">
                    <option value="0"><?= __('-- Keep current team --', 'tasksmanager') ?></option>
                    <?php foreach ($assign_groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="tmAddFollowupStep()">
                    <i class="ti ti-message-2 me-1"></i><?= __('Add follow-up step', 'tasksmanager') ?>
                </button>
            </div>
            <div class="text-muted small mt-1">
                <i class="ti ti-info-circle me-1"></i>
                <?= __('A follow-up step posts a message on the ticket (no task) and the workflow continues automatically.', 'tasksmanager') ?>
            </div>

        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info">
        <i class="ti ti-info-circle me-1"></i>
        <?= __('Save the workflow first, then you can add steps.', 'tasksmanager') ?>
    </div>
<?php endif; ?>
</div>

<script>
// Populate CSRF tokens from GLPI's meta tag (CheckCsrfListener validates this)
(function () {
    const token = document.querySelector('meta[property="glpi:csrf_token"]')?.getAttribute('content') ?? '';
    document.querySelectorAll('.glpi-csrf-token').forEach(el => { el.value = token; });
})();

(function () {
    const WORKFLOW_ID       = <?= (int)$workflow_id ?>;
    const AJAX_URL          = <?= json_encode($ajax_url) ?>;
    const TASKTEMPLATE_URL  = <?= json_encode($tasktemplate_base_url) ?>;

    // SLA panel markup (shared by existing + newly-added steps) and the
    // assignable-groups list for the "reassign on breach" picker.
    const SLA_BLOCK_HTML    = <?= json_encode($sla_block_html) ?>;
    const FUP_BLOCK_HTML    = <?= json_encode($fup_block_html) ?>;
    const TM_ASSIGN_GROUPS  = <?= json_encode($assign_groups) ?>;
    const TM_OLAS           = <?= json_encode($olas_list) ?>;
    const TM_FUP_TEMPLATES  = <?= json_encode($fup_templates) ?>;

    // Cache of form questions for the rule-field dropdown. Lazily loaded
    // the first time a user opens "Routing rules" on any step.
    let FORM_QUESTIONS_CACHE = null;
    let FORM_QUESTIONS_LOAD  = null;

    function csrfToken() {
        const m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    }

    function post(data) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        return fetch(AJAX_URL, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrfToken(),
            },
        }).then(r => {
            // Endpoint contract: { ok: bool, error?: string, data?: object }.
            // Parse the JSON regardless of HTTP status (errors come back as 4xx/5xx
            // with a JSON body), and surface a transport error only when the
            // body isn't parseable.
            return r.json().catch(() => ({ ok: false, error: 'HTTP ' + r.status }));
        }).catch(err => { console.error('Workflow AJAX error:', err); return {ok: false, error: String(err)}; });
    }

    function renumber() {
        document.querySelectorAll('#tm-flow .tm-flow-step .tm-step-num').forEach((el, i) => {
            el.textContent = i + 1;
        });
        const steps = document.querySelectorAll('#tm-flow .tm-flow-step');
        const empty = document.getElementById('tm-empty-state');
        if (empty) empty.style.display = steps.length ? 'none' : '';
    }

    function persistOrder() {
        const els = Array.from(document.querySelectorAll('#tm-flow .tm-flow-step'));
        const ids = els.map(el => el.dataset.stepId);
        // Mirror the server's formula (position+1)*10 so goto-dropdowns,
        // which filter on data-step-order, stay valid after a reorder.
        els.forEach((el, i) => { el.dataset.stepOrder = (i + 1) * 10; });
        post({action: 'reorder_steps', workflows_id: WORKFLOW_ID, order: JSON.stringify(ids)});
    }

    // Add a follow-up-only step. The follow-up-only card differs enough from
    // the task-step card that we just reload the editor after inserting —
    // simpler and less error-prone than maintaining a second JS card template.
    window.tmAddFollowupStep = function () {
        const sel  = document.getElementById('tm-new-fup');
        if (!sel.value) return;
        const team = document.getElementById('tm-new-fup-team');
        post({
            action: 'add_followup_step',
            workflows_id: WORKFLOW_ID,
            itilfollowuptemplates_id: sel.value,
            assign_groups_id: team ? team.value : 0,
        }).then(resp => {
            if (!resp.ok) { alert(resp.error || 'Error'); return; }
            window.location.reload();
        });
    };

    // Change the team a follow-up-only step assigns the ticket to.
    window.tmSaveFollowupTeam = function (sel) {
        const status = sel.parentElement.querySelector('.tm-fup-team-status');
        if (status) status.textContent = '<?= __('Saving…', 'tasksmanager') ?>';
        post({
            action: 'save_followup_team',
            step_id: sel.dataset.stepId,
            assign_groups_id: sel.value,
        }).then(resp => {
            if (!status) return;
            if (!resp.ok) { status.textContent = resp.error || 'Error'; return; }
            status.textContent = '<?= __('Saved', 'tasksmanager') ?>';
            setTimeout(() => { status.textContent = ''; }, 1500);
        });
    };

    window.tmAddStep = function () {
        const sel = document.getElementById('tm-new-tpl');
        if (!sel.value) return;
        const tplId   = sel.value;
        const tplName = sel.options[sel.selectedIndex].text;

        post({action: 'add_step', workflows_id: WORKFLOW_ID, tasktemplates_id: tplId})
            .then(resp => {
                if (!resp.ok) { alert(resp.error || 'Error'); return; }
                const flow = document.getElementById('tm-flow');

                const stepDiv = document.createElement('div');
                stepDiv.classList.add('tm-flow-step');
                stepDiv.dataset.stepId = (resp.data && resp.data.step_id) ? resp.data.step_id : '';
                stepDiv.dataset.stepOrder = (resp.data && resp.data.step_order) ? resp.data.step_order : '';
                stepDiv.dataset.tasktemplatesId = tplId;
                stepDiv.dataset.rules = '[]';
                stepDiv.dataset.defaultGoto = '0';
                stepDiv.dataset.sla = JSON.stringify({duration:0, warning_pct:75, action:'notify', group:0, calendar:0, olas_id:0});
                stepDiv.dataset.followupTpl = '0';
                stepDiv.innerHTML = `
                    <div class="tm-flow-card">
                        <div class="tm-flow-handle" title="<?= __('Drag to reorder', 'tasksmanager') ?>">
                            <i class="ti ti-grip-vertical"></i>
                        </div>
                        <div class="tm-flow-num"><span class="tm-step-num"></span></div>
                        <div class="tm-flow-body">
                            <div class="tm-flow-title">
                                <a href="${TASKTEMPLATE_URL}?id=${encodeURIComponent(tplId)}"
                                   target="_blank" rel="noopener"
                                   title="<?= __('Open this task template in a new tab', 'tasksmanager') ?>">
                                    ${escHtml(tplName)}
                                    <i class="ti ti-external-link ms-1 text-muted small"></i>
                                </a>
                            </div>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none tm-toggle-desc"
                                    onclick="tmToggleDesc(this)">
                                <i class="ti ti-chevron-right me-1"></i>
                                <span class="tm-desc-label"><?= __('Add template comment', 'tasksmanager') ?></span>
                            </button>
                            <div class="tm-flow-desc" style="display:none">
                                <textarea class="form-control form-control-sm tm-desc-textarea" rows="3"
                                          placeholder="<?= __('Editing this updates the linked task template\'s comment field.', 'tasksmanager') ?>"
                                          onblur="tmSaveDesc(this)"></textarea>
                                <div class="text-muted small mt-1">
                                    <i class="ti ti-info-circle me-1"></i>
                                    <?= __('This is the task template\'s comment field. Changes here apply to every workflow that uses the same template.', 'tasksmanager') ?>
                                    <span class="tm-desc-status ms-2"></span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none tm-toggle-rules ms-2"
                                    onclick="tmToggleRules(this)">
                                <i class="ti ti-chevron-right me-1"></i>
                                <span class="tm-rules-label"><?= __('Routing rules (0)', 'tasksmanager') ?></span>
                            </button>
                            <div class="tm-flow-rules" style="display:none">
                                <div class="tm-rules-list"></div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="tmAddRule(this)">
                                        <i class="ti ti-plus me-1"></i><?= __('Add rule', 'tasksmanager') ?>
                                    </button>
                                    <span class="tm-rules-status text-muted small"></span>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="ti ti-info-circle me-1"></i>
                                    <?= __('Rules are tried in order. The first match wins. If none match, the next step runs. Backward jumps are ignored to prevent loops.', 'tasksmanager') ?>
                                </div>
                            </div>
                            ${SLA_BLOCK_HTML}
                            ${FUP_BLOCK_HTML}
                        </div>
                        <div class="tm-flow-actions">
                            <button type="button" class="btn btn-sm btn-outline-danger px-1"
                                    title="<?= __('Remove') ?>" onclick="tmRemoveStep(this)">
                                <i class="ti ti-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="tm-flow-connector"><i class="ti ti-chevron-down"></i></div>`;
                flow.appendChild(stepDiv);
                // Populate the answer-template dropdown on the new card.
                populateFupTpl(stepDiv);

                renumber();
                sel.value = '';
            });
    };

    window.tmRemoveStep = function (btn) {
        const stepDiv = btn.closest('.tm-flow-step');
        const stepId  = stepDiv.dataset.stepId;
        post({action: 'remove_step', step_id: stepId})
            .then(resp => {
                if (!resp.ok) { alert(resp.error || 'Error'); return; }
                stepDiv.remove();
                renumber();
            });
    };

    window.tmToggleDesc = function (btn) {
        const desc = btn.parentElement.querySelector('.tm-flow-desc');
        if (!desc) return;

        const chevron = btn.querySelector('i');
        const open = desc.style.display === 'none';
        desc.style.display = open ? '' : 'none';
        if (chevron) {
            chevron.classList.toggle('ti-chevron-right', !open);
            chevron.classList.toggle('ti-chevron-down', open);
        }
        if (open) {
            const ta = desc.querySelector('textarea');
            if (ta) ta.focus();
        }
    };

    window.tmSaveDesc = function (ta) {
        const stepDiv = ta.closest('.tm-flow-step');
        if (!stepDiv) return;
        const tplId   = stepDiv.dataset.tasktemplatesId;
        const status  = stepDiv.querySelector('.tm-desc-status');

        if (!tplId) {
            if (status) status.textContent = 'No template linked';
            return;
        }

        if (status) status.textContent = '<?= __('Saving…', 'tasksmanager') ?>';

        post({action: 'update_template_comment', tasktemplates_id: tplId, comment: ta.value})
            .then(d => {
                if (!d.ok) {
                    if (status) status.textContent = d.error || 'Error';
                    return;
                }
                if (status) status.textContent = '<?= __('Saved', 'tasksmanager') ?>';
                setTimeout(() => { if (status) status.textContent = ''; }, 1500);

                const label = stepDiv.querySelector('.tm-desc-label');
                if (label) {
                    label.textContent = ta.value.trim()
                        ? '<?= __('Edit template comment', 'tasksmanager') ?>'
                        : '<?= __('Add template comment', 'tasksmanager') ?>';
                }
            });
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Conditional routing rules ────────────────────────────────────────────
    // Each step can carry an ordered list of rules. The first rule whose
    // condition matches wins; the engine jumps to its goto_step_id. No match
    // → sequential fallthrough. Persisted as JSON in next_step_rules.

    // Whether the server narrowed the question list to forms that reference
    // this workflow. Used by the rule editor to show a small hint when
    // we're showing ALL questions (no form is wired up yet).
    let FORM_QUESTIONS_FILTERED = false;

    function loadFormQuestions() {
        if (FORM_QUESTIONS_CACHE !== null) return Promise.resolve(FORM_QUESTIONS_CACHE);
        if (FORM_QUESTIONS_LOAD)           return FORM_QUESTIONS_LOAD;

        FORM_QUESTIONS_LOAD = post({
            action: 'list_form_questions',
            workflows_id: WORKFLOW_ID,
        }).then(resp => {
            FORM_QUESTIONS_CACHE    = (resp.ok && resp.data && resp.data.questions) || [];
            FORM_QUESTIONS_FILTERED = !!(resp.ok && resp.data && resp.data.filtered_by_workflow);
            return FORM_QUESTIONS_CACHE;
        });
        return FORM_QUESTIONS_LOAD;
    }

    // Build the goto-step <select> options from the current DOM. Only steps
    // whose data-step-order is strictly greater than `currentOrder` are
    // offered — backward jumps would loop.
    function gotoOptions(currentOrder, selectedId) {
        const stepEls = Array.from(document.querySelectorAll('#tm-flow .tm-flow-step'));
        let html = `<option value="">${'<?= __('-- Select target step --', 'tasksmanager') ?>'}</option>`;
        stepEls.forEach((el, i) => {
            const id    = parseInt(el.dataset.stepId, 10);
            const order = parseInt(el.dataset.stepOrder, 10);
            if (!id || isNaN(order) || order <= currentOrder) return;
            const title = el.querySelector('.tm-flow-title')?.textContent.trim() || ('Step ' + (i+1));
            const sel = (id === parseInt(selectedId, 10)) ? ' selected' : '';
            html += `<option value="${id}"${sel}>${(i+1)}. ${escHtml(title)}</option>`;
        });
        return html;
    }

    function fieldOptions(selectedField) {
        let opts = '';
        const items = [
            { v: 'content', l: '<?= __('Ticket description', 'tasksmanager') ?>' },
            { v: 'name',    l: '<?= __('Ticket title',       'tasksmanager') ?>' },
        ];
        items.forEach(it => {
            const sel = (it.v === selectedField) ? ' selected' : '';
            opts += `<option value="${it.v}"${sel}>${escHtml(it.l)}</option>`;
        });
        if (FORM_QUESTIONS_CACHE && FORM_QUESTIONS_CACHE.length) {
            opts += `<optgroup label="${'<?= __('Form questions', 'tasksmanager') ?>'}">`;
            FORM_QUESTIONS_CACHE.forEach(q => {
                const val = 'form:' + q.id;
                const sel = (val === selectedField) ? ' selected' : '';
                const lbl = (q.form_name ? (q.form_name + ' — ') : '') + (q.label || ('#' + q.id));
                opts += `<option value="${val}"${sel}>${escHtml(lbl)}</option>`;
            });
            opts += '</optgroup>';
        }
        // If the selected field is form:<id> but the cache hasn't loaded yet,
        // keep the value alive so save round-trips don't lose it.
        if (selectedField && selectedField.startsWith('form:') &&
            !opts.includes(`value="${selectedField}"`)) {
            opts += `<option value="${escHtml(selectedField)}" selected>${escHtml(selectedField)}</option>`;
        }
        return opts;
    }

    function opOptions(selectedOp) {
        const items = [
            { v: 'contains',     l: '<?= __('contains',          'tasksmanager') ?>' },
            { v: 'not_contains', l: '<?= __('does not contain',  'tasksmanager') ?>' },
            { v: 'eq',           l: '<?= __('equals',            'tasksmanager') ?>' },
            { v: 'neq',          l: '<?= __('does not equal',    'tasksmanager') ?>' },
        ];
        return items.map(it =>
            `<option value="${it.v}"${it.v === selectedOp ? ' selected' : ''}>${escHtml(it.l)}</option>`
        ).join('');
    }

    function renderRule(rule, currentOrder) {
        rule = rule || {};
        const div = document.createElement('div');
        div.className = 'tm-rule d-flex flex-wrap gap-1 align-items-center mb-1';
        div.innerHTML = `
            <span class="small text-muted"><?= __('When', 'tasksmanager') ?></span>
            <select class="form-select form-select-sm tm-rule-field" style="max-width:200px"
                    onchange="tmSaveRulesFromBtn(this)">${fieldOptions(rule.field || 'content')}</select>
            <select class="form-select form-select-sm tm-rule-op" style="max-width:160px"
                    onchange="tmSaveRulesFromBtn(this)">${opOptions(rule.op || 'contains')}</select>
            <input type="text" class="form-control form-control-sm tm-rule-value" style="max-width:200px"
                   placeholder="<?= __('value', 'tasksmanager') ?>"
                   value="${escHtml(rule.value || '')}"
                   onblur="tmSaveRulesFromBtn(this)">
            <span class="small text-muted"><?= __('go to', 'tasksmanager') ?></span>
            <select class="form-select form-select-sm tm-rule-goto" style="max-width:240px"
                    onchange="tmSaveRulesFromBtn(this)">${gotoOptions(currentOrder, rule.goto_step_id || 0)}</select>
            <button type="button" class="btn btn-sm btn-outline-danger px-1"
                    title="<?= __('Remove rule', 'tasksmanager') ?>" onclick="tmRemoveRule(this)">
                <i class="ti ti-x"></i>
            </button>
        `;
        return div;
    }

    function renderRulesInto(stepDiv) {
        const list   = stepDiv.querySelector('.tm-rules-list');
        if (!list) return;
        const order  = parseInt(stepDiv.dataset.stepOrder, 10) || 0;
        const rules  = JSON.parse(stepDiv.dataset.rules || '[]');
        list.innerHTML = '';
        rules.forEach(r => list.appendChild(renderRule(r, order)));

        // Footer hint: tell the user whether the form-question dropdown
        // was narrowed to forms that reference this workflow, or whether
        // we're showing everything because no form is wired up yet.
        const panel = stepDiv.querySelector('.tm-flow-rules');
        if (panel) {
            let hint = panel.querySelector('.tm-rules-scope-hint');
            if (!hint) {
                hint = document.createElement('div');
                hint.className = 'tm-rules-scope-hint text-muted small mt-1';
                panel.appendChild(hint);
            }
            if (FORM_QUESTIONS_CACHE && FORM_QUESTIONS_CACHE.length > 0) {
                if (FORM_QUESTIONS_FILTERED) {
                    hint.innerHTML = '<i class="ti ti-filter me-1"></i>'
                        + <?= json_encode(__('Showing only questions from forms that reference this workflow.', 'tasksmanager')) ?>;
                } else {
                    hint.innerHTML = '<i class="ti ti-list me-1"></i>'
                        + <?= json_encode(__('Showing every defined question. Assign this workflow to a form\'s Ticket destination to narrow the list.', 'tasksmanager')) ?>;
                }
            } else {
                hint.innerHTML = '';
            }
        }

        // Render the "Else" selector
        const defaultGoto = parseInt(stepDiv.dataset.defaultGoto, 10) || 0;
        const modeSel = stepDiv.querySelector('.tm-rules-else-mode');
        const gotoSel = stepDiv.querySelector('.tm-rules-else-goto');
        if (!modeSel || !gotoSel) return;

        // Always rebuild the goto-step <select>: positions / labels may have
        // shifted since the last render.
        gotoSel.innerHTML = gotoOptions(order, defaultGoto > 0 ? defaultGoto : 0);

        let mode = 'next';
        if (defaultGoto === -1)      mode = 'end';
        else if (defaultGoto >  0)   mode = 'goto';
        modeSel.value = mode;
        gotoSel.style.display = (mode === 'goto') ? '' : 'none';
    }

    function serializeRules(stepDiv) {
        const rows = stepDiv.querySelectorAll('.tm-rule');
        const out = [];
        rows.forEach(r => {
            const field = r.querySelector('.tm-rule-field')?.value || '';
            const op    = r.querySelector('.tm-rule-op')?.value    || '';
            const value = r.querySelector('.tm-rule-value')?.value || '';
            const goto_step_id = parseInt(r.querySelector('.tm-rule-goto')?.value || 0, 10);
            if (!field || !goto_step_id) return;
            out.push({ field, op, value, goto_step_id });
        });
        return out;
    }

    // Read the else-mode / goto controls and return the int the server expects.
    //   "next" → 0  (sequential next)
    //   "end"  → -1 (terminate workflow)
    //   "goto" → the picked step id (or 0 if none picked → falls back to linear)
    function readDefaultGoto(stepDiv) {
        const modeSel = stepDiv.querySelector('.tm-rules-else-mode');
        if (!modeSel) return 0;
        const mode = modeSel.value;
        if (mode === 'end') return -1;
        if (mode === 'goto') {
            return parseInt(stepDiv.querySelector('.tm-rules-else-goto')?.value || 0, 10);
        }
        return 0;
    }

    function saveStepRules(stepDiv) {
        const stepId       = stepDiv.dataset.stepId;
        const status       = stepDiv.querySelector('.tm-rules-status');
        const rules        = serializeRules(stepDiv);
        const default_goto = readDefaultGoto(stepDiv);

        if (status) status.textContent = '<?= __('Saving…', 'tasksmanager') ?>';
        post({
            action: 'save_step_rules',
            step_id: stepId,
            rules: JSON.stringify(rules),
            default_goto_step_id: default_goto,
        }).then(resp => {
            if (!resp.ok) {
                if (status) status.textContent = resp.error || 'Error';
                return;
            }
            stepDiv.dataset.rules = JSON.stringify(rules);
            stepDiv.dataset.defaultGoto = default_goto;
            const label = stepDiv.querySelector('.tm-rules-label');
            if (label) {
                label.textContent = '<?= __('Routing rules', 'tasksmanager') ?>' +
                                    ' (' + rules.length + ')';
            }
            if (status) {
                status.textContent = '<?= __('Saved', 'tasksmanager') ?>';
                setTimeout(() => { if (status) status.textContent = ''; }, 1500);
            }
        });
    }

    // Handler for the else-mode <select>. Shows/hides the goto dropdown and
    // saves immediately for "next" / "end" (no extra input needed). For
    // "goto" we wait — the inner <select>'s own onchange will fire when the
    // user picks a step.
    window.tmDefaultGotoChanged = function (sel) {
        const stepDiv = sel.closest('.tm-flow-step');
        const gotoSel = stepDiv.querySelector('.tm-rules-else-goto');
        if (gotoSel) {
            gotoSel.style.display = (sel.value === 'goto') ? '' : 'none';
        }
        if (sel.value !== 'goto') {
            // Clear any stale picked value so readDefaultGoto returns 0 / -1.
            if (gotoSel) gotoSel.value = '';
            saveStepRules(stepDiv);
        } else if (gotoSel && gotoSel.value) {
            // Mode flipped back to "goto" with an existing pick — save it.
            saveStepRules(stepDiv);
        }
        // If "goto" with no pick yet, do nothing — wait for the user.
    };

    window.tmToggleRules = function (btn) {
        const stepDiv = btn.closest('.tm-flow-step');
        const panel   = btn.parentElement.querySelector('.tm-flow-rules');
        if (!panel) return;

        const chevron = btn.querySelector('i');
        const open = panel.style.display === 'none';
        panel.style.display = open ? '' : 'none';
        if (chevron) {
            chevron.classList.toggle('ti-chevron-right', !open);
            chevron.classList.toggle('ti-chevron-down', open);
        }
        if (open) {
            // First render: load form questions once, then paint.
            loadFormQuestions().then(() => renderRulesInto(stepDiv));
        }
    };

    window.tmAddRule = function (btn) {
        const stepDiv = btn.closest('.tm-flow-step');
        const list    = stepDiv.querySelector('.tm-rules-list');
        const order   = parseInt(stepDiv.dataset.stepOrder, 10) || 0;
        list.appendChild(renderRule({}, order));
        // Do NOT save yet — wait until the user picks a goto step. Empty rules
        // are dropped by save_step_rules anyway.
    };

    window.tmRemoveRule = function (btn) {
        const row     = btn.closest('.tm-rule');
        const stepDiv = btn.closest('.tm-flow-step');
        row.remove();
        saveStepRules(stepDiv);
    };

    // Used as the inline change/blur handler on every rule input.
    window.tmSaveRulesFromBtn = function (el) {
        const stepDiv = el.closest('.tm-flow-step');
        if (stepDiv) saveStepRules(stepDiv);
    };

    // ── Per-step SLA ──────────────────────────────────────────────────────────

    // Build a "1 hours" / "30 minutes" style summary from seconds, picking
    // the largest whole unit. Mirrors the unit options in the panel.
    function slaSummary(seconds) {
        seconds = parseInt(seconds, 10) || 0;
        if (seconds <= 0) return '<?= __('SLA', 'tasksmanager') ?>';
        let amount, unit;
        if (seconds % 86400 === 0)      { amount = seconds / 86400; unit = '<?= __('day(s)', 'tasksmanager') ?>'; }
        else if (seconds % 3600 === 0)  { amount = seconds / 3600;  unit = '<?= __('hour(s)', 'tasksmanager') ?>'; }
        else                            { amount = Math.round(seconds / 60); unit = '<?= __('min', 'tasksmanager') ?>'; }
        return '<?= __('SLA', 'tasksmanager') ?>: ' + amount + ' ' + unit;
    }

    // Decompose seconds into {amount, unitSeconds} choosing the largest
    // whole unit so the editor shows e.g. "4 hours" not "240 minutes".
    function slaDecompose(seconds) {
        seconds = parseInt(seconds, 10) || 0;
        if (seconds > 0 && seconds % 86400 === 0) return {amount: seconds / 86400, unit: 86400};
        if (seconds > 0 && seconds % 3600 === 0)  return {amount: seconds / 3600,  unit: 3600};
        if (seconds > 0)                          return {amount: Math.round(seconds / 60), unit: 60};
        return {amount: 0, unit: 3600}; // default unit = hours when empty
    }

    function updateSlaLabel(stepDiv) {
        const cfg = JSON.parse(stepDiv.dataset.sla || '{}');
        const lbl = stepDiv.querySelector('.tm-sla-label');
        if (!lbl) return;
        if ((cfg.olas_id || 0) > 0) {
            const ola = TM_OLAS.find(o => o.id === cfg.olas_id);
            lbl.textContent = '<?= __('SLA', 'tasksmanager') ?>: ' + (ola ? ola.name : '<?= __('Service Level', 'tasksmanager') ?>');
        } else {
            lbl.textContent = slaSummary(cfg.duration || 0);
        }
    }

    // Show/hide the custom-only rows (duration, calendar) and the OLA picker
    // based on the selected source.
    //
    // NOTE: the Max-duration row carries Bootstrap's `d-flex`
    // (= display:flex !important). A plain inline `style.display='none'`
    // can't override an !important class rule, so we must set the inline
    // display WITH !important priority to actually hide it. (The calendar
    // row is `form-check`, no !important, so it would hide either way —
    // but we use the same path for both for consistency.)
    function applySlaSourceVisibility(panel, source) {
        const hide = (source === 'ola');
        panel.querySelectorAll('.tm-sla-custom-only').forEach(el => {
            if (hide) {
                el.style.setProperty('display', 'none', 'important');
            } else {
                el.style.removeProperty('display');
            }
        });
        const olaEl = panel.querySelector('.tm-sla-ola');
        if (olaEl) {
            if (source === 'ola') {
                olaEl.style.removeProperty('display');
            } else {
                olaEl.style.setProperty('display', 'none', 'important');
            }
        }
    }

    function renderSlaInto(stepDiv) {
        const panel = stepDiv.querySelector('.tm-flow-sla');
        if (!panel) return;
        const cfg = JSON.parse(stepDiv.dataset.sla || '{}');
        const source = (cfg.olas_id || 0) > 0 ? 'ola' : 'custom';

        const dec    = slaDecompose(cfg.duration || 0);
        const srcEl  = panel.querySelector('.tm-sla-source');
        const olaEl  = panel.querySelector('.tm-sla-ola');
        const amtEl  = panel.querySelector('.tm-sla-amount');
        const uniEl  = panel.querySelector('.tm-sla-unit');
        const warnEl = panel.querySelector('.tm-sla-warn');
        const actEl  = panel.querySelector('.tm-sla-action');
        const grpEl  = panel.querySelector('.tm-sla-group');
        const calEl  = panel.querySelector('.tm-sla-cal');

        if (srcEl)  srcEl.value  = source;
        if (amtEl)  amtEl.value  = dec.amount || '';
        if (uniEl)  uniEl.value  = String(dec.unit);
        if (warnEl) warnEl.value = (cfg.warning_pct ?? 75);
        if (actEl)  actEl.value  = cfg.action || 'notify';
        if (calEl)  calEl.checked = !!cfg.calendar;

        // Populate the OLA <select> once.
        if (olaEl && !olaEl.dataset.tmFilled) {
            let html = `<option value="0">${'<?= __('-- Select a Service Level --', 'tasksmanager') ?>'}</option>`;
            TM_OLAS.forEach(o => {
                html += `<option value="${o.id}">${escHtml(o.name)} (${o.type}, ${o.num} ${escHtml(o.unit)})</option>`;
            });
            olaEl.innerHTML = html;
            olaEl.dataset.tmFilled = '1';
        }
        if (olaEl) olaEl.value = String(cfg.olas_id || 0);

        // Populate the reassign-group <select> once.
        if (grpEl && !grpEl.dataset.tmFilled) {
            let html = `<option value="0">${'<?= __('-- Select a group --', 'tasksmanager') ?>'}</option>`;
            TM_ASSIGN_GROUPS.forEach(g => {
                html += `<option value="${g.id}">${escHtml(g.name)}</option>`;
            });
            grpEl.innerHTML = html;
            grpEl.dataset.tmFilled = '1';
        }
        if (grpEl) grpEl.value = String(cfg.group || 0);

        // Show the group picker only for the reassign action.
        if (grpEl) grpEl.style.display = (cfg.action === 'reassign') ? '' : 'none';

        applySlaSourceVisibility(panel, source);
    }

    function saveSla(stepDiv) {
        const panel = stepDiv.querySelector('.tm-flow-sla');
        if (!panel) return;
        const status = panel.querySelector('.tm-sla-status');

        const source = panel.querySelector('.tm-sla-source')?.value || 'custom';
        const amount = parseInt(panel.querySelector('.tm-sla-amount')?.value || 0, 10) || 0;
        const unit   = parseInt(panel.querySelector('.tm-sla-unit')?.value || 3600, 10) || 3600;
        const warn   = parseInt(panel.querySelector('.tm-sla-warn')?.value || 0, 10) || 0;
        const action = panel.querySelector('.tm-sla-action')?.value || 'notify';
        const group  = parseInt(panel.querySelector('.tm-sla-group')?.value || 0, 10) || 0;
        const cal    = panel.querySelector('.tm-sla-cal')?.checked ? 1 : 0;
        const olaId  = (source === 'ola')
            ? (parseInt(panel.querySelector('.tm-sla-ola')?.value || 0, 10) || 0)
            : 0;
        const duration = (source === 'ola') ? 0 : amount * unit;

        if (status) status.textContent = '<?= __('Saving…', 'tasksmanager') ?>';
        post({
            action: 'save_step_sla',
            step_id: stepDiv.dataset.stepId,
            sla_duration: duration,
            sla_warning_pct: warn,
            sla_breach_action: action,
            sla_breach_groups_id: group,
            sla_use_calendar: cal,
            olas_id: olaId,
        }).then(resp => {
            if (!resp.ok) { if (status) status.textContent = resp.error || 'Error'; return; }
            stepDiv.dataset.sla = JSON.stringify({
                duration: duration, warning_pct: warn, action: action,
                group: group, calendar: cal, olas_id: olaId
            });
            updateSlaLabel(stepDiv);
            if (status) {
                status.textContent = '<?= __('Saved', 'tasksmanager') ?>';
                setTimeout(() => { if (status) status.textContent = ''; }, 1500);
            }
        });
    }

    window.tmToggleSla = function (btn) {
        const stepDiv = btn.closest('.tm-flow-step');
        const panel   = btn.parentElement.querySelector('.tm-flow-sla');
        if (!panel) return;
        const chevron = btn.querySelector('i');
        const open = panel.style.display === 'none';
        panel.style.display = open ? '' : 'none';
        if (chevron) {
            chevron.classList.toggle('ti-chevron-right', !open);
            chevron.classList.toggle('ti-chevron-down', open);
        }
        if (open) renderSlaInto(stepDiv);
    };

    window.tmSaveSla = function (el) {
        const stepDiv = el.closest('.tm-flow-step');
        if (stepDiv) saveSla(stepDiv);
    };

    // Toggle Custom vs Service Level (OLA) duration source. Updates which
    // rows are visible, then persists. We don't save immediately when
    // switching TO "ola" with no OLA picked yet — wait for the OLA select.
    window.tmSlaSourceChanged = function (sel) {
        const panel   = sel.closest('.tm-flow-sla');
        const stepDiv = sel.closest('.tm-flow-step');
        if (panel) applySlaSourceVisibility(panel, sel.value);
        if (!stepDiv) return;
        if (sel.value === 'ola') {
            const olaVal = parseInt(panel.querySelector('.tm-sla-ola')?.value || 0, 10) || 0;
            if (olaVal > 0) saveSla(stepDiv); // existing pick — persist now
            // else: wait for the user to choose an OLA
        } else {
            saveSla(stepDiv); // back to custom — persist (clears olas_id)
        }
    };

    // Show/hide the reassign-group picker when the breach action changes,
    // then persist.
    window.tmSlaActionChanged = function (sel) {
        const panel = sel.closest('.tm-flow-sla');
        const grpEl = panel ? panel.querySelector('.tm-sla-group') : null;
        if (grpEl) grpEl.style.display = (sel.value === 'reassign') ? '' : 'none';
        const stepDiv = sel.closest('.tm-flow-step');
        if (stepDiv) saveSla(stepDiv);
    };

    // Paint the SLA summary label on every step at load (so the toggle
    // shows "SLA: 4 hours" without needing to open the panel first).
    document.querySelectorAll('#tm-flow .tm-flow-step').forEach(updateSlaLabel);

    // ── Per-step ITILFollowupTemplate ("answer template") picker ─────────────
    //
    // Populate the dropdown once per step (cheap — same options for every
    // step) and bind the change handler. Stored value comes from the
    // `data-followup-tpl` attribute that PHP rendered, OR the JS default
    // ('0') for newly-added steps.

    function fupTemplateOptionsHtml(selectedId) {
        let html = `<option value="0">${'<?= __('-- No answer template --', 'tasksmanager') ?>'}</option>`;
        TM_FUP_TEMPLATES.forEach(t => {
            const sel = (t.id === parseInt(selectedId, 10)) ? ' selected' : '';
            html += `<option value="${t.id}"${sel}>${escHtml(t.name)}</option>`;
        });
        return html;
    }

    function populateFupTpl(stepDiv) {
        const sel = stepDiv.querySelector('.tm-fup-tpl');
        if (!sel) return;
        if (sel.dataset.tmFilled) return; // populate once per element
        const current = stepDiv.dataset.followupTpl || '0';
        sel.innerHTML = fupTemplateOptionsHtml(current);
        sel.dataset.tmFilled = '1';
    }

    window.tmSaveFupTpl = function (el) {
        const stepDiv = el.closest('.tm-flow-step');
        if (!stepDiv) return;
        const status  = stepDiv.querySelector('.tm-fup-status');
        const tplId   = parseInt(el.value, 10) || 0;
        if (status) status.textContent = '<?= __('Saving…', 'tasksmanager') ?>';
        post({
            action: 'update_step_followup_template',
            step_id: stepDiv.dataset.stepId,
            itilfollowuptemplates_id: tplId,
        }).then(resp => {
            if (!resp.ok) {
                if (status) status.textContent = resp.error || 'Error';
                return;
            }
            stepDiv.dataset.followupTpl = String(tplId);
            if (status) {
                status.textContent = '<?= __('Saved', 'tasksmanager') ?>';
                setTimeout(() => { if (status) status.textContent = ''; }, 1500);
            }
        });
    };

    document.querySelectorAll('#tm-flow .tm-flow-step').forEach(populateFupTpl);

    // ── SortableJS drag-reorder ──────────────────────────────────────────────
    // GLPI 11 ships SortableJS at public/lib/sortablejs.js (loaded by the
    // central layout). We attach to the flow container and call our reorder
    // endpoint on drop.
    function initSortable() {
        if (typeof Sortable === 'undefined') {
            // SortableJS not loaded yet — retry shortly. GLPI sometimes loads
            // it after our inline script runs.
            return setTimeout(initSortable, 200);
        }
        const flow = document.getElementById('tm-flow');
        if (!flow) return;
        Sortable.create(flow, {
            handle:    '.tm-flow-handle',
            animation: 150,
            ghostClass: 'tm-flow-ghost',
            dragClass:  'tm-flow-drag',
            onEnd: function () {
                renumber();
                persistOrder();
            },
        });
    }

    initSortable();
    renumber();
})();
</script>
<?php Html::footer(); ?>
