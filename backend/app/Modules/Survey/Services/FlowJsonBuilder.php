<?php

namespace App\Modules\Survey\Services;

use App\Models\SurveyForm;

class FlowJsonBuilder
{
    // Bump if Meta ships a newer Flow JSON schema version — check
    // https://developers.facebook.com/docs/whatsapp/flows/reference/flowjson
    // before changing this; an outdated version can get the flow rejected at publish time.
    private const FLOW_JSON_VERSION = '6.3';

    private const SCREEN_ID = 'SURVEY';

    // Build the full Flow JSON from a survey form's field list. Every field the
    // client added becomes one form component on a single terminal screen —
    // no branching, no per-screen server calls, so no data-exchange endpoint needed.
    public function build(SurveyForm $form): array
    {
        $children = [
            ['type' => 'TextHeading', 'text' => $this->truncate($form->name, 80)],
        ];

        if ($form->description) {
            $children[] = ['type' => 'TextBody', 'text' => $this->truncate($form->description, 4096)];
        }

        $payloadFields = [];

        foreach ($form->fields ?? [] as $field) {
            $children[] = $this->buildComponent($field);
            $payloadFields[$field['key']] = '${form.' . $field['key'] . '}';
        }

        $children[] = [
            'type'  => 'Footer',
            'label' => 'Submit',
            'on-click-action' => [
                'name'    => 'complete',
                'payload' => $payloadFields,
            ],
        ];

        return [
            'version' => self::FLOW_JSON_VERSION,
            'screens' => [[
                'id'       => self::SCREEN_ID,
                'title'    => $this->truncate($form->name, 30),
                'terminal' => true,
                'success'  => true,
                'data'     => new \stdClass(),
                'layout'   => [
                    'type'     => 'SingleColumnLayout',
                    'children' => $children,
                ],
            ]],
        ];

        // return [
        //     'version' => self::FLOW_JSON_VERSION,
        //     'screens' => [[
        //         'id'       => self::SCREEN_ID,
        //         'title'    => $this->truncate($form->name, 30),
        //         'terminal' => true,
        //         'success'  => true,
        //         'data'     => [],
        //         'layout'   => [
        //             'type'     => 'SingleColumnLayout',
        //             'children' => $children,
        //         ],
        //     ]],
        // ];
    }

    // One form component per field type. Choice fields use RadioButtonsGroup for
    // small option sets (fits the bottom sheet cleanly) and Dropdown once it'd get
    // crowded — same threshold Meta's own examples use.
    private function buildComponent(array $field): array
    {
        $type     = $field['type'] ?? 'text';
        $key      = $field['key'];
        $label    = $this->truncate($field['question_text'], 80);
        $required = (bool) ($field['required'] ?? false);

        if ($type === 'number') {
            return [
                'type'       => 'TextInput',
                'name'       => $key,
                'label'      => $label,
                'input-type' => 'number',
                'required'   => $required,
            ];
        }

        if ($type === 'choice') {
            $options = array_values($field['options'] ?? []);
            $dataSource = array_map(fn($opt) => ['id' => $opt, 'title' => $this->truncate($opt, 30)], $options);

            return [
                'type'        => count($options) <= 4 ? 'RadioButtonsGroup' : 'Dropdown',
                'name'        => $key,
                'label'       => $label,
                'required'    => $required,
                'data-source' => $dataSource,
            ];
        }

        // default: text
        return [
            'type'       => 'TextInput',
            'name'       => $key,
            'label'      => $label,
            'input-type' => 'text',
            'required'   => $required,
        ];
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
