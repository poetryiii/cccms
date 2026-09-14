<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class User extends BaseModel
{
    protected $name = 'user';
    protected $hidden = ['password'];
}
