---
title: Writing the docs
description: How the Odden documentation is organized and the conventions every page follows.
---

These docs are published at [odden.io/docs](https://odden.io/docs). They live in this repository so that a change to a package and the change to its documentation can land in the same pull request.

## Layout

- `docs/navigation.yml` sets the sidebar: the sections, and the order of pages within each. A page that isn't listed there isn't published.
- Each package has a folder (`docs/core`, `docs/sales`, and so on) with an `index.md` overview and one file per topic.
- Shared pages (introduction, installation, configuration) sit at the top level.

## Front matter

Every page starts with:

```yaml
---
title: Pipelines and deals
description: One sentence that says what the page covers. Used for search results and link previews.
---
```

Don't repeat the title as a `#` heading in the body; the site renders it. Start the body with a short paragraph, then use `##` and `###` headings.

## Links

Link to other pages with relative paths to the Markdown file, so the links also work on GitHub: `[custom properties](../core/custom-properties.md)`, or `[stages](pipelines.md#stages)` within a section. The site rewrites them.

## Style

- Write for a Laravel developer installing Odden into their own app. Use "you".
- Document only what the code does today. Use the real class names, method signatures, config keys, environment variables, route names, events, and Artisan commands.
- Every code example must work as written against the current packages. Prefer short, complete examples over fragments.
- Use sentence case for headings. Keep paragraphs short. No marketing language.
- Use fenced code blocks with a language (`php`, `bash`, `env`, `json`, `blade`).
- When something is configurable, show the config key and its default.
- Call out side effects a developer would not expect (queued jobs, emails, scheduled commands, events dispatched).

## Publishing

The `Docs` workflow (`.github/workflows/docs.yml`) checks the pages, navigation and links on every pull request that touches `docs/**`. On a push to `main` it then redeploys the website, because `odden.io/docs` is built from `main`.

The redeploy is a `GET` request to a deploy hook URL stored in the `WEBSITE_DEPLOY_HOOK` repository secret (Settings → Secrets and variables → Actions). The website is hosted on Laravel Cloud, whose hook URLs look like `https://cloud.laravel.com/deploy/{id}/{token}`; a `POST` to one returns a 404. GitHub never shows a secret's value, so the URL has to come from the host: copy the deploy hook of the Laravel Cloud environment that serves `odden.io` (not the one for `app.odden.io`), then store it:

```bash
gh secret set WEBSITE_DEPLOY_HOOK
```

If the secret is empty, the publish step passes with a notice and the docs update on the website's next deploy. If the host deletes or regenerates the hook, or the hook belongs to a site that no longer exists (as after the move from focalcrm.io), the step fails with `curl: (22) ... 404`; the docs checks still pass, so the pages themselves are fine. Create a new hook, update the secret, and re-run the failed `Docs` run.
