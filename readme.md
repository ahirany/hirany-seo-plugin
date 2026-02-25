## Hirany SEO Plugin

Hirany SEO is an all‑in‑one WordPress SEO plugin inspired by RankMath. It provides keyword tracking, schema markup, Content AI, SEO scoring, sitemaps, robots/llms.txt management, and AI traffic analytics in a modular architecture.

### Key Features

- **Track up to 50,000 keywords** with a custom data model, daily/cron‑based updates, external SERP provider integration (SerpAPI or custom JSON endpoint), and 12‑month history.
- **Powerful schema generator** with a per‑post meta box (Article, Product, FAQPage, Custom JSON‑LD) and automatic JSON‑LD output in `wp_head`.
- **Content AI generator** to draft SEO‑optimized content in the editor using an OpenAI‑compatible API (configured under Hirany SEO → Settings).
- **Comprehensive SEO score** per post based on focus keyword usage (title, slug, description, headings, ALT text, early in content) and content length, rendered as a score out of 100 with a checklist.
- **Unlimited keyword optimization** via a “Keywords & Meta” meta box that stores multiple focus keywords and optional SEO title/meta description.
- **Automated XML sitemaps** at `/hsp-sitemap.xml`, with settings to choose which post types are included and whether to include the homepage.
- **llms.txt generator** at `/llms.txt` with an in‑dashboard editor and reset‑to‑default functionality.
- **Robots.txt editor and validator** that can override the core `robots.txt`, append sitemap references, and warn about dangerous rules like `User-agent: *` + `Disallow: /`.
- **AI search traffic tracker** that logs visits with AI‑related referrers (ChatGPT, Perplexity, Claude, etc.) and visualizes them in an “AI Traffic” admin report.

### Installation (development)

1. Copy the `hirany-seo-plugin` folder into your WordPress `wp-content/plugins/` directory.
2. Ensure the main file is named `hirany-seo-plugin.php`.
3. Activate **Hirany SEO Plugin** from the WordPress Plugins screen.
4. Go to **Hirany SEO** in the admin sidebar to configure:
   - Content AI provider, API key, and model.
   - Rank tracker provider and limits.
   - Sitemaps, llms.txt, and robots.txt.

### Notes

- This plugin is intentionally modular: each feature lives in its own `HSP_*` class under `includes/` and is wired via the central `HSP_Plugin` loader.
- External APIs (Content AI, rank tracking) are *off* by default and must be explicitly configured with your own keys.

