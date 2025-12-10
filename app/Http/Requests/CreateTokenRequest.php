<?php

namespace App\Http\Requests;

class CreateTokenRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'default_content' => ['string'],
            'default_content_type' => ['string'],
            'default_status' => ['int'],
            'timeout' => ['int', 'min:0', 'max:10'],
            'server_redirect_url' => ['string', 'nullable'],
            'server_redirect_method' => ['string', 'nullable'],
            'server_redirect_headers' => ['string', 'nullable'],
            'server_redirect_content_type' => ['string', 'nullable'],
            // New: redirect mode and type
            'redirect_mode' => ['string', 'nullable', 'in:forward,redirect'],
            'redirect_type' => ['int', 'nullable', 'in:301,302,307,308'],
        ];
    }
}
