<?php

namespace App\Http\Requests\Customs;

class UpdateCustomsDocumentRequest extends StoreCustomsDocumentRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'document_type' => ['sometimes', 'string'],
        ]);
    }
}
