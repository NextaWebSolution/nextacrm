<?php
defined('NX_ROOT') || exit;
return [
    'table' => 'kb_articles', 'label' => 'Knowledge Base', 'singular' => 'Article', 'module' => 'kb_articles', 'icon' => 'book-open', 'group' => 'Support',
    'title' => 'title', 'subtitle' => 'category', 'assignable' => false, 'board' => 'status', 'default_sort' => ['updated_at', 'desc'],
    'search' => ['title', 'body', 'tags'],
    'fields' => [
        'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'list' => true],
        'category' => ['type' => 'select', 'label' => 'Category', 'options' => 'picklist:kb_category', 'list' => true, 'filter' => true],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['Draft', 'Published', 'Archived'], 'default' => 'Draft', 'list' => true, 'filter' => true],
        'is_public' => ['type' => 'bool', 'label' => 'Show in public help centre', 'filter' => true, 'help' => 'Published public articles appear at /help. Supports **bold**, _italic_, # headings, - lists, `code` and [links](https://…).'],
        'views' => ['type' => 'number', 'label' => 'Views', 'readonly' => true, 'list' => true],
        'tags' => ['type' => 'tags', 'label' => 'Tags', 'filter' => true],
        'body' => ['type' => 'textarea', 'label' => 'Article', 'width' => 'full', 'rows' => 14, 'required' => true],
    ],
];
