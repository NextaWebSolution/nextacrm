<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'departments', 'label' => 'Departments', 'singular' => 'Department', 'module' => 'departments', 'icon' => 'network', 'group' => 'Team',
    'title' => 'name', 'assignable' => false, 'default_sort' => ['name', 'asc'],
    'search' => ['name'],
    'fields' => [
        'name' => ['type' => 'text', 'label' => 'Department', 'required' => true, 'list' => true],
        'head_user_id' => ['type' => 'user', 'label' => 'Head', 'list' => true],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'width' => 'full', 'list' => true],
    ],
    'related' => [
        ['entity' => 'employees', 'fk' => 'department_id', 'label' => 'Employees'],
    ],
];
