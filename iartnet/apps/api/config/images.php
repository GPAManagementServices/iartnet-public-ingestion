<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IIIF Images Root (folder di appoggio)
    |--------------------------------------------------------------------------
    |
    | Directory in cui vengono copiate le immagini importate da mirror.asset.
    | Il server IIIF va configurato per leggere le immagini da questa directory.
    |
    */

    'root' => env('IMAGES_ROOT', ''),

    /*
    |--------------------------------------------------------------------------
    | libvips (preparazione IIIF — modalità vips)
    |--------------------------------------------------------------------------
    |
    | VIPS_BIN: directory che contiene vips e vipsheader (prepended al PATH del Process).
    | BASH_PATH: eseguibile bash (su Windows: Git Bash, es. C:/Program Files/Git/bin/bash.exe).
    | IIIF_VIPS_SCRIPT: script di preparazione TIFF tiled.
    |
    */

    'vips_bin' => env('VIPS_BIN', ''),

    'bash_path' => env('BASH_PATH', 'bash'),

    'vips_script' => env('IIIF_VIPS_SCRIPT', base_path('tools/iiif_vips_tiff_prepare.sh')),

    'vips_timeout' => (int) env('IIIF_VIPS_TIMEOUT', 600),

    'vips_job_timeout' => (int) env('IIIF_VIPS_JOB_TIMEOUT', 3600),

    'pyramid_mode' => env('PYRAMID_MODE', 'auto'),

    'tile_size' => (int) env('TILE_SIZE', 512),

    'jpeg_q' => (int) env('JPEG_Q', 90),

    'pyramid_min_side' => (int) env('PYRAMID_MIN_SIDE', 4096),

    'pyramid_min_levels' => (int) env('PYRAMID_MIN_LEVELS', 4),

    'png_compression' => env('PNG_COMPRESSION', 'deflate'),

    'alpha_policy' => env('ALPHA_POLICY', 'deflate'),

    'convert_cmyk_to_srgb' => env('CONVERT_CMYK_TO_SRGB', '1'),

];
