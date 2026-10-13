<?php

namespace App\Http\Requests\Settings;

use App\Actions\Settings\DeleteAccount;
use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(DeleteAccount $deleteAccount): array
    {
        return [
            function (Validator $validator) use ($deleteAccount) {
                if ($validator->errors()->has('password')) {
                    return;
                }

                $refusal = $this->user() ? $deleteAccount->refusal($this->user()) : null;

                if ($refusal !== null) {
                    $validator->errors()->add('account', $refusal);
                }
            },
        ];
    }
}
