<?php

/**
 * Tasks Manager - GLPI Plugin
 *
 * @license   GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Tasksmanager\Profile;
use GlpiPlugin\Tasksmanager\TaskDashboard;
use GlpiPlugin\Tasksmanager\Workflow;

define('PLUGIN_TASKSMANAGER_VERSION', '1.6.1');
define('PLUGIN_TASKSMANAGER_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_TASKSMANAGER_MAX_GLPI_VERSION', '11.0.99');

function plugin_init_tasksmanager(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['tasksmanager'] = true;

    // Wrench icon on Setup > Plugins → opens the workflow list.
    $PLUGIN_HOOKS['config_page']['tasksmanager'] = 'front/workflow.list.php';

    // Workflow builder under Tools menu
    $PLUGIN_HOOKS['menu_toadd']['tasksmanager'] = [
        'tools' => Workflow::class,
    ];

    // Hook ticket task events for auto-advance
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['tasksmanager']    = [
        'Ticket'     => 'plugin_tasksmanager_ticket_add',
        'TicketTask' => 'plugin_tasksmanager_item_add',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['tasksmanager'] = [
        'Ticket'     => 'plugin_tasksmanager_ticket_update',
        'TicketTask' => 'plugin_tasksmanager_item_update',
    ];

    // Workflow tab on tickets
    Plugin::registerClass(TaskDashboard::class, ['addtabon' => ['Ticket']]);

    // Rights tab on profiles (Administration → Profiles → <profile> → Tasks Manager)
    Plugin::registerClass(Profile::class, ['addtabon' => ['Profile']]);

    // Auto-refresh page when a ticket task is marked as Done so the workflow
    // tab and group assignment are always in sync with the server state.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['tasksmanager'] = ['public/js/workflow-refresh.js'];
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['tasksmanager']        = ['public/css/tasksmanager.css'];

    // Workflow field in form destinations.
    // Wrapped defensively: during early boot (Plugin::getPluginInformation)
    // the form-destination classes or this plugin's autoloader may not yet
    // be available, and an uncaught error here makes GLPI fail to load
    // plugin information with the cryptic warning seen previously.
    try {
        if (
            class_exists('Glpi\\Form\\Destination\\FormDestinationManager')
            && class_exists('GlpiPlugin\\Tasksmanager\\Form\\Destination\\WorkflowField')
        ) {
            \Glpi\Form\Destination\FormDestinationManager::getInstance()
                ->registerPluginCommonITILConfigField(
                    \Glpi\Form\Destination\FormDestinationTicket::class,
                    new \GlpiPlugin\Tasksmanager\Form\Destination\WorkflowField()
                );
        }
    } catch (\Throwable $e) {
        // Silently skip — form integration is optional during plugin info load.
    }
}

function plugin_version_tasksmanager(): array
{
    return [
        'name'         => 'Tasks Manager',
        'version'      => PLUGIN_TASKSMANAGER_VERSION,
        'author'       => 'Christian Bernard',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TASKSMANAGER_MIN_GLPI_VERSION,
                'max' => PLUGIN_TASKSMANAGER_MAX_GLPI_VERSION,
            ],
            'php' => ['min' => '8.1'],
        ],
    ];
}

function plugin_tasksmanager_check_prerequisites(): bool
{
    return true;
}

function plugin_tasksmanager_check_config(bool $verbose = false): bool
{
    return true;
}
