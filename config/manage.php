<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload purposes
    |--------------------------------------------------------------------------
    |
    | The single upload endpoint (POST /manage/uploads) takes a `purpose` and looks
    | its storage rules up here, reproducing the per-field FileUpload config the
    | Filament resources declared inline.
    |
    | Image resizing and cropping happens client-side on a canvas before the upload,
    | which is what Filament's FilePond integration did too; `resize` is the target
    | the client aims for and the server only enforces `max` and `mimes`.
    |
    */

    'uploads' => [

        'show_thumbnail' => [
            'disk' => 's3',
            'directory' => 'shows/thumbnails',
            'visibility' => 'private',
            'mimes' => ['jpeg', 'jpg', 'png', 'webp'],
            'max' => 5120,
            'preserve_filename' => true,
            'resize' => null,
        ],

        'recording_thumbnail' => [
            'disk' => 's3',
            'directory' => 'recordings/thumbnails',
            'visibility' => 'private',
            'mimes' => ['jpeg', 'jpg', 'png', 'webp'],
            'max' => 5120,
            'preserve_filename' => false,
            'resize' => ['width' => 1280, 'height' => 720, 'mode' => 'cover'],
        ],

        'emote' => [
            'disk' => 's3',
            'directory' => 'emotes',
            'visibility' => 'private',
            'mimes' => ['jpeg', 'jpg', 'png', 'webp', 'gif'],
            'max' => 2048,
            'preserve_filename' => true,
            'resize' => ['width' => 64, 'height' => 64, 'mode' => 'cover', 'aspect' => '1:1'],
        ],

        'branding_logo' => [
            'disk' => 'public',
            'directory' => 'branding',
            'visibility' => 'public',
            'mimes' => ['jpeg', 'jpg', 'png', 'webp', 'svg'],
            'max' => 2048,
            'preserve_filename' => true,
            'resize' => null,
        ],

        'branding_login_image' => [
            'disk' => 'public',
            'directory' => 'branding',
            'visibility' => 'public',
            'mimes' => ['jpeg', 'jpg', 'png', 'webp'],
            'max' => 8192,
            'preserve_filename' => true,
            'resize' => null,
        ],

        'branding_login_video' => [
            'disk' => 'public',
            'directory' => 'branding',
            'visibility' => 'public',
            'mimes' => ['mp4', 'webm'],
            'max' => 51200,
            'preserve_filename' => true,
            'resize' => null,
        ],

    ],

];
