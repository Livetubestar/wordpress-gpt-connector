Shadowverse Live Streams

Main file: `youtube-live-status.php`

Shortcode:

`[shadowverse_live_streams]`

Default behavior:

- responsive card layout
- max 2 columns on desktop
- hidden description by default
- thumbnail click opens the stream
- hover preview for desktop pointer devices

Recommended API key setup in `wp-config.php`:

```php
define('SHADOWVERSE_LIVE_STREAMS_API_KEY', 'YOUR_YOUTUBE_API_KEY');
```

If FTP-only deployment is easier, you can also put the key into `MANUAL_API_KEY` inside `youtube-live-status.php`.

What changed:

- uses batched `videos.list` lookups instead of one API call per stream
- uses live concurrent viewers when available
- caches for 1 hour and refreshes on WordPress cron
- keeps the same shortcode name for easier replacement
- tuned for cleaner 2-column presentation on the live list page
