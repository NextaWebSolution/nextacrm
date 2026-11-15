<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'expenses', 'label' => 'Expenses', 'singular' => 'Expense', 'module' => 'expenses', 'icon' => 'wallet', 'group' => 'Finance',
    'title' => 'title', 'subtitle' => 'category', 'board' => 'status', 'default_sort' => ['expense_date', 'desc'],
    'search' => ['title', 'vendor', 'notes'],
    'quick' => ['title', 'category', 'amount', 'expense_date', 'vendor', 'project_id'],
    'approvable' => true,
    'fields' => [
        'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'list' => true],
        'category' => ['type' => 'select', 'label' => 'Category', 'options' => 'picklist:expense_category', 'list' => true, 'filter' => true],
        'amount' => ['type' => 'money', 'label' => 'Amount', 'required' => true, 'list' => true, 'aggregate' => 'sum'],
        'tax_amount' => ['type' => 'money', 'label' => 'Tax included'],
        'currency' => ['type' => 'currency', 'label' => 'Currency'],
        'expense_date' => ['type' => 'date', 'label' => 'Date', 'required' => true, 'list' => true, 'filter' => true],
        'vendor' => ['type' => 'text', 'label' => 'Vendor', 'list' => true],
        'payment_method' => ['type' => 'select', 'label' => 'Paid via', 'options' => 'picklist:payment_method'],
        'customer_id' => ['type' => 'relation', 'relation' => 'customers', 'label' => 'Customer', 'filter' => true],
        'project_id' => ['type' => 'relation', 'relation' => 'projects', 'label' => 'Project', 'filter' => true],
        'is_billable' => ['type' => 'bool', 'label' => 'Billable to customer', 'filter' => true],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['Pending', 'Approved', 'Rejected', 'Reimbursed'], 'default' => 'Pending', 'list' => true, 'filter' => true, 'approval' => true],
        'approved_by' => ['type' => 'user', 'label' => 'Approved by', 'readonly' => true],
        'notes' => ['type' => 'textarea', 'label' => 'Notes', 'width' => 'full'],
    ],
];
