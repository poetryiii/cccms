<?php

use plugin\cccms\support\DatabaseBootstrap;
use plugin\cccms\support\SecurityBootstrap;

return [
    // 安全自检必须最先执行：密钥不合法时直接终止进程，不进入后续启动流程
    SecurityBootstrap::class,
    DatabaseBootstrap::class,
];
