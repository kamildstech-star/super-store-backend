<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PosListing
{
    public static function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
    }

    public static function whereContains(Builder $query, array $columns, string $term): void
    {
        $pattern = '%'.strtr(mb_strtolower($term), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        foreach ($columns as $column) {
            // Column names come only from the controller's static allowlist.
            $query->orWhereRaw("LOWER($column) LIKE ? ESCAPE '!'", [$pattern]);
        }
    }

    public static function response(Builder $query, array $filters): JsonResponse
    {
        $page = $query->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }
}
