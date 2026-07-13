<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'clinical-payloads' => [
            'driver' => 'local',
            'root' => env('CLINICAL_PAYLOADS_LOCAL_ROOT', storage_path('app/clinical-payloads')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'clinical-payloads-s3' => [
            'driver' => 's3',
            'key' => env('CLINICAL_PAYLOADS_S3_ACCESS_KEY_ID'),
            'secret' => env('CLINICAL_PAYLOADS_S3_SECRET_ACCESS_KEY'),
            'token' => env('CLINICAL_PAYLOADS_S3_SESSION_TOKEN'),
            'region' => env('CLINICAL_PAYLOADS_S3_REGION', 'us-east-1'),
            'bucket' => env('CLINICAL_PAYLOADS_S3_BUCKET'),
            'endpoint' => env('CLINICAL_PAYLOADS_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('CLINICAL_PAYLOADS_S3_PATH_STYLE', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
            'options' => array_filter([
                'ServerSideEncryption' => env('CLINICAL_PAYLOADS_S3_SSE', 'aws:kms'),
                'SSEKMSKeyId' => env('CLINICAL_PAYLOADS_S3_KMS_KEY_ID'),
            ], fn (mixed $value): bool => filled($value)),
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
