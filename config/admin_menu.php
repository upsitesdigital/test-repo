<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Add a top-level menu page.
 *
 * This function takes a capability which will be used to determine whether
 * or not a page is included in the menu.
 *
 * The function which is hooked in to handle the output of the page must check
 * that the user has the required capability as well.
 *
 * @global array $menu
 * @global array $admin_page_hooks
 * @global array $_registered_pages
 * @global array $_parent_pages
 *
 * @param string   $page_title The text to be displayed in the title tags of the page when the menu is selected.
 * @param string   $menu_title The text to be used for the menu.
 * @param string   $capability The capability required for this menu to be displayed to the user.
 * @param string   $menu_slug  The slug name to refer to this menu by. Should be unique for this menu page and only
 *                             include lowercase alphanumeric, dashes, and underscores characters to be compatible
 *                             with sanitize_key().
 * @param callable $function   The function to be called to output the content for this page.
 * @param string   $icon_url   The URL to the icon to be used for this menu.
 *                             * Pass a base64-encoded SVG using a data URI, which will be colored to match
 *                               the color scheme. This should begin with 'data:image/svg+xml;base64,'.
 *                             * Pass the name of a Dashicons helper class to use a font icon,
 *                               e.g. 'dashicons-chart-pie'.
 *                             * Pass 'none' to leave div.wp-menu-image empty so an icon can be added via CSS.
 * @param int      $position   The position in the menu order this one should appear.
 * @return string The resulting page's hook_suffix.
 */
return [
    [
        'slug' => 'jobconvo',
        'page_title' => 'Jobconvo',
        'menu_title' => 'Jobconvo',
        'capability' => 'jobconvo-editor',
        'function' => 'adminSettings',
        'icon_url' => 'dashicons-groups',
        'position' => 5,
        'submenu' => [
            [
                'slug' => 'jobconvo-logs',
                'page_title' => 'API Logs',
                'menu_title' => 'Logs',
                'capability' => 'jobconvo-editor',
                'function' => 'adminLogs',
                'position' => 1
            ]
        ]

    ]
];
