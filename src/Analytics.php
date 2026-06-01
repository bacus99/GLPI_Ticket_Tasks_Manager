<?php

namespace GlpiPlugin\Tasksmanager;

/**
 * Analytics — read-only aggregation over the workflow audit log.
 *
 * Everything here is a GROUP BY (or a light PHP fold) over
 * glpi_plugin_tasksmanager_workflow_events + ticket_workflows +
 * workflow_steps. No new schema, no new collection — the data was already
 * being written by the engine since 1.3.14 (audit) and 1.8.0 (SLA events).
 */
class Analytics
{
    /**
     * Top-line KPI counters. $sinceDays scopes the SLA counters to a
     * recent window (0 = all time).
     */
    public static function getKpis(int $sinceDays = 30): array
    {
        global $DB;

        $kpis = [
            'workflows_defined' => 0,
            'instances_active'  => 0,
            'instances_done'    => 0,
            'instances_cancel'  => 0,
            'sla_warnings'      => 0,
            'sla_breaches'      => 0,
            'since_days'        => $sinceDays,
        ];

        if ($DB->tableExists('glpi_plugin_tasksmanager_workflows')) {
            $kpis['workflows_defined'] = (int)(
                $DB->request([
                    'COUNT' => 'c',
                    'FROM'  => 'glpi_plugin_tasksmanager_workflows',
                ])->current()['c'] ?? 0
            );
        }

        if ($DB->tableExists('glpi_plugin_tasksmanager_ticket_workflows')) {
            foreach (['active', 'completed', 'cancelled'] as $st) {
                $cnt = (int)(
                    $DB->request([
                        'COUNT' => 'c',
                        'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
                        'WHERE' => ['status' => $st],
                    ])->current()['c'] ?? 0
                );
                $key = $st === 'active' ? 'instances_active'
                     : ($st === 'completed' ? 'instances_done' : 'instances_cancel');
                $kpis[$key] = $cnt;
            }
        }

        if ($DB->tableExists('glpi_plugin_tasksmanager_workflow_events')) {
            $where_recent = [];
            if ($sinceDays > 0) {
                $where_recent['date_creation'] = ['>=', date('Y-m-d H:i:s', time() - $sinceDays * DAY_TIMESTAMP)];
            }
            foreach (['step_sla_warning' => 'sla_warnings', 'step_sla_breached' => 'sla_breaches'] as $ev => $key) {
                $kpis[$key] = (int)(
                    $DB->request([
                        'COUNT' => 'c',
                        'FROM'  => 'glpi_plugin_tasksmanager_workflow_events',
                        'WHERE' => ['event_type' => $ev] + $where_recent,
                    ])->current()['c'] ?? 0
                );
            }
        }

        return $kpis;
    }

