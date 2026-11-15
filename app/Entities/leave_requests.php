<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'leave_requests', 'label' => 'Leave Requests', 'singular' => 'Leave request', 'module' => 'leave_requests', 'icon' => 'umbrella', 'group' => 'Team',
    'title' => 'type', 'subtitle' => 'employee_id', 'board' => 'status', 'default_sort' => ['start_date', 'desc'],
    'search' => ['reason'],
    'quick' => ['employee_id', 'type', 'start_date', 'end_date', 'reason'],
    'approvable' => true, 'assigned_label' => 'Approver',
    'fields' => [
        'employee_id' => ['type' => 'relation', 'relation' => 'employees', 'label' => 'Employee', 'required' => true, 'list' => true, 'filter' => true],
        'type' => ['type' => 'select', 'label' => 'Leave type', 'options' => 'picklist:leave_type', 'required' => true, 'list' => true, 'filter' => true],
        'start_date' => ['type' => 'date', 'label' => 'From', 'required' => true, 'list' => true, 'filter' => true],
        'end_date' => ['type' => 'date', 'label' => 'To', 'required' => true, 'list' => true],
        'days' => ['type' => 'decimal', 'label' => 'Days', 'list' => true, 'help' => 'Calculated from dates (weekdays) if empty'],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['Pending', 'Approved', 'Rejected', 'Cancelled'], 'default' => 'Pending', 'list' => true, 'filter' => true, 'approval' => true],
        'approved_by' => ['type' => 'user', 'label' => 'Reviewed by', 'readonly' => true],
        'review_note' => ['type' => 'text', 'label' => 'Review note', 'readonly' => true],
        'reason' => ['type' => 'textarea', 'label' => 'Reason', 'width' => 'full'],
    ],
];
