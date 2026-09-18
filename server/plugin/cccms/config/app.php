<?php

return [
    'enable'  => true,

    // 框架版本号，与上游仓库的 git tag 对应。`cccms:update` 用它提示是否落后
    'version' => 'v0.0.1',

    // 国际化：默认语言与可用语言（I18n::locale() 的最终回落值；前端 /config/ui 也下发它）
    'locale'  => 'zh-CN',
    'locales' => ['zh-CN', 'en-US'],
];
