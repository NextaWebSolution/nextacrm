<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'time_entries', 'label' => 'Timesheets', 'singular' => 'Time entry', 'module' => 'time_entries', 'icon' => 'clock', 'group' => 'Delivery',
    'title' => 'description', 'subtitle' => 'project_id', 'default_sort' => ['entry_date', 'desc'],
    'search' => ['description'],
    'quick' => ['project_id', 'task_id', 'entry_date', 'minutes', 'is_billable', 'description'],
    'assigned_label' => 'Team member',
    'fields' => [
        'entry_date' => ['type' => 'date', 'label' => 'Date', 'required' => true, 'list' => true, 'filter' => true],
        'project_id' => ['type' => 'relation', 'relation' => 'projects', 'label' => 'Project', 'list' => true, 'filter' => true],
        'task_id' => ['type' => 'relation', 'relation' => 'tasks', 'label' => 'Task', 'list' => true, 'depends' => 'project_id'],
        'minutes' => ['type' => 'duration', 'label' => 'Duration', 'required' => true, 'list' => true, 'aggregate' => 'sum'],
        'is_billable' => ['type' => 'bool', 'label' => 'Billable', 'default' => 1, 'list' => true, 'filter' => true],
        'hourly_rate' => ['type' => 'money', 'label' => 'Rate / hour'],
        'is_invoiced' => ['type' => 'bool', 'label' => 'Invoiced', 'readonly' => true, 'list' => true, 'filter' => true],
        'is_running' => ['type' => 'bool', 'label' => 'Timer running', 'readonly' => true],
        'started_at' => ['type' => 'datetime', 'label' => 'Started', 'readonly' => true],
        'description' => ['type' => 'textarea', 'label' => 'What did you work on?', 'list' => true, 'width' => 'full'],
    ],
];
