<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'products', 'label' => 'Products & Services', 'singular' => 'Product', 'module' => 'products', 'icon' => 'package', 'group' => 'Finance',
    'title' => 'name', 'subtitle' => 'sku', 'assignable' => false, 'default_sort' => ['name', 'asc'],
    'search' => ['name', 'sku', 'description'],
    'quick' => ['name', 'type', 'price', 'unit', 'tax_id'],
    'fields' => [
        'name' => ['type' => 'text', 'label' => 'Name', 'required' => true, 'list' => true],
        'sku' => ['type' => 'text', 'label' => 'SKU / Code', 'list' => true],
        'type' => ['type' => 'select', 'label' => 'Type', 'options' => ['Service', 'Product', 'Subscription', 'License'], 'default' => 'Service', 'list' => true, 'filter' => true],
        'unit' => ['type' => 'select', 'label' => 'Unit', 'options' => ['unit', 'hour', 'day', 'month', 'year', 'page', 'project', 'license', 'seat'], 'default' => 'unit', 'list' => true],
        'price' => ['type' => 'money', 'label' => 'Price', 'required' => true, 'list' => true],
        'cost' => ['type' => 'money', 'label' => 'Cost'],
        'tax_id' => ['type' => 'tax', 'label' => 'Default tax', 'list' => true],
        'hsn_sac' => ['type' => 'text', 'label' => 'HSN / SAC'],
        'is_active' => ['type' => 'bool', 'label' => 'Active', 'default' => 1, 'list' => true, 'filter' => true],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'width' => 'full'],
    ],
];
