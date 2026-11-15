<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'invoices', 'label' => 'Invoices', 'singular' => 'Invoice', 'module' => 'invoices', 'icon' => 'receipt', 'group' => 'Finance',
    'title' => 'number', 'subtitle' => 'customer_id', 'board' => 'status', 'document' => 'invoice', 'default_sort' => ['issue_date', 'desc'],
    'search' => ['number', 'subject'],
    'fields' => [
        'number' => ['type' => 'text', 'label' => 'Number', 'list' => true, 'readonly' => true],
        'customer_id' => ['type' => 'relation', 'relation' => 'customers', 'label' => 'Customer', 'required' => true, 'list' => true, 'filter' => true],
        'contact_id' => ['type' => 'relation', 'relation' => 'contacts', 'label' => 'Contact'],
        'project_id' => ['type' => 'relation', 'relation' => 'projects', 'label' => 'Project', 'filter' => true],
        'subject' => ['type' => 'text', 'label' => 'Subject'],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['Draft', 'Sent', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled'], 'default' => 'Draft', 'list' => true, 'filter' => true, 'readonly' => true],
        'issue_date' => ['type' => 'date', 'label' => 'Invoice date', 'list' => true, 'filter' => true],
        'due_date' => ['type' => 'date', 'label' => 'Due date', 'list' => true, 'filter' => true],
        'currency' => ['type' => 'currency', 'label' => 'Currency'],
        'total' => ['type' => 'money', 'label' => 'Total', 'list' => true, 'readonly' => true, 'aggregate' => 'sum'],
        'amount_paid' => ['type' => 'money', 'label' => 'Paid', 'readonly' => true, 'aggregate' => 'sum'],
        'balance' => ['type' => 'money', 'label' => 'Balance due', 'list' => true, 'readonly' => true, 'aggregate' => 'sum'],
        'is_recurring' => ['type' => 'bool', 'label' => 'Recurring', 'filter' => true],
        'recurring_interval' => ['type' => 'select', 'label' => 'Repeat every', 'options' => ['Weekly', 'Monthly', 'Quarterly', 'Half-yearly', 'Yearly']],
        'recurring_next_date' => ['type' => 'date', 'label' => 'Next invoice on'],
        'recurring_end_date' => ['type' => 'date', 'label' => 'Stop repeating after'],
        'quote_id' => ['type' => 'relation', 'relation' => 'quotes', 'label' => 'From quotation', 'readonly' => true],
        'notes' => ['type' => 'textarea', 'label' => 'Notes for customer', 'width' => 'full'],
        'terms' => ['type' => 'textarea', 'label' => 'Terms & conditions', 'width' => 'full'],
    ],
    'related' => [
        ['entity' => 'payments', 'fk' => 'invoice_id', 'label' => 'Payments'],
    ],
];
