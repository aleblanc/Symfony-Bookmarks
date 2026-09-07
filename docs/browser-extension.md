# Browser extension setup

Install the [official Linkwarden extension](https://github.com/linkwarden/browser-extension) in Firefox / Chrome / Edge. Then:

1. Open the extension options.
2. **Instance URL**: `http://<your-instance>/` (e.g. `http://raspberrypi.local:8000`).
3. **API token**: paste the value of `APP_API_TOKEN` from your `.env.local`.
4. Click "Sign in" — the extension calls `GET /api/v1/users/me`; you should see the collection dropdown populate.

New links you add via the extension will land in the app instantly and get archived on the next cron tick.
