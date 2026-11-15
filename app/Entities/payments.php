<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'payments', 'label' => 'Payments', 'singular' => 'Payment', 'module' => 'payments', 'icon' => 'credit-card', 'group' => 'Finance',
    'title' => 'reference', 'subtitle' => 'invoice_id', 'assignable' => false, 'default_sort' => ['paid_on', 'desc'],
    'search' => ['reference', 'notes'],
    'quick' => ['invoice_id', 'amount', 'paid_on', 'method', 'reference'],
    'fields' => [
        'invoice_id' => ['type' => 'relation', 'relation' => 'invoices', 'label' => 'Invoice', 'required' => true, 'list' => true, 'filter' => true],
        'customer_id' => ['type' => 'relation', 'relation' => 'customers', 'label' => 'Customer', 'list' => true, 'filter' => true, 'readonly' => true],
        'amount' => ['type' => 'money', 'label' => 'Amount', 'required' => true, 'list' => true, 'aggregate' => 'sum'],
        'currency' => ['type' => 'currency', 'label' => 'Currency', 'readonly' => true],
        'paid_on' => ['type' => 'date', 'label' => 'Paid on', 'required' => true, 'list' => true, 'filter' => true],
        'method' => ['type' => 'select', 'label' => 'Method', 'options' => 'picklist:payment_method', 'list' => true, 'filter' => true],
        'reference' => ['type' => 'text', 'label' => 'Reference / UTR', 'list' => true],
        'notes' => ['type' => 'textarea', 'label' => 'Notes', 'width' => 'full'],
    ],
];
