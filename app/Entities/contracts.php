<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'contracts', 'label' => 'Contracts', 'singular' => 'Contract', 'module' => 'contracts', 'icon' => 'scale', 'group' => 'Finance',
    'title' => 'title', 'subtitle' => 'customer_id', 'board' => 'status', 'default_sort' => ['end_date', 'asc'],
    'search' => ['title', 'type'],
    'quick' => ['title', 'customer_id', 'value', 'start_date', 'end_date', 'status'],
    'fields' => [
        'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'list' => true],
        'customer_id' => ['type' => 'relation', 'relation' => 'customers', 'label' => 'Customer', 'required' => true, 'list' => true, 'filter' => true],
        'contact_id' => ['type' => 'relation', 'relation' => 'contacts', 'label' => 'Signatory'],
        'project_id' => ['type' => 'relation', 'relation' => 'projects', 'label' => 'Project'],
        'type' => ['type' => 'select', 'label' => 'Type', 'options' => ['Fixed Price', 'Time & Material', 'Retainer', 'AMC / Support', 'NDA', 'MSA', 'SOW', 'Staffing'], 'list' => true, 'filter' => true],
        'value' => ['type' => 'money', 'label' => 'Contract value', 'list' => true, 'aggregate' => 'sum'],
        'currency' => ['type' => 'currency', 'label' => 'Currency'],
        'start_date' => ['type' => 'date', 'label' => 'Start date', 'list' => true],
        'end_date' => ['type' => 'date', 'label' => 'End date', 'list' => true, 'filter' => true],
        'renewal_reminder_days' => ['type' => 'number', 'label' => 'Remind before (days)', 'default' => 30],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['Draft', 'Active', 'Expired', 'Terminated', 'Renewed'], 'default' => 'Draft', 'list' => true, 'filter' => true],
        'description' => ['type' => 'textarea', 'label' => 'Scope / description', 'width' => 'full'],
    ],
];
