<?php

/**
 * Tasks Manager - GLPI Plugin
 *
 * @license   GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Tasksmanager\TaskDashboard;
use GlpiPlugin\Tasksmanager\Workflow;

define('PLUGIN_TASKSMANAGER_VERSION', '1.3.1');
define('PLUGIN_TASKSMANAGER_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_TASKSMANAGER_MAX_GLPI_VERSION', '11.0.99');

function plugin_init_tasksmanager(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['tasksmanager'] = true;

    // Workflow builder under Tools menu
    $PLUGIN_HOOKS['menu_toadd']['tasksmanager'] = [
        'tools' => Workflow::class,
    ];

    // Hook ticket task events for auto-advance
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['tasksmanager']    = ['TicketTask' => 'plugin_tasksmanager_item_add'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['tasksmanager'] = ['TicketTask' => 'plugin_tasksmanager_item_update'];

    // Workflow tab on tickets
    Plugin::registerClass(TaskDashboard::class, ['addtabon' => ['Ticket']]);

    // Workflow field in form destinations
    if (class_exists(\Glpi\Form\Destination\FormDestinationManager::class)) {
        \Glpi\Form\Destination\FormDestinationManager::getInstance()
            ->registerPluginCommonITILConfigField(
                \Glpi\Form\Destination\FormDestinationTicket::class,
                new \GlpiPlugin\Tasksmanager\Form\Destination\WorkflowField()
            );
    }
}

function plugin_version_tasksmanager(): array
{
    return [
        'name'         => 'Tasks Manager',
        'version'      => PLUGIN_TASKSMANAGER_VERSION,
        'author'       => 'TC Transcontinental',
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
