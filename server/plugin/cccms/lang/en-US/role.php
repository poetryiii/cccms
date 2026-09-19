<?php

declare(strict_types=1);

/** Role module messages. */
return [
    'not_found'              => 'Role not found',
    'code_required'          => 'Role code cannot be empty',
    'code_exists'            => 'Role code already exists',
    'code_in_trash'          => 'Role code {code} is in the recycle bin, please restore or permanently delete it first',
    'code_generate_failed'   => 'Unable to generate a role code automatically, please specify one manually',
    'parent_not_found'       => 'Parent role not found',
    'inherit_depth_exceeded' => 'Role inheritance depth cannot exceed 5 levels',
    'cannot_delete_super'    => 'The super administrator role cannot be deleted',
    'has_children'           => 'This role has child roles and cannot be deleted',
    'copy_suffix'            => ' Copy',
];