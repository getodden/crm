<?php

declare(strict_types=1);

namespace Odden\Service\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Odden\Service\Models\KnowledgeArticle;

class HelpCenterController extends Controller
{
    /**
     * Browse the public knowledge base.
     */
    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->value();
        $selectedCategory = $request->string('category')->trim()->value();

        $query = KnowledgeArticle::query()->where('is_published', true);

        if (! empty($search)) {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if (! empty($selectedCategory)) {
            $query->where('category', $selectedCategory);
        }

        $articles = $query->orderBy('views_count', 'desc')->paginate(12)->withQueryString();

        $categories = KnowledgeArticle::query()
            ->where('is_published', true)
            ->distinct()
            ->pluck('category')
            ->all();

        return view('odden-service::help.index', [
            'articles' => $articles,
            'categories' => $categories,
            'search' => $search,
            'selectedCategory' => $selectedCategory,
        ]);
    }

    /**
     * Read a knowledge base article.
     */
    public function show(string $slug): View
    {
        /** @var KnowledgeArticle $article */
        $article = KnowledgeArticle::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        // Count one view per visitor session, so refreshes and repeat visits don't inflate it.
        $viewed = (array) session()->get('odden.viewed_articles', []);
        if (! in_array($article->id, $viewed, true)) {
            $article->recordView();
            session()->put('odden.viewed_articles', [...$viewed, $article->id]);
        }

        $relatedArticles = KnowledgeArticle::query()
            ->where('category', $article->category)
            ->where('id', '!=', $article->id)
            ->where('is_published', true)
            ->take(4)
            ->get();

        return view('odden-service::help.show', [
            'article' => $article,
            'relatedArticles' => $relatedArticles,
        ]);
    }

    /**
     * Vote on article helpfulness.
     */
    public function vote(Request $request, string $slug): RedirectResponse
    {
        /** @var KnowledgeArticle $article */
        $article = KnowledgeArticle::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $voteType = $request->validate([
            'type' => ['required', 'in:helpful,not_helpful'],
        ])['type'];

        // One vote per article per browser session: the page is public, so without this a script could set the score.
        $voted = (array) $request->session()->get('odden_help_voted', []);
        if (in_array($article->id, $voted, true)) {
            return back()->with('feedback_submitted', 'Thank you! You have already given feedback on this article.');
        }
        $request->session()->put('odden_help_voted', [...$voted, $article->id]);

        if ($voteType === 'helpful') {
            $article->voteHelpful();
            $message = 'Thank you for your feedback!';
        } else {
            $article->voteNotHelpful();
            $message = 'Thank you! We will work to improve this guide.';
        }

        return back()->with('feedback_submitted', $message);
    }
}
