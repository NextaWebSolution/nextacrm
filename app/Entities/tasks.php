<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'tasks', 'label' => 'Tasks', 'singular' => 'Task', 'module' => 'tasks', 'icon' => 'square-check-big', 'group' => 'Delivery',
    'title' => 'title', 'subtitle' => 'project_id', 'board' => 'status', 'default_sort' => ['due_date', 'asc'], 'sortable_board' => true,
    'search' => ['title', 'description'],
    'quick' => ['title', 'project_id', 'assigned_to', 'priority', 'due_date', 'estimate_hours'],
    'assigned_label' => 'Assignee',
    'fields' => [
        'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'list' => true],
        'project_id' => ['type' => 'relation', 'relation' => 'projects', 'label' => 'Project', 'list' => true, 'filter' => true],
        'milestone_id' => ['type' => 'relation', 'relation' => 'milestones', 'label' => 'Milestone', 'depends' => 'project_id'],
        'parent_id' => ['type' => 'relation', 'relation' => 'tasks', 'label' => 'Parent task'],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => 'picklist:task_status', 'default' => 'To Do', 'list' => true, 'filter' => true],
        'priority' => ['type' => 'select', 'label' => 'Priority', 'options' => ['Low', 'Medium', 'High', 'Urgent'], 'default' => 'Medium', 'list' => true, 'filter' => true],
        'start_date' => ['type' => 'date', 'label' => 'Start date'],
        'due_date' => ['type' => 'date', 'label' => 'Due date', 'list' => true, 'filter' => true],
        'estimate_hours' => ['type' => 'decimal', 'label' => 'Estimate (h)'],
        'spent_hours' => ['type' => 'decimal', 'label' => 'Logged (h)', 'readonly' => true, 'list' => true],
        'progress' => ['type' => 'percent', 'label' => 'Progress'],
        'is_billable' => ['type' => 'bool', 'label' => 'Billable', 'filter' => true],
        'depends_on' => ['type' => 'text', 'label' => 'Blocked by (task IDs)', 'help' => 'Comma-separated task IDs that must be done first'],
        'related_entity' => ['type' => 'hidden', 'label' => 'Related type'],
        'related_id' => ['type' => 'hidden', 'label' => 'Related record'],
        'completed_at' => ['type' => 'datetime', 'label' => 'Completed', 'readonly' => true],
        'tags' => ['type' => 'tags', 'label' => 'Tags', 'filter' => true],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'width' => 'full'],
    ],
    'related' => [
        ['entity' => 'tasks', 'fk' => 'parent_id', 'label' => 'Subtasks'],
        ['entity' => 'time_entries', 'fk' => 'task_id', 'label' => 'Time'],
    ],
];
