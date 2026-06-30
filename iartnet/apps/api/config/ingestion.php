<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingestion Filesystem Root
    |--------------------------------------------------------------------------
    |
    | This value is the root directory path for the ingestion workflow.
    | All ingestion paths (extraction, temporary files, run storage) derive
    | from this root directory. Set this in your ".env" file using the
    | INGEST_FS_ROOT environment variable.
    |
    | Example: /var/www/iartnet/storage/ingestion
    |
    */

    'fs_root' => env('INGEST_FS_ROOT', storage_path('app/ingestion')),

];
