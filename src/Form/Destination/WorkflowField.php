<?php

namespace GlpiPlugin\Tasksmanager\Form\Destination;

use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use GlpiPlugin\Tasksmanager\Workflow;
use Ticket;

final class WorkflowField extends AbstractConfigField
{
    public const KEY = 'tasksmanager_workflow';

    public function getLabel(): string
    {
        return __('Workflow (Tasks Manager)', 'tasksmanager');
    }

    public function getConfigClass(): string
    {
        return WorkflowFieldConfig::class;
    }

    public function getDefaultConfig(Form $form): WorkflowFieldConfig
    {
        return new WorkflowFieldConfig('');
    }

    public function getWeight(): int
    {
        return 30;
    }

    public function getCategory(): Category
    {
        return Category::TIMELINE;
    }

    public function renderConfigForm(
        Form $form,
        FormDestination $destination,
        JsonFieldInterface $config,
        string $input_name,
        array $display_options
    ): string {
        $workflows   = Workflow::getDropdownOptions();
        $current_val = ($config instanceof WorkflowFieldConfig) ? $config->getValue() : '';

        $html  = '<select name="' . htmlspecialchars($input_name . '[value]') . '" class="form-select form-select-sm">';
        $html .= '<option value="">' . __('-- No workflow --', 'tasksmanager') . '</option>';
        foreach ($workflows as $id => $name) {
            $selected = ((string)$id === (string)$current_val) ? ' selected' : '';
            $html    .= '<option value="' . (int)$id . '"' . $selected . '>' . htmlspecialchars($name) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    public function applyConfiguratedValueToInputUsingAnswers(
        JsonFieldInterface $config,
        array $input,
        AnswersSet $answers_set
    ): array {
        // Workflow is applied after the ticket exists — nothing to do here.
        return $input;
    }

    public function applyConfiguratedValueAfterDestinationCreation(
        FormDestination $destination,
        JsonFieldInterface $config,
        AnswersSet $answers_set,
        array $created_objects
    ): void {
        if (!($config instanceof WorkflowFieldConfig)) {
            return;
        }

        $workflows_id = (int)$config->getValue();
        if (!$workflows_id) {
            return;
        }

        foreach ($created_objects as $object) {
            if ($object instanceof Ticket) {
                Workflow::applyToTicket($object->getID(), $workflows_id);
                break;
            }
        }
    }
}
