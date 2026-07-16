<?php

namespace Webkul\Product\Http\Controllers\API\V1;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Webkul\Product\Http\Requests\PackagingRequest;
use Webkul\Product\Http\Resources\V1\PackagingResource;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Support\Http\Concerns\ValidatesCompanyScope;

#[Group('Product API Management')]
#[Subgroup('Packaging', 'Manage product packaging')]
#[Authenticated]
class PackagingController extends Controller
{
    use ValidatesCompanyScope;

    #[Endpoint('List packagings', 'Retrieve a paginated list of packagings with filtering and sorting')]
    #[QueryParam('include', 'string', 'Comma-separated list of relationships to include. </br></br><b>Available options:</b> product, creator, company', required: false, example: 'product,creator')]
    #[QueryParam('filter[id]', 'string', 'Comma-separated list of IDs to filter by', required: false, example: 'No-example')]
    #[QueryParam('filter[name]', 'string', 'Filter by packaging name (partial match)', required: false, example: 'No-example')]
    #[QueryParam('filter[product_id]', 'string', 'Comma-separated list of product IDs to filter by', required: false, example: 'No-example')]
    #[QueryParam('sort', 'string', 'Sort field', example: 'sort')]
    #[QueryParam('page', 'int', 'Page number', example: 1)]
    #[ResponseFromApiResource(PackagingResource::class, Packaging::class, collection: true, paginate: 10)]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function index()
    {
        Gate::authorize('viewAny', Packaging::class);

        $packagings = QueryBuilder::for(Packaging::class)
            ->allowedFilters(
                AllowedFilter::exact('id'),
                AllowedFilter::partial('name'),
                AllowedFilter::exact('product_id'),
            )
            ->allowedSorts('id', 'name', 'sort', 'created_at')
            ->allowedIncludes(
                'product',
                'creator',
                'company',
            )
            ->paginate();

        return PackagingResource::collection($packagings);
    }

    #[Endpoint('Create packaging', 'Create a new product packaging')]
    #[ResponseFromApiResource(PackagingResource::class, Packaging::class, status: 201, additional: ['message' => 'Packaging created successfully.'])]
    #[Response(status: 422, description: 'Validation error', content: '{"message": "The given data was invalid.", "errors": {"name": ["The name field is required."]}}')]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function store(PackagingRequest $request)
    {
        Gate::authorize('create', Packaging::class);

        $data = $request->validated();
        $data['company_id'] = $this->resolvePackagingCompanyId($data, Auth::user());

        $packaging = Packaging::create($data);

        return (new PackagingResource($packaging))
            ->additional(['message' => 'Packaging created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Packaging.company_id must match its Product.company_id (D2,
     * aureuserp#137): a packaging is never a standalone tenant boundary, it
     * inherits its owning product's company. Omitting company_id derives it
     * from the (required) product_id; an explicit value must both be
     * allowed for the acting user AND match the product's own company —
     * visibility of both sides individually is not enough, they must agree.
     */
    private function resolvePackagingCompanyId(array $data, $user): int
    {
        $product = Product::find($data['product_id'] ?? null);

        if (! $product) {
            throw new AuthorizationException('The related product does not exist or is not accessible to your company.');
        }

        if (! array_key_exists('company_id', $data)) {
            if ($product->company_id === null) {
                throw new AuthorizationException('A packaging must belong to a company, and its product has none to derive from.');
            }

            return $product->company_id;
        }

        if ($data['company_id'] === null) {
            throw new AuthorizationException('A packaging must belong to a company; company_id cannot be null.');
        }

        $this->assertCompanyIdAllowed($data['company_id'], $user, 'packaging');

        if ((int) $data['company_id'] !== (int) $product->company_id) {
            throw new AuthorizationException('The packaging company must match its product company.');
        }

        return (int) $data['company_id'];
    }

    #[Endpoint('Show packaging', 'Retrieve a specific packaging by its ID')]
    #[UrlParam('id', 'integer', 'The packaging ID', required: true, example: 1)]
    #[QueryParam('include', 'string', 'Comma-separated list of relationships to include. </br></br><b>Available options:</b> product, creator, company', required: false, example: 'product,creator')]
    #[ResponseFromApiResource(PackagingResource::class, Packaging::class)]
    #[Response(status: 404, description: 'Packaging not found', content: '{"message": "Resource not found."}')]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function show(string $id)
    {
        $packaging = QueryBuilder::for(Packaging::where('id', $id))
            ->allowedIncludes(
                'product',
                'creator',
                'company',
            )
            ->firstOrFail();

        Gate::authorize('view', $packaging);

        return new PackagingResource($packaging);
    }

    #[Endpoint('Update packaging', 'Update an existing packaging')]
    #[UrlParam('id', 'integer', 'The packaging ID', required: true, example: 1)]
    #[ResponseFromApiResource(PackagingResource::class, Packaging::class, additional: ['message' => 'Packaging updated successfully.'])]
    #[Response(status: 404, description: 'Packaging not found', content: '{"message": "Resource not found."}')]
    #[Response(status: 422, description: 'Validation error', content: '{"message": "The given data was invalid.", "errors": {"name": ["The name field must be a string."]}}')]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function update(PackagingRequest $request, string $id)
    {
        $packaging = Packaging::findOrFail($id);

        Gate::authorize('update', $packaging);

        $data = $request->validated();

        $this->assertCompanyIdImmutable($data, $packaging, 'packaging');

        if (array_key_exists('product_id', $data) && (int) $data['product_id'] !== $packaging->product_id) {
            $newProduct = Product::find($data['product_id']);

            if (! $newProduct || (int) $newProduct->company_id !== (int) $packaging->company_id) {
                throw new AuthorizationException('The related product must belong to the same company as this packaging.');
            }
        }

        $packaging->update($data);

        return (new PackagingResource($packaging))
            ->additional(['message' => 'Packaging updated successfully.']);
    }

    #[Endpoint('Delete packaging', 'Delete a packaging')]
    #[UrlParam('id', 'integer', 'The packaging ID', required: true, example: 1)]
    #[Response(status: 200, description: 'Packaging deleted', content: '{"message": "Packaging deleted successfully."}')]
    #[Response(status: 404, description: 'Packaging not found', content: '{"message": "Resource not found."}')]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function destroy(string $id)
    {
        $packaging = Packaging::findOrFail($id);

        Gate::authorize('delete', $packaging);

        $packaging->delete();

        return response()->json([
            'message' => 'Packaging deleted successfully.',
        ]);
    }
}
