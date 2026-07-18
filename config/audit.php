<?php

return [
    'retention_days' => max((int) env('AUDIT_LOG_RETENTION_DAYS', 180), 30),
];
