<?php

declare(strict_types=1);

/** Tenant (multi-tenancy isolation) messages. */
return [
    // Platform tenant (virtual tenant; no row in the database)
    'platform_name'   => 'Platform',
    'platform_remark' => 'Platform tenant (built-in; cannot be edited or deleted)',

    // Record read / write
    'not_found'             => 'The tenant does not exist',
    'name_required'         => 'Please enter the tenant name',
    'code_required'         => 'Please enter the tenant code',
    'code_invalid'          => 'The tenant code may only contain letters, digits, underscores and hyphens, 2-64 characters',
    'code_exists'           => 'The tenant code already exists',
    'code_in_trashed'       => 'The tenant code {code} is in the recycle bin; please restore or permanently delete it first',
    'cannot_edit_platform'  => 'The platform tenant is built-in and cannot be edited',
    'cannot_delete_platform' => 'The platform tenant is built-in and cannot be deleted',
    'has_users'             => 'This tenant still has {count} account(s); please move or delete them before deleting the tenant',

    // Permission and switching
    'super_only'    => 'Only a super administrator can perform this action',
    'platform_only' => 'This action is only allowed under the platform tenant; please switch back to the platform first',
    'not_usable'    => 'This tenant is disabled or expired and cannot be switched to',
    'switched'      => 'Tenant switched',
];