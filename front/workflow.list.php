<?php

/**
 * Tasks Manager - Workflow list
 */

use GlpiPlugin\Tasksmanager\Workflow;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    Html::displayNotFoundError();
}

Session::checkRight('plugin_tasksmanager_workflows', READ);

global $DB;

// Handle delete
if (isset($_POST['delete']) && isset($_POST['id'])) {
    Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

    $id = (int)$_POST['id'];
    $DB->delete('glpi_plugin_tasksmanager_workflow_steps',   ['workflows_id' => $id]);
    $DB->delete('glpi_plugin_tasksmanager_ticket_workflows', ['workflows_id' => $id]);
    $DB->delete('glpi_plugin_tasksmanager_workflows',        ['id'           => $id]);

    Session::addMessageAfterRedirect(__('Workflow deleted.', 'tasksmanager'), true, INFO);
    Html::redirect($_SERVER['PHP_SELF']);
}

// Handle clone — duplicate workflow + all its steps as a new "(copy)" record
if (isset($_POST['clone']) && isset($_POST['id'])) {
    Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

    $src_id = (int)$_POST['id'];
    $new_id = Workflow::duplicate($src_id);

    if ($new_id > 0) {
        Session::addMessageAfterRedirect(
            __('Workflow duplicated.', 'tasksmanager'),
            true,
            INFO
        );
        Html::redirect('workflow.form.php?id=' . $new_id);
    } else {
        Session::addMessageAfterRedirect(
            __('Failed to duplicate workflow.', 'tasksmanager'),
            true,
            ERROR
        );
        Html::redirect($_SERVER['PHP_SELF']);
    }
}

Html::header(
    __('Workflows', 'tasksmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    Workflow::class
);

$workflows = $DB->request([
    'FROM'  => 'glpi_plugin_tasksmanager_workflows',
    'ORDER' => ['name ASC'],
]);

$can_edit = Session::haveRight('plugin_tasksmanager_workflows', UPDATE);

echo '<div class="container-fluid mt-3">';
echo '<div class="d-flex justify-content-between align-items-center mb-3">';
echo '<h2><i class="ti ti-git-branch me-2"></i>' . __('Workflows', 'tasksmanager') . '</h2>';
echo '<div class="d-flex gap-2">';
echo '<a href="analytics.php" class="btn btn-outline-secondary">';
echo '<i class="ti ti-chart-bar me-1"></i>' . __('Analytics', 'tasksmanager');
echo '</a>';
if ($can_edit) {
    echo '<a href="workflow.form.php" class="btn btn-primary">';
    echo '<i class="ti ti-plus me-1"></i>' . __('Add workflow', 'tasksmanager');
    echo '</a>';
}
echo '</div>';
echo '</div>';

echo '<div class="card">';
echo '<div class="card-body p-0">';
echo '<table class="table table-hover mb-0">';
echo '<thead class="table-light"><tr>';
echo '<th>' . __('Name') . '</th>';
echo '<th class="text-center">' . __('Steps', 'tasksmanager') . '</th>';
echo '<th class="text-center">' . __('Active') . '</th>';
echo '<th></th>';
echo '</tr></thead><tbody>';

foreach ($workflows as $wf) {
    $step_count = countElementsInTable(
        'glpi_plugin_tasksmanager_workflow_steps',
        ['workflows_id' => $wf['id']]
    );

    echo '<tr>';
    echo '<td>';
    if ($can_edit) {
        echo '<a href="workflow.form.php?id=' . (int)$wf['id'] . '">';
    }
    echo '<i class="ti ti-git-branch me-1 text-muted"></i>' . htmlspecialchars($wf['name']);
    if ($can_edit) echo '</a>';
    echo '</td>';
    echo '<td class="text-center"><span class="badge bg-secondary">' . (int)$step_count . '</span></td>';
    echo '<td class="text-center">';
    echo $wf['is_active']
        ? '<span class="badge bg-success">' . __('Yes') . '</span>'
        : '<span class="badge bg-secondary">' . __('No') . '</span>';
    echo '</td>';
    echo '<td class="text-end">';
    if ($can_edit) {
        echo '<a href="workflow.form.php?id=' . (int)$wf['id'] . '" class="btn btn-sm btn-outline-primary me-1"'
            . ' title="' . __('Edit') . '">';
        echo '<i class="ti ti-edit"></i></a>';

        // Clone
        echo '<form method="post" class="d-inline me-1"'
            . ' onsubmit="return confirm(\'' . addslashes(__('Duplicate this workflow?', 'tasksmanager')) . '\')">';
        echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';
        echo '<input type="hidden" name="id" value="' . (int)$wf['id'] . '">';
        echo '<button type="submit" name="clone" class="btn btn-sm btn-outline-secondary"'
            . ' title="' . __('Duplicate', 'tasksmanager') . '">';
        echo '<i class="ti ti-copy"></i></button>';
        echo '</form>';

        // Delete
        echo '<form method="post" class="d-inline"'
            . ' onsubmit="return confirm(\'' . addslashes(__('Delete this workflow?', 'tasksmanager')) . '\')">';
        echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';
        echo '<input type="hidden" name="id" value="' . (int)$wf['id'] . '">';
        echo '<button type="submit" name="delete" class="btn btn-sm btn-outline-danger"'
            . ' title="' . __('Delete') . '">';
        echo '<i class="ti ti-trash"></i></button>';
        echo '</form>';
    }
    echo '</td>';
    echo '</tr>';
}

if (!count($workflows)) {
    echo '<tr><td colspan="4" class="text-center text-muted py-4">';
    echo __('No workflows yet. Click "Add workflow" to create one.', 'tasksmanager');
    echo '</td></tr>';
}

echo '</tbody></table></div></div></div>';

echo '<script>(function(){';
echo 'const t=document.querySelector("meta[property=\'glpi:csrf_token\']")?.getAttribute("content")??"";';
echo 'document.querySelectorAll(".glpi-csrf-token").forEach(el=>{el.value=t;});';
echo '})();</script>';

Html::footer();
