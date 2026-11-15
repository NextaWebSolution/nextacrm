<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'projects', 'label' => 'Projects', 'singular' => 'Project', 'module' => 'projects', 'icon' => 'folder-kanban', 'group' => 'Delivery',
    'title' => 'name', 'subtitle' => 'customer_id', 'board' => 'status', 'default_sort' => ['created_at', 'desc'],
    'search' => ['name', 'code'],
    'quick' => ['name', 'customer_id', 'billing_type', 'budget', 'start_date', 'due_date', 'assigned_to'],
    'assigned_label' => 'Project manager',
    'fields' => [
        'name' => ['type' => 'text', 'label' => 'Project name', 'required' => true, 'list' => true],
        'code' => ['type' => 'text', 'label' => 'Code'],
        'customer_id' => ['type' => 'relation', 'relation' => 'customers', 'label' => 'Customer', 'list' => true, 'filter' => true],
        'deal_id' => ['type' => 'relation', 'relation' => 'deals', 'label' => 'Deal'],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => 'picklist:project_status', 'default' => 'Not Started', 'list' => true, 'filter' => true],
        'priority' => ['type' => 'select', 'label' => 'Priority', 'options' => ['Low', 'Medium', 'High', 'Urgent'], 'default' => 'Medium', 'filter' => true],
        'billing_type' => ['type' => 'select', 'label' => 'Billing', 'options' => ['Fixed', 'Hourly', 'Retainer', 'Non-billable'], 'default' => 'Fixed', 'filter' => true],
        'budget' => ['type' => 'money', 'label' => 'Budget', 'list' => true, 'aggregate' => 'sum'],
        'hourly_rate' => ['type' => 'money', 'label' => 'Hourly rate'],
        'currency' => ['type' => 'currency', 'label' => 'Currency'],
        'start_date' => ['type' => 'date', 'label' => 'Start date'],
        'due_date' => ['type' => 'date', 'label' => 'Deadline', 'list' => true, 'filter' => true],
        'progress' => ['type' => 'percent', 'label' => 'Progress', 'list' => true, 'readonly' => true, 'help' => 'Calculated from tasks'],
        'tags' => ['type' => 'tags', 'label' => 'Tags', 'filter' => true],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'width' => 'full'],
    ],
    'related' => [
        ['entity' => 'tasks', 'fk' => 'project_id', 'label' => 'Tasks'],
        ['entity' => 'milestones', 'fk' => 'project_id', 'label' => 'Milestones'],
        ['entity' => 'time_entries', 'fk' => 'project_id', 'label' => 'Time'],
        ['entity' => 'invoices', 'fk' => 'project_id', 'label' => 'Invoices'],
        ['entity' => 'expenses', 'fk' => 'project_id', 'label' => 'Expenses'],
        ['entity' => 'tickets', 'fk' => 'project_id', 'label' => 'Tickets'],
    ],
];
