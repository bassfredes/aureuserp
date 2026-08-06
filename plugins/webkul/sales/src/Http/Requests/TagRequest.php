<?php

namespace Webkul\Sale\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Webkul\Sale\Models\Tag;

class TagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $requiredRule = $isUpdate ? ['sometimes', 'required'] : ['required'];
        $tagId = $this->route('tag');

        // Name uniqueness is scoped per company (#138 PR4 A4K): the same
        // name may legitimately exist in two different companies, only a
        // duplicate within the SAME company is a conflict. On create, the
        // effective company is the one Tag::creating() will assign (the
        // acting user's default company); on update, it's the tag's own
        // already-persisted company_id.
        $effectiveCompanyId = $tagId
            ? Tag::find($tagId)?->company_id
            : Auth::user()?->default_company_id;

        return [
            'name' => [
                ...$requiredRule,
                'string',
                'max:255',
                Rule::unique('sales_tags', 'name')
                    ->where(function ($query) use ($effectiveCompanyId) {
                        $effectiveCompanyId === null
                            ? $query->whereNull('company_id')
                            : $query->where('company_id', $effectiveCompanyId);
                    })
                    ->ignore($tagId, 'id'),
            ],
            'color' => ['nullable', 'string', 'max:7'],
        ];
    }

    /**
     * Get body parameters for API documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Sales tag name (max 255 characters).',
                'example'     => 'Urgent',
            ],
            'color' => [
                'description' => 'Tag color in hex format (max 7 characters).',
                'example'     => '#FF5733',
            ],
        ];
    }
}
