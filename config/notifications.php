<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Viewer notifications
    |--------------------------------------------------------------------------
    |
    | Shipped defaults only. The delay is edited at /manage > Settings >
    | Notifications and stored in the settings table like everything else, so read
    | it through App\Support\NotificationSettings rather than config() - a saved
    | row always wins and a config() read would ignore it.
    |
    */

    /*
     | Hours between a recording being published and anyone being told about it.
     |
     | Publishing is the moment mistakes surface: the wrong cut, the wrong title,
     | a thumbnail that never captured. The delay is the window in which any of
     | that can be fixed, or the recording unpublished again, without a thousand
     | people having already been sent it. Nothing is queued during the window -
     | the dispatcher simply does not see the recording yet - so unpublishing
     | inside it cancels the send outright.
     */
    'delay_hours' => 4,

    /*
     | How far back the dispatcher will look. A recording published while the
     | queue was down should still go out; one published a month ago and only now
     | noticed should not turn into a mailing about old news.
     */
    'catch_up_days' => 7,

];
