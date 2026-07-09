<?php

use Opcodes\LogViewer\Http\Middleware\EnsureFrontendRequestsAreStateful;

$config = require base_path('vendor/opcodesio/log-viewer/config/log-viewer.php');

$config['route_path'] = 'admin/logs';
$config['middleware'] = [
    'web',
    'admin.panel',
];
$config['api_middleware'] = [
    'web',
    EnsureFrontendRequestsAreStateful::class,
    'admin.panel',
];
$config['back_to_system_url'] = '/admin';
$config['back_to_system_label'] = 'Quay lại quản trị';
$config['include_files'][] = '/var/log/supervisor/*.log';

return $config;
