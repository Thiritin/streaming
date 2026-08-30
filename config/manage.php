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
    | Everything lands on `s3`. The local `public` disk cannot be used: app pods are
    | replicas with their own ephemeral filesystems, so a file uploaded through one of
    | them is invisible to the other nine and gone at the next deploy - which is exactly
    | what happened to the branding logo. The `branding/*` objects are the only ones
    | stored with public visibility, so a logo on the login page is a plain cacheable
    | URL rather than a signed one that expires; everything else stays private and is
    | read through a temporary URL.
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
            'disk' => 's3',
            'directory' => 'branding',
            'visibility' => 'public',
            'mimes' => ['jpeg', 'jpg', 'png', 'webp', 'svg'],
            'max' => 2048,
            'preserve_filename' => true,
            'resize' => null,
        ],

        'branding_favicon' => [
            'disk' => 's3',
            'directory' => 'branding',
            'visibility' => 'public',
            // .ico included because it is still what a hand-made favicon arrives as,
            // and every browser reads one.
            'mimes' => ['png', 'svg', 'ico', 'webp'],
            'max' => 512,
            'preserve_filename' => true,
            'resize' => null,
        ],

        'branding_login_image' => [
            'disk' => 's3',
            'directory' => 'branding',
            'visibility' => 'public',
            'mimes' => ['jpeg', 'jpg', 'png', 'webp'],
            'max' => 8192,
            'preserve_filename' => true,
            'resize' => null,
        ],

    ],

];
