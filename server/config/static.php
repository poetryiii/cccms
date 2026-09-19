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

/**
 * Static file settings
 */
return [
    'enable' => true,
    'middleware' => [     // Static file Middleware
        // 本地存储（public/storage）与后台同域直出，统一加 nosniff 并对非图片/PDF
        // 强制下载，避免含脚本的存量附件被内联渲染成存储型 XSS。
        plugin\cccms\app\middleware\StorageGuard::class,
    ],
];