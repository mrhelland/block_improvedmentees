<?php
// Capabilities for the Improved Mentees block.

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Capability to add the block on system or course pages.
    'block/improvedmentees:addinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],

    // Capability to add the block to the dashboard ("My home").
    'block/improvedmentees:myaddinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/my:manageblocks',
    ],
];
