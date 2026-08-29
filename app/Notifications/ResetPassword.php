<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's own reset mail, queued.
 *
 * The token row is written before the message is sent, so an installation whose mail
 * is down answered 500 on a request that had already half succeeded. Queued, the
 * request finishes and the send is the queue's problem to retry.
 */
class ResetPassword extends BaseResetPassword implements ShouldQueue
{
    use Queueable;
}
