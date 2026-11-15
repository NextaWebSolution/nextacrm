<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'tickets', 'label' => 'Tickets', 'singular' => 'Ticket', 'module' => 'tickets', 'icon' => 'life-buoy', 'group' => 'Support',
    'title' => 'subject', 'subtitle' => 'number', 'board' => 'status', 'default_sort' => ['created_at', 'desc'],
    'search' => ['number', 'subject', 'requester_email', 'requester_name'],
    'quick' => ['subject', 'customer_id', 'contact_id', 'priority', 'type', 'assigned_to', 'description'],
    'fields' => [
        'number' => ['type' => 'text', 'label' => 'Ticket #', 'list' => true, 'readonly' => true],
        'subject' => ['type' => 'text', 'label' => 'Subject', 'required' => true, 'list' => true],
        'customer_id' => ['type' => 'relation', 'relation' => 'customers', 'label' => 'Customer', 'list' => true, 'filter' => true],
        'contact_id' => ['type' => 'relation', 'relation' => 'contacts', 'label' => 'Contact'],
        'project_id' => ['type' => 'relation', 'relation' => 'projects', 'label' => 'Project', 'filter' => true],
        'priority' => ['type' => 'select', 'label' => 'Priority', 'options' => ['Low', 'Medium', 'High', 'Urgent'], 'default' => 'Medium', 'list' => true, 'filter' => true],
        'type' => ['type' => 'select', 'label' => 'Type', 'options' => 'picklist:ticket_type', 'filter' => true],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => 'picklist:ticket_status', 'default' => 'Open', 'list' => true, 'filter' => true],
        'channel' => ['type' => 'select', 'label' => 'Channel', 'options' => ['Web', 'Email', 'Phone', 'Chat', 'WhatsApp', 'Portal', 'API'], 'default' => 'Web', 'filter' => true],
        'requester_name' => ['type' => 'text', 'label' => 'Requester name'],
        'requester_email' => ['type' => 'email', 'label' => 'Requester email'],
        'sla_status' => ['type' => 'select', 'label' => 'SLA', 'options' => ['On Track', 'At Risk', 'Breached', 'Met'], 'readonly' => true, 'list' => true, 'filter' => true],
        'first_response_due' => ['type' => 'datetime', 'label' => 'First response due', 'readonly' => true],
        'resolution_due' => ['type' => 'datetime', 'label' => 'Resolution due', 'readonly' => true, 'list' => true],
        'first_responded_at' => ['type' => 'datetime', 'label' => 'First responded', 'readonly' => true],
        'resolved_at' => ['type' => 'datetime', 'label' => 'Resolved', 'readonly' => true],
        'satisfaction' => ['type' => 'select', 'label' => 'Satisfaction', 'options' => ['1' => '1 – Poor', '2' => '2', '3' => '3', '4' => '4', '5' => '5 – Excellent']],
        'tags' => ['type' => 'tags', 'label' => 'Tags', 'filter' => true],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'width' => 'full'],
    ],
    'related' => [
        ['entity' => 'tasks', 'morph' => true, 'label' => 'Tasks'],
    ],
];
