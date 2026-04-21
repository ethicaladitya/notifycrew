# Team Reminder Frontend (Headless)

This app is a standalone frontend for Team Reminder Tool. It is designed so public users only see your frontend domain and never call your WordPress host directly.

## Architecture

- Browser app (Vite static): sign-in UI + reminder management.
- Vercel serverless proxy (`/api/portal/*`): receives browser requests and forwards them to WordPress.
- WordPress plugin (`trt/v1/portal/*`): validates API key, validates Google ID token, validates team/domain access, then reads/writes reminders.

## 1) WordPress Setup

In Team Reminder Tool settings:

- Enable frontend form.
- Set Google OAuth Client ID.
- Set allowed email domains.
- Set **Headless API Key** (a long random secret).

## 2) Local Development

1. Copy `.env.example` to `.env`.
2. Set `VITE_GOOGLE_CLIENT_ID`.
3. For local UI only, keep `VITE_API_BASE_URL=/api`.
4. Install and run:

```bash
npm install
npm run dev
```

## 3) Deploy on Vercel (Recommended)

Set project root to `frontend-app` and configure environment variables:

- `VITE_GOOGLE_CLIENT_ID`: Google client ID used by browser app.
- `VITE_API_BASE_URL`: `/api`
- `WORDPRESS_API_BASE`: `https://your-hidden-wp-host.com/wp-json/trt/v1/portal`
- `TRT_API_KEY`: same value as WordPress Headless API Key.
- `ALLOWED_ORIGIN`: optional; set to your frontend domain instead of `*`.

Then deploy.

Result: Browser -> Vercel `/api` -> WordPress. Your WP URL is not exposed in browser network requests.

## 4) Deploy on GitHub Pages

GitHub Pages is static only, so keep Vercel proxy as backend.

Build with:

```bash
VITE_BASE_PATH=/your-repo-name/ \
VITE_API_BASE_URL=https://your-vercel-proxy-domain/api \
VITE_GOOGLE_CLIENT_ID=your_client_id \
npm run build
```

Publish `frontend-app/dist` to GitHub Pages.

## Security Notes

- Never put `TRT_API_KEY` in browser code.
- Keep `TRT_API_KEY` only in server-side env vars (Vercel).
- Use a custom domain for frontend to avoid showing provider domains.
- Restrict `ALLOWED_ORIGIN` on proxy for stricter cross-origin policy.
