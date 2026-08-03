<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Customers\Enums\CustomerCategory;
use App\Modules\Customers\Enums\CustomerType;
use Illuminate\Validation\Rule;

/**
 * POST /leads/{lead}/convert
 *
 * Two valid payloads:
 *
 * Intent A — create new Customer:
 *   { "conversion_intent": "create_new", "type": "individual", "category": "buyer" }
 *
 * Intent B — link existing Customer:
 *   { "conversion_intent": "link_existing", "existing_customer_id": "01JXXX" }
 */
final class ConvertLeadRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys([
            'conversion_intent',
            'type',
            'category',
            'existing_customer_id',
        ]);
    }

    public function rules(): array
    {
        return [
            'conversion_intent' => [
                'required',
                Rule::in(['create_new', 'link_existing']),
            ],

            // Required only for create_new
            'type' => [
                'required_if:conversion_intent,create_new',
                'prohibited_if:conversion_intent,link_existing',
                'nullable',
                Rule::enum(CustomerType::class),
            ],
            'category' => [
                'required_if:conversion_intent,create_new',
                'prohibited_if:conversion_intent,link_existing',
                'nullable',
                Rule::enum(CustomerCategory::class),
            ],

            // Required only for link_existing
            'existing_customer_id' => [
                'required_if:conversion_intent,link_existing',
                'prohibited_if:conversion_intent,create_new',
                'nullable',
                'string',
                'ulid',
                // Tenant-scoped existence is validated in the Action, not here,
                // because the Action re-validates inside the transaction.
            ],
        ];
    }
}
