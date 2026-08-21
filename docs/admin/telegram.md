# Telegram bot

One bot for the installation, and as many chats as there are rooms worth telling. It posts
two things: a show that is about to start, and a report a viewer just sent in. In a chat
that is allowed to, the same messages carry the buttons that start the show, end it, and
close the report.

The chat is the unit of configuration, not the bot. A control room group can have buttons,
a hall group can be told about its own stage only, and a maintainer's direct message can be
info-only. That is the "one bot, n chats" shape: one token in Settings, one row per chat in
`/manage` > Telegram.

## What it needs

- A bot from [@BotFather](https://t.me/BotFather), and its token
- This site reachable from the internet, because Telegram delivers by webhook
- The **Telegram** feature switched on in `/manage` > Settings > Features

## Setting it up

### 1. Save the token

`/manage` > Settings > **Telegram** > **Bot token**, then save.

Saving registers the webhook with Telegram straight away, pointing at `/api/telegram/webhook`
on this installation, with a secret token Telegram has to echo back on every call. There is
nothing else to run: a save that Telegram refused says so in the toast, and the Telegram page
shows the same thing under the bot's name.

The token lives in the settings table only. No environment variable, same rule as the control
and import keys. Clearing it takes the bot off the air and leaves every linked chat alone.

The other field is how early a show is announced, in minutes before its scheduled start. It
is also how early a show can be started from a chat, because the announcement is what carries
the Start button.

### 2. Link a chat

Two ways in, because groups and direct messages behave differently.

**A group:** `/manage` > Telegram > **New link code**, then add the bot to the group and send
`/link ABC-123` in it. The code is good for half an hour. The bot answers, and the chat turns
up in the list with nothing switched on.

**A topic in a forum group:** send `/link ABC-123` in the topic itself. Each topic links as
a row of its own, with its own flags and its own source filter, and posts land in that topic
rather than in General. A group with topics on can send shows to the stage topic and reports
to the support topic - one code each, linked from the topic it belongs to. `/status` and
`/unlink` sent in a topic apply to that topic only.

**By id:** `/manage` > Telegram > **Add by chat ID**. For a topic, fill in the topic id too;
`/chatid` sent in the topic names both. Send `/chatid` to the bot in the chat to
find it; groups are negative numbers. Useful for a direct message, where a code is more
ceremony than it is worth. Telegram will not let a bot write to a person who has never talked
to it, so send the bot a `/start` first, then use **Send test post** on the row to prove it.

### 3. Say what it gets

`/manage` > Telegram > the chat.

- **Shows** - one message a few minutes before each slot, which then tracks the show through
  live and ended.
- **Feedback** - every report a viewer sends in, with the browser and the stream it happened on.
- **Sources** - nothing ticked means every source. Tick some to make it a single room's chat.
- **Allow actions from this chat** - whether the messages carry buttons at all.

## What the buttons do

A show message starts as **▶️ Start show**. Pressing it takes the show live exactly as the
panel and a Companion surface do - same method, same events, same notification to viewers -
and the message rewrites itself to **⏹ End show**.

Ending asks first: the End button turns the message into a confirmation with **✅ Yes, end it**
and **Cancel**, in place, without posting anything new. Starting does not ask, because a show
started a minute early is a smaller problem than a room full of people watching nothing.

A feedback message carries **✅ Resolve**, which closes the report and rewrites the message to
say who did it. Resolving it in `/manage` instead rewrites the same message.

Messages are kept in step whoever made the change. A show started in the control room, on a
Companion surface, by auto mode, or cancelled in the planner rewrites every chat that was told
about it - the message turns red, reads "Live since 14:02", and swaps its Start button for
End. A group is never left holding a button for something that already happened.

A show that goes live before it was ever announced - started hours early, or taken live by auto
mode outside the lead window - has no message to rewrite, so one is posted at that moment
already reading as live. A show that ends without ever having been announced stays quiet
rather than posting history nobody was following.

This runs through the queue, so a worker has to be processing (Horizon in production,
`php artisan queue:work` locally) for the messages to be rewritten.

## Who can press them

Anyone who can read the chat. Telegram has no idea which of its members works here, and the
bot does not ask: the trust is the chat, which somebody with the panel open deliberately
linked and deliberately marked as interactive. Treat switching it on the same way as handing
out a control key.

An info-only chat gets the same text with an **Open in panel** link and no action buttons, and
a press from a chat that is not interactive is refused.

Presses are recorded. The handle turns up in the log line for a show, and on the report itself
for a resolve.

## Commands

| Command | What it does |
| --- | --- |
| `/link CODE` | Links this chat, using a code from the panel |
| `/status` | What this chat is set to receive |
| `/chatid` | This chat's id, plus the topic id when sent in a topic |
| `/unlink` | Removes the row; nothing more is posted here |

## When it goes quiet

The Telegram page shows the bot's name, whether Telegram is delivering to us, and how many
updates are waiting. A chat the bot was kicked out of, blocked in, or that no longer exists is
switched off automatically with the reason on the row - re-enable it after fixing the cause,
which clears the reason.

From a shell:

```bash
php artisan telegram:webhook          # what Telegram thinks it should deliver to
php artisan telegram:webhook set      # register it again, e.g. after a domain change
php artisan telegram:webhook rotate   # new secret, then re-register
php artisan telegram:webhook delete   # stop delivery without clearing the token
```

The announcement scan runs every minute from the scheduler, so shows only appear if the
schedule is running. Nothing is posted at all when the Telegram feature is off, and the
webhook answers 404 - the linked chats stay linked and pick up again when it is switched
back on.
