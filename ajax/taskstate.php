<?php

/**
 * Tasks Manager - AJAX handler for task state updates
 *
 * Response contract (per GLPI-Shared/rules/glpi-plugin-api.md):
 *   { ok: bool, error?: string, data?: object }
 */

use GlpiPlugin\Tasksmanager\TaskState;

include('../../../inc/includes.php');

// Enforce authentication
Session::checkLoginUser();
Session::checkRight('ticket', UPDATE);

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'update_status':
            // CSRF already validated by GLPI 11 CheckCsrfListener before this code runs
            $taskstate = new TaskState();
            $id = (int)($_POST['id'] ?? 0);

            if (!$taskstate->getFromDB($id)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Task state not found']);
                exit;
            }

            $taskstate->check($id, UPDATE);
            $result = $taskstate->update([
                'id'            => $id,
                'plugin_status' => $_POST['plugin_status'] ?? $taskstate->fields['plugin_status'],
                'progress'      => $_POST['progress'] ?? $taskstate->fields['progress'],
            ]);

            if (!$result) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => __('Update failed', 'tasksmanager')]);
                exit;
            }

            echo json_encode(['ok' => true]);
            break;

        case 'get_states':
            $tickets_id = (int)($_GET['tickets_id'] ?? 0);
            if ($tickets_id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Invalid ticket ID']);
                exit;
            }
            // Per-ticket authorization. The global `ticket UPDATE` check
            // upstream only verifies the *profile* right; it doesn't bound
            // the user to tickets they can actually see. Ticket::can()
            // resolves the row, checks the READ right, AND validates entity
            // visibility via Session::haveAccessToEntity() — without this
            // gate, an Entity-A technician could enumerate ticket IDs and
            // pull task-state (notes, assignees, workflow step data) for
            // any ticket in any entity. The taskstates table has no
            // entities_id column, so the entity boundary has to be
            // enforced by going through the parent Ticket.
            $ticket = new \Ticket();
            if (!$ticket->can($tickets_id, READ)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Access denied']);
                exit;
            }
            $states = TaskState::getForTicket($tickets_id);
            echo json_encode(['ok' => true, 'data' => $states]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
            exit;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
