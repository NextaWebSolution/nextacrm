<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'quotes', 'label' => 'Quotations', 'singular' => 'Quotation', 'module' => 'quotes', 'icon' => 'file-text', 'group' => 'Sales',
    'title' => 'number', 'subtitle' => 'customer_id', 'board' => 'status', 'document' => 'quote', 'default_sort' => ['issue_date', 'desc'],
    'search' => ['number', 'subject'],
    'fields' => [
        'number' => ['type' => 'text', 'label' => 'Number', 'list' => true, 'readonly' => true],
        'customer_id' => ['type' => 'relation', 'relation' => 'customers', 'label' => 'Customer', 'required' => true, 'list' => true, 'filter' => true],
        'contact_id' => ['type' => 'relation', 'relation' => 'contacts', 'label' => 'Contact'],
        'deal_id' => ['type' => 'relation', 'relation' => 'deals', 'label' => 'Deal'],
        'project_id' => ['type' => 'relation', 'relation' => 'projects', 'label' => 'Project'],
        'subject' => ['type' => 'text', 'label' => 'Subject', 'list' => true],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['Draft', 'Sent', 'Accepted', 'Declined', 'Expired', 'Invoiced'], 'default' => 'Draft', 'list' => true, 'filter' => true],
        'issue_date' => ['type' => 'date', 'label' => 'Date', 'list' => true, 'filter' => true],
        'valid_until' => ['type' => 'date', 'label' => 'Valid until', 'list' => true],
        'currency' => ['type' => 'currency', 'label' => 'Currency'],
        'total' => ['type' => 'money', 'label' => 'Total', 'list' => true, 'readonly' => true, 'aggregate' => 'sum'],
        'accepted_by_name' => ['type' => 'text', 'label' => 'Accepted by', 'readonly' => true],
        'invoice_id' => ['type' => 'relation', 'relation' => 'invoices', 'label' => 'Invoice', 'readonly' => true],
        'notes' => ['type' => 'textarea', 'label' => 'Notes for customer', 'width' => 'full'],
        'terms' => ['type' => 'textarea', 'label' => 'Terms & conditions', 'width' => 'full'],
    ],
];
