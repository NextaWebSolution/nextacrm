<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'events', 'label' => 'Meetings & Events', 'singular' => 'Event', 'module' => 'events', 'icon' => 'calendar-days', 'group' => 'Overview', 'nav' => false,
    'title' => 'title', 'subtitle' => 'type', 'default_sort' => ['starts_at', 'desc'],
    'search' => ['title', 'location', 'description'],
    'quick' => ['title', 'type', 'starts_at', 'ends_at', 'location', 'meeting_url'],
    'assigned_label' => 'Organizer',
    'fields' => [
        'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'list' => true],
        'type' => ['type' => 'select', 'label' => 'Type', 'options' => ['Meeting', 'Call', 'Demo', 'Interview', 'Reminder', 'Other'], 'default' => 'Meeting', 'list' => true, 'filter' => true],
        'starts_at' => ['type' => 'datetime', 'label' => 'Starts', 'required' => true, 'list' => true, 'filter' => true],
        'ends_at' => ['type' => 'datetime', 'label' => 'Ends', 'list' => true],
        'all_day' => ['type' => 'bool', 'label' => 'All day'],
        'location' => ['type' => 'text', 'label' => 'Location', 'list' => true],
        'meeting_url' => ['type' => 'url', 'label' => 'Meeting link'],
        'reminder_minutes' => ['type' => 'select', 'label' => 'Reminder', 'options' => ['' => 'None', '10' => '10 minutes before', '30' => '30 minutes before', '60' => '1 hour before', '1440' => '1 day before']],
        'related_entity' => ['type' => 'hidden', 'label' => 'Related type'],
        'related_id' => ['type' => 'hidden', 'label' => 'Related record'],
        'description' => ['type' => 'textarea', 'label' => 'Agenda / notes', 'width' => 'full'],
    ],
];
