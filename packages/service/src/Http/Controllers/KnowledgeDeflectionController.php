<?php

declare(strict_types=1);

namespace Odden\Service\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Odden\Service\Actions\DeflectTicketAction;
use Odden\Service\Models\KnowledgeArticle;

class KnowledgeDeflectionController extends Controller
{
    /**
     * Provide real-time smart suggestions based on customer input or agent search query.
     */
    public function suggest(Request $request, DeflectTicketAction $action): JsonResponse
    {
        $query = (string) ($request->query('q') ?? $request->query('query') ?? '');
        $limit = max(1, min(10, (int) $request->query('limit', 5)));

        $suggestions = $action->execute($query, $limit);

        return response()->json([
            'query' => $query,
            'count' => $suggestions->count(),
            'data' => $suggestions,
        ]);
    }

    /**
     * Track a customer acknowledging an article resolved their issue before creating a ticket.
     */
    public function deflect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'article_id' => ['required', 'integer'],
        ]);

        /** @var KnowledgeArticle|null $article */
        $article = KnowledgeArticle::query()
            ->where('is_published', true)
            ->find($validated['article_id']);

        if ($article === null) {
            return response()->json([
                'success' => false,
                'message' => 'Article not found.',
            ], 404);
        }

        $article->recordDeflection();

        return response()->json([
            'success' => true,
            'message' => 'Deflection recorded successfully.',
            'deflections_count' => $article->deflections_count,
        ]);
    }
}
