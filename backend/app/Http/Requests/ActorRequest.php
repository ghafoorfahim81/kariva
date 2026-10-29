<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

/** Create or edit an actor page. Phones typed in any style become E.164. */
class ActorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['contact_phone', 'whatsapp'] as $field) {
            if ($this->has($field) && $this->input($field) !== null && $this->input($field) !== '') {
                // a number that cannot be normalized stays as typed, so validation rejects it instead of dropping it
                $this->merge([$field => Phone::normalize((string) $this->input($field)) ?? $this->input($field)]);
            }
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'display_name' => [$required, 'string', 'min:2', 'max:80'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:categories,id'],
            'city_id' => ['sometimes', 'nullable', 'uuid', 'exists:cities,id'],
            'contact_phone' => ['sometimes', 'nullable', 'regex:/^\+937\d{8}$/'],
            'whatsapp' => ['sometimes', 'nullable', 'regex:/^\+937\d{8}$/'],
        ];
    }

    /** Column names for the actors table. */
    public function attributesForModel(): array
    {
        $data = $this->validated();
        foreach (['contact_phone' => 'contact_phone_e164', 'whatsapp' => 'whatsapp_e164'] as $from => $to) {
            if (array_key_exists($from, $data)) {
                $data[$to] = $data[$from];
                unset($data[$from]);
            }
        }

        return $data;
    }
}
