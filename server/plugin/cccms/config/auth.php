<?php

return [
    // HS256 要求密钥 >= 32 字节；生产环境务必经环境变量 JWT_SECRET 注入随机密钥
    'secret' => getenv('JWT_SECRET') ?: 'cccms-local-dev-secret-0123456789abcdef-0123456789abcdef-0123456789abcdef',
    // accessToken 有效期（秒），默认 7 天
    'ttl' => 604800,
    // 字段级 encrypt 密钥（AES-256-GCM 种子，经 sha256 规整为 32 字节）。
    // 生产环境务必经环境变量 DATA_ENCRYPT_KEY 注入；
    // 已确认**不做密钥轮换**，一旦更换，历史加密字段将无法解密。
    'data_encrypt_key' => getenv('DATA_ENCRYPT_KEY') ?: 'cccms-local-dev-crypto-key-0123456789abcdef',
];
