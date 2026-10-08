<?php

return [
    'enabled' => env('OFFICE_LICENSE_ENABLED', false),
    'state_dir' => env('OFFICE_AGENT_STATE_DIR', '/run/office-agent/public'),
    'uuid_file' => env('OFFICE_AGENT_UUID_FILE', '/run/office-agent/product_uuid'),
    'machine_file' => env('OFFICE_AGENT_MACHINE_FILE', '/run/office-agent/machine-id'),
    'socket' => env('OFFICE_AGENT_SOCKET', '/run/office-agent/control/control.sock'),
    'control_token' => env('OFFICE_AGENT_CONTROL_TOKEN', ''),
    'control_token_file' => '/run/office-agent/control/token',
];
