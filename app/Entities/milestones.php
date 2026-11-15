<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'milestones', 'label' => 'Milestones', 'singular' => 'Milestone', 'module' => 'milestones', 'module_label' => 'Project milestones', 'icon' => 'flag', 'group' => 'Delivery',
    'title' => 'name', 'subtitle' => 'project_id', 'assignable' => false, 'default_sort' => ['due_date', 'asc'], 'nav' => false,
    'search' => ['name'],
    'fields' => [
        'name' => ['type' => 'text', 'label' => 'Milestone', 'required' => true, 'list' => true],
        'project_id' => ['type' => 'relation', 'relation' => 'projects', 'label' => 'Project', 'required' => true, 'list' => true, 'filter' => true],
        'due_date' => ['type' => 'date', 'label' => 'Due date', 'list' => true],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['Open', 'Completed'], 'default' => 'Open', 'list' => true, 'filter' => true],
        'amount' => ['type' => 'money', 'label' => 'Billing amount', 'list' => true],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'width' => 'full'],
    ],
];
