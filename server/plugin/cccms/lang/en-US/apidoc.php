<?php

declare(strict_types=1);

/** API docs (F1) messages. */
return [
    'not_found' => 'No such endpoint or no generated doc (id looks like "GET /user")',

    // First level of the tree: plugin. Every plugin may declare its own `plugin_{plugin}` in
    // `plugin/{plugin}/lang/{locale}/apidoc.php` (namespaces are merged); without it the title
    // falls back to config/app.php or the first menu title in db/menu.php (OpenApi::pluginTitle).
    'plugin_cccms' => 'Built-in module',
    'plugin_index' => 'Frontend site',

    // Group titles for controllers that expose only public endpoints (no permission node)
    'group_auth' => 'Authentication',
    'group_home' => 'Frontend site',
    'group_observability' => 'Observability',
    // Export tasks and profile have no standalone menu entry but deserve their own group
    'group_export' => 'Export tasks',
    'group_profile' => 'Profile',
];
