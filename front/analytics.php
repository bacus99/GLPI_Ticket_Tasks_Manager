<?php

/**
 * Tasks Manager - Workflow analytics dashboard
 *
 * Cross-ticket aggregate view over the audit log: KPIs, per-workflow
 * performance, and per-step bottleneck + SLA compliance. Read-only.
 * Charts are pure CSS bars (no external JS dependency) so the page is
 * robust across GLPI installs.
 */

use GlpiPlugin\Tasksmanager\Analytics;
use GlpiPlugin\Tasksmanager\Workflow;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    Html::displayNotFoundError();
}

Session::checkRight('plugin_tasksmanager_workflows', READ);

Html::header(
    __('Workflow analytics', 'tasksmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    Workflow::class
);

$kpis        = Analytics::getKpis(30);
$performance = Analytics::getWorkflowPerformance();

// Which workflow's step breakdown to show. Default to the most-applied one.
$selected_wf = isset($_GET['workflows_id']) ? (int)$_GET['workflows_id'] : 0;
if ($selected_wf === 0 && !empty($performance)) {
    $best = 0;
    foreach ($performance as $p) {
        if ($p['applied'] > $best) { $best = $p['applied']; $selected_wf = $p['id']; }
    }
    if ($selected_wf === 0) {
        $selected_wf = (int)$performance[0]['id'];
    }
}
$bottlenecks = $selected_wf > 0 ? Analytics::getStepBottlenecks($selected_wf) : [];

/** Small helper: a Tabler KPI card. */
function tm_kpi_card(string $icon, string $label, $value, string $tone = 'secondary'): string
{
    return '<div class="col-sm-6 col-lg-4 col-xl-2 mb-3">'
        . '<div class="card h-100"><div class="card-body text-center py-3">'
        . '<div class="fs-1 text-' . $tone . '"><i class="ti ' . $icon . '"></i></div>'
        . '<div class="h2 mb-0">' . (int)$value . '</div>'
        . '<div class="text-muted small">' . htmlspecialchars($label) . '</div>'
        . '</div></div></div>';
}

echo '<div class="container-fluid mt-3">';

// Header + nav back to the list
echo '<div class="d-flex justify-content-between align-items-center mb-3">';
echo '<h2><i class="ti ti-chart-bar me-2"></i>' . __('Workflow analytics', 'tasksmanager') . '</h2>';
echo '<a href="workflow.list.php" class="btn btn-outline-secondary btn-sm">'
    . '<i class="ti ti-arrow-left me-1"></i>' . __('Back to workflows', 'tasksmanager') . '</a>';
echo '</div>';

// ── KPI cards ──────────────────────────────────────────────────────────────
echo '<div class="row">';
echo tm_kpi_card('ti-git-branch',    __('Workflows defined', 'tasksmanager'), $kpis['workflows_defined'], 'primary');
echo tm_kpi_card('ti-player-play',   __('Active instances', 'tasksmanager'),  $kpis['instances_active'],  'info');
echo tm_kpi_card('ti-circle-check',  __('Completed', 'tasksmanager'),         $kpis['instances_done'],    'success');
echo tm_kpi_card('ti-x',             __('Cancelled', 'tasksmanager'),         $kpis['instances_cancel'],  'secondary');
echo tm_kpi_card('ti-alarm',         sprintf(__('SLA warnings (%dd)', 'tasksmanager'), $kpis['since_days']),  $kpis['sla_warnings'], 'warning');
echo tm_kpi_card('ti-alarm-filled',  sprintf(__('SLA breaches (%dd)', 'tasksmanager'), $kpis['since_days']),  $kpis['sla_breaches'], 'danger');
echo '</div>';

// ── Per-workflow performance ────────────────────────────────────────────────
echo '<div class="card mb-4">';
echo '<div class="card-header"><h5 class="mb-0"><i class="ti ti-list-numbers me-1"></i>'
    . __('Workflow performance', 'tasksmanager') . '</h5></div>';
echo '<div class="card-body p-0">';
echo '<table class="table table-hover mb-0">';
echo '<thead class="table-light"><tr>';
echo '<th>' . __('Workflow', 'tasksmanager') . '</th>';
echo '<th class="text-center">' . __('Applied', 'tasksmanager') . '</th>';
echo '<th class="text-center">' . __('Completed', 'tasksmanager') . '</th>';
echo '<th class="text-center">' . __('Active') . '</th>';
echo '<th style="min-width:160px">' . __('Completion rate', 'tasksmanager') . '</th>';
echo '<th class="text-center">' . __('Avg duration', 'tasksmanager') . '</th>';
echo '<th class="text-center">' . __('SLA breaches', 'tasksmanager') . '</th>';
echo '<th></th>';
echo '</tr></thead><tbody>';

if (empty($performance)) {
    echo '<tr><td colspan="8" class="text-center text-muted py-4">'
        . __('No workflow activity yet.', 'tasksmanager') . '</td></tr>';
} else {
    foreach ($performance as $p) {
        $rate = (int)$p['completion_rate'];
        $bar_tone = $rate >= 80 ? 'bg-success' : ($rate >= 50 ? 'bg-warning' : 'bg-danger');
        $is_sel = ($p['id'] === $selected_wf);

        echo '<tr' . ($is_sel ? ' class="table-active"' : '') . '>';
        echo '<td><i class="ti ti-git-branch me-1 text-muted"></i>' . htmlspecialchars($p['name']);
        if (!$p['is_active']) {
            echo ' <span class="badge bg-secondary ms-1">' . __('inactive', 'tasksmanager') . '</span>';
        }
        echo '</td>';
        echo '<td class="text-center">' . (int)$p['applied'] . '</td>';
        echo '<td class="text-center">' . (int)$p['completed'] . '</td>';
        echo '<td class="text-center">' . (int)$p['active'] . '</td>';
        echo '<td>';
        echo '<div class="d-flex align-items-center gap-2">';
        echo '<div class="progress flex-grow-1" style="height:8px;min-width:80px">';
        echo '<div class="progress-bar ' . $bar_tone . '" style="width:' . $rate . '%"></div>';
        echo '</div><span class="small text-muted">' . $rate . '%</span></div>';
        echo '</td>';
        echo '<td class="text-center text-muted small">'
            . ($p['avg_duration'] > 0 ? Html::timestampToString($p['avg_duration'], false) : '—')
            . '</td>';
        echo '<td class="text-center">';
        echo $p['sla_breaches'] > 0
            ? '<span class="badge bg-danger">' . (int)$p['sla_breaches'] . '</span>'
            : '<span class="text-muted">0</span>';
        echo '</td>';
        echo '<td class="text-end">';
        echo '<a href="?workflows_id=' . (int)$p['id'] . '" class="btn btn-sm btn-outline-primary"'
            . ' title="' . __('View step breakdown', 'tasksmanager') . '">'
            . '<i class="ti ti-chart-histogram"></i></a>';
        echo '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table></div></div>';

// ── Step bottleneck + SLA compliance ────────────────────────────────────────
echo '<div class="card mb-4">';
echo '<div class="card-header d-flex justify-content-between align-items-center">';
echo '<h5 class="mb-0"><i class="ti ti-chart-histogram me-1"></i>'
    . __('Step breakdown', 'tasksmanager') . '</h5>';

// Workflow selector
echo '<form method="get" class="d-flex align-items-center gap-2 mb-0">';
echo '<label class="small text-muted mb-0">' . __('Workflow', 'tasksmanager') . '</label>';
echo '<select name="workflows_id" class="form-select form-select-sm" style="max-width:280px"'
    . ' onchange="this.form.submit()">';
foreach ($performance as $p) {
    $sel = $p['id'] === $selected_wf ? ' selected' : '';
    echo '<option value="' . (int)$p['id'] . '"' . $sel . '>' . htmlspecialchars($p['name']) . '</option>';
}
echo '</select></form>';
echo '</div>';

echo '<div class="card-body">';

if (empty($bottlenecks)) {
    echo '<p class="text-muted mb-0">' . __('No step timing data yet for this workflow.', 'tasksmanager') . '</p>';
} else {
    // Max avg dwell for proportional bar widths.
    $max_dwell = 1;
    foreach ($bottlenecks as $b) {
        if ($b['avg_dwell'] > $max_dwell) { $max_dwell = $b['avg_dwell']; }
    }

    echo '<div class="text-muted small mb-3"><i class="ti ti-info-circle me-1"></i>'
        . __('Average time the workflow spent on each step before advancing. Longer bars = bottlenecks.', 'tasksmanager')
        . '</div>';

    echo '<table class="table table-sm align-middle mb-0">';
    echo '<thead class="table-light"><tr>';
    echo '<th style="width:40px">#</th>';
    echo '<th>' . __('Step', 'tasksmanager') . '</th>';
    echo '<th style="min-width:240px">' . __('Avg time on step', 'tasksmanager') . '</th>';
    echo '<th class="text-center">' . __('Samples', 'tasksmanager') . '</th>';
    echo '<th class="text-center">' . __('SLA warnings', 'tasksmanager') . '</th>';
    echo '<th class="text-center">' . __('SLA breaches', 'tasksmanager') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($bottlenecks as $i => $b) {
        $pct = $max_dwell > 0 ? (int)round($b['avg_dwell'] / $max_dwell * 100) : 0;
        // Tone the bar red if this step has breaches, amber if warnings.
        $tone = $b['breaches'] > 0 ? 'bg-danger' : ($b['warnings'] > 0 ? 'bg-warning' : 'bg-info');

        echo '<tr>';
        echo '<td>' . ($i + 1) . '</td>';
        echo '<td>' . htmlspecialchars($b['name']) . '</td>';
        echo '<td>';
        echo '<div class="d-flex align-items-center gap-2">';
        echo '<div class="progress flex-grow-1" style="height:10px;min-width:120px">';
        echo '<div class="progress-bar ' . $tone . '" style="width:' . max($pct, $b['avg_dwell'] > 0 ? 3 : 0) . '%"></div>';
        echo '</div>';
        echo '<span class="small text-muted" style="white-space:nowrap">'
            . ($b['avg_dwell'] > 0 ? Html::timestampToString($b['avg_dwell'], false) : '—')
            . '</span>';
        echo '</div>';
        echo '</td>';
        echo '<td class="text-center text-muted small">' . (int)$b['samples'] . '</td>';
        echo '<td class="text-center">';
        echo $b['warnings'] > 0 ? '<span class="badge bg-warning text-dark">' . (int)$b['warnings'] . '</span>' : '<span class="text-muted">0</span>';
        echo '</td>';
        echo '<td class="text-center">';
        echo $b['breaches'] > 0 ? '<span class="badge bg-danger">' . (int)$b['breaches'] . '</span>' : '<span class="text-muted">0</span>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo '</div></div>';
echo '</div>'; // container

Html::footer();
