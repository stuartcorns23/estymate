<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        return Inertia::render('products/index', [
            'products' => Product::query()
                ->with('taxRate:id,name,rate')
                ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('products/form', [
            'product' => null,
            'taxRates' => $this->taxRates($request),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        Product::create($request->productData());

        return to_route('products.index');
    }

    public function edit(Request $request, Product $product): Response
    {
        return Inertia::render('products/form', [
            'product' => $product,
            'taxRates' => $this->taxRates($request),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->productData());

        return to_route('products.index');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return to_route('products.index');
    }

    /**
     * @return Collection<int, TaxRate>
     */
    private function taxRates(Request $request)
    {
        return $request->user()->currentBusiness->documentTaxRates()->orderByDesc('rate')->get(['id', 'name', 'rate', 'is_default']);
    }
}
