<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Marketing\Jobs\UpdateCreateSearchTerm as UpdateCreateSearchTermJob;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Http\Resources\ProductResource;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductController extends APIController
{
    /**
     * Create a controller instance.
     *
     * @return void
     */
    public function __construct(
        protected CategoryRepository $categoryRepository,
        protected ProductRepository $productRepository
    ) {
    }

    /**
     * Product listings.
     */
    public function index(Request $request): JsonResource
    {
        $mergedParams = array_merge(request()->query(), [
            'channel_id'           => core()->getCurrentChannel()->id,
            'status'               => 1,
            'visible_individually' => 1,
        ]);

        // Fetch simple products
        $simpleParams = array_merge($mergedParams, ['type' => 'simple']);
        $simpleProducts = $this->productRepository
            ->setSearchEngine(core()->getConfigData('catalog.products.search.storefront_mode'))
            ->getAll($simpleParams);

        // Fetch configurable products
        $configurableParams = array_merge($mergedParams, ['type' => 'configurable']);
        $configurableProducts = $this->productRepository
            ->setSearchEngine(core()->getConfigData('catalog.products.search.storefront_mode'))
            ->getAll($configurableParams);

        // Create a new collection to store the final products
        $finalProducts = collect();

        // Loop over the simple products and add their parent configurable product to the final collection
        foreach ($simpleProducts as $simpleProduct) {
            $parentProduct = $simpleProduct->parent;
            if ($parentProduct && !$finalProducts->contains('id', $parentProduct->id)) {
                $finalProducts->push($parentProduct);
            }
        }

        // Add configurable products to the final collection
        foreach ($configurableProducts as $configurableProduct) {
            if (!$finalProducts->contains('id', $configurableProduct->id)) {
                $finalProducts->push($configurableProduct);
            }
        }

        // Remove duplicate products based on their ID
        $finalProducts = $finalProducts->unique('id');

        // Convert the collection to an array
        $finalProductsArray = $finalProducts->values()->all();

        // Manually paginate the results
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $itemsPerPage = $simpleProducts->perPage(); // Use the same perPage as the original query
        $totalItems = count($finalProductsArray);
        $currentPageItems = array_slice($finalProductsArray, ($currentPage - 1) * $itemsPerPage, $itemsPerPage);

        $paginatedFinalProducts = new LengthAwarePaginator(
            $currentPageItems,
            $totalItems,
            $itemsPerPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );

        // Update search term if necessary
        if (!empty(request()->query('query'))) {
            /**
             * Update or create search term only if
             * there is only one filter that is query param
             */
            if (count(request()->except(['mode', 'sort', 'limit'])) == 1) {
                UpdateCreateSearchTermJob::dispatch([
                    'term'       => request()->query('query'),
                    'results'    => $totalItems,
                    'channel_id' => core()->getCurrentChannel()->id,
                    'locale'     => app()->getLocale(),
                ]);
            }
        }

        return new JsonResource([
            'data' => ProductResource::collection($paginatedFinalProducts)->response()->getData(true)['data'],
            'links' => [
                'first' => $paginatedFinalProducts->url(1),
                'last' => $paginatedFinalProducts->url($paginatedFinalProducts->lastPage()),
                'prev' => $paginatedFinalProducts->previousPageUrl(),
                'next' => $paginatedFinalProducts->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginatedFinalProducts->currentPage(),
                'from' => $paginatedFinalProducts->firstItem(),
                'last_page' => $paginatedFinalProducts->lastPage(),
                'links' => $paginatedFinalProducts->linkCollection()->toArray(),
                'path' => $paginatedFinalProducts->path(),
                'per_page' => $paginatedFinalProducts->perPage(),
                'to' => $paginatedFinalProducts->lastItem(),
                'total' => $paginatedFinalProducts->total(),
            ]
        ]);
    }



    /**
     * Related product listings.
     *
     * @param  int  $id
     */
    public function relatedProducts($id): JsonResource
    {
        $product = $this->productRepository->findOrFail($id);

        $relatedProducts = $product->related_products()
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_related_products'))
            ->get();

        return ProductResource::collection($relatedProducts);
    }

    /**
     * Up-sell product listings.
     *
     * @param  int  $id
     */
    public function upSellProducts($id): JsonResource
    {
        $product = $this->productRepository->findOrFail($id);

        $upSellProducts = $product->up_sells()
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_up_sells_products'))
            ->get();

        return ProductResource::collection($upSellProducts);
    }
}
