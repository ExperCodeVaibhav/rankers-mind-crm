<?php

declare(strict_types=1);

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testErrorsDoNotLeakBetweenValidationCalls(): void
    {
        $validator = new Validator();
        self::assertArrayHasKey('name', $validator->validate([], ['name' => 'required']));
        self::assertSame([], $validator->validate(['name' => 'Asha'], ['name' => 'required']));
    }

    /** @dataProvider invalidInput */
    public function testInvalidInputIsRejected(mixed $value, string $rules): void
    {
        self::assertArrayHasKey('field', (new Validator())->validate(['field' => $value], ['field' => $rules]));
    }

    public static function invalidInput(): array
    {
        return [
            'blank required' => ['  ', 'required'],
            'array injection' => [['nested'], 'required|max:30'],
            'object injection' => [new stdClass(), 'email'],
            'invalid email' => ['0', 'email'],
            'invalid number' => ['abc', 'numeric'],
            'long unicode' => ['हिन्दी', 'max:2'],
            'impossible date' => ['2026-02-30', 'date'],
            'relative date' => ['tomorrow', 'date'],
            'invalid time' => ['2026-09-28T25:30', 'date'],
            'invalid choice' => ['Administrator', 'in:Staff,Client'],
        ];
    }

    /** @dataProvider validInput */
    public function testValidInputIsAccepted(mixed $value, string $rules): void
    {
        self::assertSame([], (new Validator())->validate(['field' => $value], ['field' => $rules]));
    }

    public static function validInput(): array
    {
        return [
            'zero is present' => [0, 'required|numeric'],
            'email' => ['staff@example.test', 'required|email'],
            'nullable' => [null, 'nullable|numeric'],
            'empty nullable' => ['', 'nullable|date'],
            'leap day' => ['2024-02-29', 'required|date'],
            'datetime input' => ['2026-09-28T09:30', 'date'],
            'datetime seconds' => ['2026-09-28T09:30:15', 'date'],
            'database datetime' => ['2026-09-28 09:30:15', 'date'],
            'unicode length' => ['éé', 'max:2'],
            'choice' => ['Staff', 'in:Staff,Client'],
        ];
    }
}