    /**
     * Per-workflow performance: how many times applied, completed, still
     * active, completion rate, avg total duration, SLA breaches.
     *
     * @return array list of rows keyed by workflow.
     */
    public static function getWorkflowPerformance(): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_tasksmanager_workflows')
            || !$DB->tableExists('glpi_plugin_tasksmanager_ticket_workflows')
        ) {
            return [];
        }

        $rows = [];
        $wf_iter = $DB->request([
            'SELECT' => ['id', 'name', 'is_active'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflows',
            'ORDER'  => ['name ASC'],
        ]);

        foreach ($wf_iter as $wf) {
            $wid = (int)$wf['id'];

            $applied = (int)($DB->request([
                'COUNT' => 'c',
                'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
                'WHERE' => ['workflows_id' => $wid],
            ])->current()['c'] ?? 0);

            $done = (int)($DB->request([
                'COUNT' => 'c',
                'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
                'WHERE' => ['workflows_id' => $wid, 'status' => 'completed'],
            ])->current()['c'] ?? 0);

            $active = (int)($DB->request([
                'COUNT' => 'c',
                'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
                'WHERE' => ['workflows_id' => $wid, 'status' => 'active'],
            ])->current()['c'] ?? 0);

            $breaches = 0;
            if ($DB->tableExists('glpi_plugin_tasksmanager_workflow_events')) {
                $breaches = (int)($DB->request([
                    'COUNT' => 'c',
                    'FROM'  => 'glpi_plugin_tasksmanager_workflow_events',
                    'WHERE' => ['workflows_id' => $wid, 'event_type' => 'step_sla_breached'],
                ])->current()['c'] ?? 0);
            }

            $rows[] = [
                'id'              => $wid,
                'name'            => (string)$wf['name'],
                'is_active'       => (int)$wf['is_active'],
                'applied'         => $applied,
                'completed'       => $done,
                'active'          => $active,
                'completion_rate' => $applied > 0 ? (int)round($done / $applied * 100) : 0,
                'avg_duration'    => self::avgWorkflowDuration($wid),
                'sla_breaches'    => $breaches,
            ];
        }

        return $rows;
    }

    /**
     * Average wall-clock duration (seconds) from workflow_applied to
     * workflow_completed across all completed instances of a workflow.
     * 0 if none completed.
     */
    private static function avgWorkflowDuration(int $workflows_id): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_tasksmanager_workflow_events')) {
            return 0;
        }

        // Collect applied + completed timestamps per instance.
        $starts = []; // tw_id => ts
        $ends   = []; // tw_id => ts
        foreach ($DB->request([
            'SELECT' => ['ticket_workflows_id', 'event_type', 'date_creation'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflow_events',
            'WHERE'  => [
                'workflows_id' => $workflows_id,
                'event_type'   => ['workflow_applied', 'workflow_completed'],
            ],
            'ORDER'  => ['date_creation ASC', 'id ASC'],
        ]) as $ev) {
            $tw = (int)$ev['ticket_workflows_id'];
            $ts = strtotime($ev['date_creation']);
            if ($ts === false) {
                continue;
            }
            if ($ev['event_type'] === 'workflow_applied') {
                if (!isset($starts[$tw])) {
                    $starts[$tw] = $ts;
                }
            } else { // workflow_completed
                $ends[$tw] = $ts;
            }
        }

        $total = 0;
        $n = 0;
        foreach ($ends as $tw => $endTs) {
            if (isset($starts[$tw]) && $endTs >= $starts[$tw]) {
                $total += ($endTs - $starts[$tw]);
                $n++;
            }
        }

        return $n > 0 ? (int)round($total / $n) : 0;
    }

    /**
     * Per-step bottleneck + SLA stats for one workflow.
     *
     * For each step_order we compute the average "dwell" — how long the
     * workflow sat on that step before advancing — by folding the ordered
     * step_started / workflow_completed events per instance. Also counts
     * SLA warnings/breaches per step.
     *
     * @return array list of {step_order, name, avg_dwell, samples,
     *               warnings, breaches}
     */
    public static function getStepBottlenecks(int $workflows_id): array
    {
        global $DB;

        if ($workflows_id <= 0
            || !$DB->tableExists('glpi_plugin_tasksmanager_workflow_events')
        ) {
            return [];
        }

        // Step template names by step_order.
        $names = [];
        if ($DB->tableExists('glpi_plugin_tasksmanager_workflow_steps')) {
            foreach ($DB->request([
                'SELECT'    => ['wfs.step_order', 'tt.name AS tpl_name'],
                'FROM'      => 'glpi_plugin_tasksmanager_workflow_steps AS wfs',
                'LEFT JOIN' => [
                    'glpi_tasktemplates AS tt' => ['ON' => ['wfs' => 'tasktemplates_id', 'tt' => 'id']],
                ],
                'WHERE'     => ['wfs.workflows_id' => $workflows_id],
                'ORDER'     => ['wfs.step_order ASC'],
            ]) as $s) {
                $names[(int)$s['step_order']] = (string)($s['tpl_name'] ?? '');
            }
        }

        // Fold step_started + workflow_completed events to derive dwell.
        $dwell_total = []; // step_order => seconds
        $dwell_count = []; // step_order => n
        $last = [];        // tw_id => [step_order, ts]

        foreach ($DB->request([
            'SELECT' => ['ticket_workflows_id', 'step_order', 'event_type', 'date_creation'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflow_events',
            'WHERE'  => [
                'workflows_id' => $workflows_id,
                'event_type'   => ['step_started', 'workflow_completed'],
            ],
            'ORDER'  => ['ticket_workflows_id ASC', 'date_creation ASC', 'id ASC'],
        ]) as $ev) {
            $tw = (int)$ev['ticket_workflows_id'];
            $ts = strtotime($ev['date_creation']);
            if ($ts === false) {
                continue;
            }

            if ($ev['event_type'] === 'step_started') {
                // Close the previous step's dwell for this instance.
                if (isset($last[$tw])) {
                    [$pOrder, $pTs] = $last[$tw];
                    if ($ts >= $pTs) {
                        $dwell_total[$pOrder] = ($dwell_total[$pOrder] ?? 0) + ($ts - $pTs);
                        $dwell_count[$pOrder] = ($dwell_count[$pOrder] ?? 0) + 1;
                    }
                }
                $last[$tw] = [(int)$ev['step_order'], $ts];
            } else { // workflow_completed closes the final step
                if (isset($last[$tw])) {
                    [$pOrder, $pTs] = $last[$tw];
                    if ($ts >= $pTs) {
                        $dwell_total[$pOrder] = ($dwell_total[$pOrder] ?? 0) + ($ts - $pTs);
                        $dwell_count[$pOrder] = ($dwell_count[$pOrder] ?? 0) + 1;
                    }
                    unset($last[$tw]);
                }
            }
        }

        // SLA warning / breach counts per step_order. Folded in PHP to keep
        // the query simple (a grouped COUNT alongside other columns is
        // awkward in the query builder).
        $warn = [];
        $breach = [];
        foreach ($DB->request([
            'SELECT' => ['step_order', 'event_type'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflow_events',
            'WHERE'  => [
                'workflows_id' => $workflows_id,
                'event_type'   => ['step_sla_warning', 'step_sla_breached'],
            ],
        ]) as $r) {
            $so = (int)$r['step_order'];
            if ($r['event_type'] === 'step_sla_warning') {
                $warn[$so] = ($warn[$so] ?? 0) + 1;
            } else {
                $breach[$so] = ($breach[$so] ?? 0) + 1;
            }
        }

        // Assemble, ordered by step_order. Include all defined steps even if
        // they have no samples yet, so the chart shows the full sequence.
        $orders = array_unique(array_merge(
            array_keys($names),
            array_keys($dwell_count),
            array_keys($warn),
            array_keys($breach)
        ));
        sort($orders);

        $out = [];
        foreach ($orders as $so) {
            $samples = $dwell_count[$so] ?? 0;
            $out[] = [
                'step_order' => $so,
                'name'       => $names[$so] ?? sprintf(__('Step %d', 'tasksmanager'), $so),
                'avg_dwell'  => $samples > 0 ? (int)round($dwell_total[$so] / $samples) : 0,
                'samples'    => $samples,
                'warnings'   => $warn[$so] ?? 0,
                'breaches'   => $breach[$so] ?? 0,
            ];
        }

        return $out;
    }
}
