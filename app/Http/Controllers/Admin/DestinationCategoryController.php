<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestinationCategoryRequest;
use App\Models\DestinationCategory;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Categories visitors filter destinations by (Beach, Viewpoint, ...).
 * A category still in use cannot be deleted, so no destination is left
 * without one.
 */
class DestinationCategoryController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(): View
    {
        $this->authorize('viewAny', DestinationCategory::class);

        return view('admin.destination-categories.index', [
            'categories' => DestinationCategory::query()
                ->withCount('destinations')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(DestinationCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', DestinationCategory::class);

        $category = DestinationCategory::create($request->validated());

        $this->logger->log('category.created', $category, "Added the destination category {$category->name}");

        return back()->with('success', "{$category->name} has been added.");
    }

    public function update(DestinationCategoryRequest $request, DestinationCategory $category): RedirectResponse
    {
        $this->authorize('update', DestinationCategory::class);

        $category->update($request->validated());

        return back()->with('success', "{$category->name} has been saved.");
    }

    public function destroy(DestinationCategory $category): RedirectResponse
    {
        $this->authorize('delete', DestinationCategory::class);

        if ($category->destinations()->exists()) {
            return back()->with('error', "{$category->name} is used by destinations. Move them to another category first.");
        }

        $name = $category->name;
        $category->delete();

        $this->logger->log('category.deleted', null, "Deleted the destination category {$name}");

        return back()->with('success', "{$name} has been deleted.");
    }
}
