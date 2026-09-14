<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    // 客户端驱动：装了 phpredis 扩展就用扩展（更快），否则回退到纯 PHP 的 predis/predis。
    // 之前这里缺失，默认取 phpredis，导致没装扩展时直接报 Class "Redis" not found。
    'client' => extension_loaded('redis') ? 'phpredis' : 'predis',

    'default' => [
        'password' => '',
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ]
];
