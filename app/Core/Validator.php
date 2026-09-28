<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    private array $errors = [];

    public function validate(array $data, array $rules): array
    {
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            foreach (explode('|', $ruleString) as $rule) {
                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                if ($name === 'nullable' && ($value === null || $value === '')) {
                    break;
                }
                if ($name === 'required' && ($value === null || trim((string) $value) === '')) {
                    $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
                } elseif ($name === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field][] = 'Enter a valid email address.';
                } elseif ($name === 'numeric' && $value !== '' && !is_numeric($value)) {
                    $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . ' must be a number.';
                } elseif ($name === 'max' && mb_strlen((string) $value) > (int) $parameter) {
                    $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . " may not exceed {$parameter} characters.";
                } elseif ($name === 'date' && $value && strtotime((string) $value) === false) {
                    $this->errors[$field][] = 'Enter a valid date.';
                } elseif ($name === 'in' && $value !== '' && !in_array($value, explode(',', (string) $parameter), true)) {
                    $this->errors[$field][] = 'Select a valid ' . str_replace('_', ' ', $field) . '.';
                }
            }
        }
        return $this->errors;
    }
}
