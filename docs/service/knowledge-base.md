---
title: Knowledge base and deflection
description: Publish help center articles, track views and votes, and suggest articles to customers before they open a ticket.
---

The knowledge base is a set of `KnowledgeArticle` records shown in a public help center. The same articles power deflection: suggesting answers while a customer writes a ticket, and counting how often an article solved the problem instead.

## Articles

`Odden\Service\Models\KnowledgeArticle` has:

| Attribute | Default | Notes |
| :--- | :--- | :--- |
| `title` | | |
| `slug` | | Unique, used in the article URL. Not generated for you. |
| `category` | `General` | Free text; the help center lists the distinct categories of published articles. |
| `body` | | Plain text. The help center escapes it and converts line breaks; Markdown and HTML are shown as typed. |
| `is_published` | `true` | Unpublished articles are hidden from the help center and suggestions. |
| `views_count`, `helpful_count`, `not_helpful_count`, `deflections_count` | `0` | Counters. |
| `user_id` | `null` | The author, through the `author()` relationship. |

```php
use Odden\Service\Models\KnowledgeArticle;

KnowledgeArticle::create([
    'title' => 'Exporting contacts to CSV',
    'slug' => 'exporting-contacts-to-csv',
    'category' => 'Data',
    'body' => "Open Contacts, choose Export, and pick the CSV format.\nLarge exports are emailed to you.",
]);
```

Each counter has a method that increments it and returns the article: `recordView()`, `voteHelpful()`, `voteNotHelpful()`, and `recordDeflection()`.

## The help center

The help center routes are in the `web` route group, with no prefix by default:

| Method | URI | Route name | Rate limit |
| :--- | :--- | :--- | :--- |
| GET | `/help` | `odden.help.index` | |
| GET | `/help/{slug}` | `odden.help.show` | |
| POST | `/help/{slug}/vote` | `odden.help.vote` | `odden-public` |

`GET /help` lists published articles, 12 per page, most viewed first. `?q=` filters by a substring of the title or body, and `?category=` by exact category.

`GET /help/{slug}` shows a published article (unpublished or unknown slugs return `404`) and up to four other published articles in the same category. `views_count` goes up once per visitor session: refreshing or revisiting the article in the same session doesn't count again.

`POST /help/{slug}/vote` takes `type`. `type` is required and must be `helpful` (increments `helpful_count`) or `not_helpful` (increments `not_helpful_count`); anything else is rejected with a validation error and nothing is counted. It redirects back with a `feedback_submitted` flash message. Votes are not limited per visitor beyond the rate limit, and the route uses the web group's CSRF protection.

The views are `odden-service::help.index` and `odden-service::help.show`. Override them the same way as the [portal views](customer-portal.md#customizing-the-pages).

## Suggesting articles

`DeflectTicketAction` finds published articles that match a customer's text:

```php
use Odden\Service\Actions\DeflectTicketAction;

$suggestions = app(DeflectTicketAction::class)->execute('How do I export my contacts?', limit: 3);

$suggestions->first()['url']; // https://your-app.test/help/exporting-contacts-to-csv
```

`execute(string $query, int $limit = 5): Collection` returns a collection of arrays with `id`, `title`, `slug`, `category`, `excerpt` (the body without tags, cut to 140 characters), `helpful_count`, `deflections_count`, and `url` (the help center URL).

Matching is keyword search with SQL `LIKE`, not AI or semantic search:

1. The query is lowercased and split on spaces and punctuation. Words shorter than three characters and common words (such as "how", "the", "help", "issue", "problem") are dropped.
2. An article matches if its title or category contains the whole query, or its title or body contains any remaining word. If no words remain, the whole query is matched against title and body.
3. Results are ordered by `helpful_count`, then `views_count`, highest first.

An empty query returns an empty collection.

### Suggestion API

The bundled support form calls this endpoint as the customer types. You can use it from your own forms or agent tools:

| | |
| :--- | :--- |
| Method and URI | `GET /api/service/knowledge/suggest` |
| Route name | `odden.service.knowledge.suggest` |
| Parameters | `q` (or `query`), `limit` (default 5, clamped to 1–10) |
| Rate limit | None |

```bash
curl "https://crm.example.com/api/service/knowledge/suggest?q=export+contacts&limit=3"
```

```json
{
    "query": "export contacts",
    "count": 1,
    "data": [
        {
            "id": 1,
            "title": "Exporting contacts to CSV",
            "slug": "exporting-contacts-to-csv",
            "category": "Data",
            "excerpt": "Open Contacts, choose Export, and pick the CSV format.\nLarge exports are emailed to you.",
            "helpful_count": 0,
            "deflections_count": 0,
            "url": "https://crm.example.com/help/exporting-contacts-to-csv"
        }
    ]
}
```

`title`, `category`, and `excerpt` are returned as stored, without HTML escaping, so insert them into a page as text (`textContent`, or `{{ }}` in Blade), never as HTML. `url` is generated with `route('odden.help.show', $slug)`, which URL-encodes the slug. The bundled support form builds each suggestion with DOM nodes and `textContent`, and only links `url` when it is an `http` or `https` URL.

## Recording deflections

When a customer says a suggested article solved their problem, record it so you can see which articles prevent tickets:

| | |
| :--- | :--- |
| Method and URI | `POST /api/service/knowledge/deflect` |
| Route name | `odden.service.knowledge.deflect` |
| Body | `article_id` (required, integer) |
| Rate limit | `odden-public` |
| CSRF | Exempt |

```bash
curl -X POST https://crm.example.com/api/service/knowledge/deflect \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"article_id": 1}'
```

```json
{
    "success": true,
    "message": "Deflection recorded successfully.",
    "deflections_count": 1
}
```

An unknown `article_id` returns `404` with `{"success": false, "message": "Article not found."}`. Unpublished articles also return `404` and are not counted. The endpoint doesn't limit repeat submissions beyond the rate limit, so treat `deflections_count` as an indicator rather than an exact figure.
