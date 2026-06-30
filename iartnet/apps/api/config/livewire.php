<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Temporary File Upload - Max Size
    |---------------------------------------------------------------------------
    |
    | Livewire validates temporary uploads with default max:12288 (12MB).
    | Override to allow 1GB for ICCD/Import zip uploads.
    | Value is in kilobytes: 1024 * 1024 = 1048576.
    |
    | Se l'upload fallisce ancora, verificare:
    | - PHP: upload_max_filesize e post_max_size >= 1G (es. in php.ini o .env)
    | - NGINX: client_max_body_size 1024m; (in server o http block)
    |
    */

    'temporary_file_upload' => [
        'rules' => ['required', 'file', 'max:1048576'], // 1 GB
    ],

];
