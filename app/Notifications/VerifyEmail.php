<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's own verification mail, queued, for the same reason as ResetPassword:
 * the account is created before the message goes out.
 */
class VerifyEmail extends BaseVerifyEmail implements ShouldQueue
{
    use Queueable;
}
