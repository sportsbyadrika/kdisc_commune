<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;
use Closure;
use DateTimeImmutable;

/**
 * Declarative validator.
 *
 *   $data = Validator::make($request->all(), [
 *       'email'    => 'required|email|max:190|unique:staff_users,email',
 *       'mobile'   => ['required', 'regex:/^\+91[6-9]\d{9}$/'],
 *       'type'     => ['required', 'in:individual,institution'],
 *       'aadhaar'  => ['required', 'aadhaar'],                       // custom rule, see extend()
 *       'start'    => 'required|date|after_or_equal:today',
 *       'qty'      => ['required', 'integer', 'min:1', fn ($v) => $v <= 9 ?: 'At most 9 seats.'],
 *   ])->validate();   // throws ValidationException, returns only validated keys
 *
 * Rules: required, required_if:field,value, nullable, sometimes, string, integer, numeric, boolean, array,
 *        email, url, min:n, max:n, between:a,b, size:n, in:a,b, not_in:a,b, regex:/re/, alpha_num, alpha_dash,
 *        date, date_format:Y-m-d, after:date|field, after_or_equal:, before:, before_or_equal:,
 *        confirmed, same:field, different:field, digits:n, accepted, unique:table,column[,ignoreId[,idColumn]],
 *        exists:table,column, mobile_in, password (strength).
 * min/max/between/size compare numbers when the field has integer|numeric, array counts for array,
 * otherwise string length (mb).
 */
final class Validator
{
    /** @var array<string, array{0: Closure, 1: string}> */
    private static array $extensions = [];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, string> */
    private static array $defaultMessages = [
        'required' => 'The :attribute field is required.',
        'required_if' => 'The :attribute field is required.',
        'string' => 'The :attribute must be text.',
        'integer' => 'The :attribute must be a whole number.',
        'numeric' => 'The :attribute must be a number.',
        'boolean' => 'The :attribute must be true or false.',
        'array' => 'The :attribute must be a list.',
        'email' => 'Please enter a valid email address.',
        'url' => 'The :attribute must be a valid URL.',
        'min.numeric' => 'The :attribute must be at least :0.',
        'min.string' => 'The :attribute must be at least :0 characters.',
        'min.array' => 'Select at least :0 items.',
        'max.numeric' => 'The :attribute may not be greater than :0.',
        'max.string' => 'The :attribute may not be longer than :0 characters.',
        'max.array' => 'Select at most :0 items.',
        'between.numeric' => 'The :attribute must be between :0 and :1.',
        'between.string' => 'The :attribute must be between :0 and :1 characters.',
        'between.array' => 'Select between :0 and :1 items.',
        'size.numeric' => 'The :attribute must be :0.',
        'size.string' => 'The :attribute must be :0 characters.',
        'size.array' => 'Select exactly :0 items.',
        'in' => 'The selected :attribute is invalid.',
        'not_in' => 'The selected :attribute is invalid.',
        'regex' => 'The :attribute format is invalid.',
        'alpha_num' => 'The :attribute may only contain letters and numbers.',
        'alpha_dash' => 'The :attribute may only contain letters, numbers, dashes and underscores.',
        'date' => 'The :attribute is not a valid date.',
        'date_format' => 'The :attribute does not match the format :0.',
        'after' => 'The :attribute must be a date after :0.',
        'after_or_equal' => 'The :attribute must be a date on or after :0.',
        'before' => 'The :attribute must be a date before :0.',
        'before_or_equal' => 'The :attribute must be a date on or before :0.',
        'confirmed' => 'The :attribute confirmation does not match.',
        'same' => 'The :attribute must match :0.',
        'different' => 'The :attribute must be different from :0.',
        'digits' => 'The :attribute must be :0 digits.',
        'accepted' => 'The :attribute must be accepted.',
        'unique' => 'This :attribute is already registered.',
        'exists' => 'The selected :attribute is invalid.',
        'mobile_in' => 'Enter a valid Indian mobile number (+91 followed by 10 digits).',
        'password' => 'The password must be at least 8 characters and include upper and lower case letters, a number and a symbol.',
    ];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string|Closure>> $rules
     * @param array<string, string> $messages  'field.rule' or 'rule' => message
     * @param array<string, string> $attributes friendly field names
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly array $attributes = [],
        private readonly ?Database $db = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string|Closure>> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): self
    {
        return new self($data, $rules, $messages, $attributes);
    }

    /**
     * Register a custom rule. The callback receives ($value, array $params, array $data, string $field)
     * and returns true when valid.
     */
    public static function extend(string $rule, Closure $callback, string $message): void
    {
        self::$extensions[$rule] = [$callback, $message];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function passes(): bool
    {
        $this->errors = [];
        foreach ($this->rules as $field => $fieldRules) {
            $this->validateField($field, is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules);
        }
        return $this->errors === [];
    }

    /**
     * Validate or throw. Returns the validated subset of the data.
     *
     * @return array<string, mixed>
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
        return $this->validated();
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $value = $this->data[$field];
                $out[$field] = is_string($value) ? trim($value) : $value;
            }
        }
        return $out;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @param list<string|Closure> $rules */
    private function validateField(string $field, array $rules): void
    {
        $value = $this->data[$field] ?? null;
        if (is_string($value)) {
            $value = trim($value);
        }
        $ruleNames = array_map(static fn ($r) => is_string($r) ? explode(':', $r, 2)[0] : '', $rules);

        if (in_array('sometimes', $ruleNames, true) && !array_key_exists($field, $this->data)) {
            return;
        }
        $empty = $this->isEmpty($value);
        $implicit = ['required', 'required_if', 'accepted'];
        if ($empty && in_array('nullable', $ruleNames, true)) {
            return;
        }

        $sizeType = match (true) {
            in_array('integer', $ruleNames, true), in_array('numeric', $ruleNames, true) => 'numeric',
            in_array('array', $ruleNames, true) => 'array',
            default => 'string',
        };

        foreach ($rules as $rule) {
            if ($rule instanceof Closure) {
                if ($empty) {
                    continue;
                }
                $result = $rule($value, $this->data, $field);
                if ($result !== true && $result !== null) {
                    $this->addError($field, is_string($result) ? $result : 'The :attribute is invalid.', []);
                    return;
                }
                continue;
            }
            [$name, $paramString] = array_pad(explode(':', $rule, 2), 2, '');
            if (in_array($name, ['nullable', 'sometimes', 'bail'], true)) {
                continue;
            }
            $params = $name === 'regex' ? [$paramString] : ($paramString === '' ? [] : str_getcsv($paramString, ',', '"', ''));

            if ($empty && !in_array($name, $implicit, true)) {
                continue; // non-implicit rules skip empty values
            }
            if (!$this->check($name, $value, $params, $field, $sizeType)) {
                $key = in_array($name, ['min', 'max', 'between', 'size'], true) ? "{$name}.{$sizeType}" : $name;
                $message = $this->messages["{$field}.{$name}"]
                    ?? $this->messages[$name]
                    ?? self::$defaultMessages[$key]
                    ?? (self::$extensions[$name][1] ?? 'The :attribute is invalid.');
                $this->addError($field, $message, $params);
                return; // one error per field
            }
        }
    }

    /** @param list<string> $params */
    private function check(string $rule, mixed $value, array $params, string $field, string $sizeType): bool
    {
        return match ($rule) {
            'required' => !$this->isEmpty($value),
            'required_if' => (string) ($this->data[$params[0] ?? ''] ?? '') !== (string) ($params[1] ?? '') || !$this->isEmpty($value),
            'accepted' => in_array($value, ['1', 1, true, 'yes', 'on', 'true'], true),
            'string' => is_string($value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric' => is_numeric($value),
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off'], true),
            'array' => is_array($value),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'min' => $this->size($value, $sizeType) >= (float) $params[0],
            'max' => $this->size($value, $sizeType) <= (float) $params[0],
            'between' => ($s = $this->size($value, $sizeType)) >= (float) $params[0] && $s <= (float) $params[1],
            'size' => $this->size($value, $sizeType) == (float) $params[0],
            'in' => is_scalar($value) && in_array((string) $value, $params, true),
            'not_in' => is_scalar($value) && !in_array((string) $value, $params, true),
            'regex' => is_scalar($value) && preg_match($params[0], (string) $value) === 1,
            'alpha_num' => is_scalar($value) && preg_match('/^[\pL\pN]+$/u', (string) $value) === 1,
            'alpha_dash' => is_scalar($value) && preg_match('/^[\pL\pN_-]+$/u', (string) $value) === 1,
            'digits' => is_scalar($value) && preg_match('/^\d{' . (int) $params[0] . '}$/', (string) $value) === 1,
            'date' => $this->parseDate($value) !== null,
            'date_format' => is_string($value) && ($d = DateTimeImmutable::createFromFormat('!' . $params[0], $value)) !== false && $d->format($params[0]) === $value,
            'after' => $this->compareDate($value, $params[0], static fn (int $c): bool => $c > 0),
            'after_or_equal' => $this->compareDate($value, $params[0], static fn (int $c): bool => $c >= 0),
            'before' => $this->compareDate($value, $params[0], static fn (int $c): bool => $c < 0),
            'before_or_equal' => $this->compareDate($value, $params[0], static fn (int $c): bool => $c <= 0),
            'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $value,
            'same' => ($this->data[$params[0]] ?? null) === $value,
            'different' => ($this->data[$params[0]] ?? null) !== $value,
            'mobile_in' => is_string($value) && preg_match('/^(\+91[\s-]?)?[6-9]\d{9}$/', $value) === 1,
            'password' => is_string($value) && strlen($value) >= 8 && preg_match('/[a-z]/', $value) && preg_match('/[A-Z]/', $value)
                && preg_match('/\d/', $value) && preg_match('/[^A-Za-z0-9]/', $value),
            'unique' => $this->checkUnique($value, $params),
            'exists' => $this->checkExists($value, $params),
            default => $this->checkExtension($rule, $value, $params, $field),
        };
    }

    /** @param list<string> $params */
    private function checkExtension(string $rule, mixed $value, array $params, string $field): bool
    {
        if (!isset(self::$extensions[$rule])) {
            throw new \InvalidArgumentException("Unknown validation rule [{$rule}].");
        }
        return (bool) (self::$extensions[$rule][0])($value, $params, $this->data, $field);
    }

    /** @param list<string> $params table,column[,ignoreId[,idColumn]] */
    private function checkUnique(mixed $value, array $params): bool
    {
        $db = $this->db ?? Database::getInstance();
        $table = $db->quoteIdentifier($params[0]);
        $column = $db->quoteIdentifier($params[1] ?? 'id');
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
        $bindings = [$value];
        if (isset($params[2]) && $params[2] !== '' && $params[2] !== 'NULL') {
            $idColumn = $db->quoteIdentifier($params[3] ?? 'id');
            $sql .= " AND {$idColumn} <> ?";
            $bindings[] = $params[2];
        }
        return (int) $db->scalar($sql, $bindings) === 0;
    }

    /** @param list<string> $params table,column */
    private function checkExists(mixed $value, array $params): bool
    {
        $db = $this->db ?? Database::getInstance();
        $table = $db->quoteIdentifier($params[0]);
        $column = $db->quoteIdentifier($params[1] ?? 'id');
        return (int) $db->scalar("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?", [$value]) > 0;
    }

    private function size(mixed $value, string $type): float
    {
        return match ($type) {
            'numeric' => is_numeric($value) ? (float) $value : 0.0,
            'array' => is_array($value) ? count($value) : 0,
            default => is_scalar($value) ? mb_strlen((string) $value) : 0,
        };
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        foreach (['!Y-m-d', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i'] as $format) {
            $d = DateTimeImmutable::createFromFormat($format, $value);
            if ($d !== false && DateTimeImmutable::getLastErrors() === false) {
                return $d;
            }
        }
        return null;
    }

    /** @param Closure(int): bool $cmp */
    private function compareDate(mixed $value, string $other, Closure $cmp): bool
    {
        $a = $this->parseDate($value);
        $otherValue = $this->data[$other] ?? null;
        $b = is_string($otherValue) ? $this->parseDate($otherValue) : match ($other) {
            'today' => new DateTimeImmutable('today'),
            'tomorrow' => new DateTimeImmutable('tomorrow'),
            'yesterday' => new DateTimeImmutable('yesterday'),
            default => $this->parseDate($other),
        };
        if ($a === null || $b === null) {
            return false;
        }
        return $cmp($a <=> $b);
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @param list<string> $params */
    private function addError(string $field, string $message, array $params): void
    {
        $label = $this->attributes[$field] ?? str_replace('_', ' ', $field);
        $replace = [':attribute' => $label];
        foreach ($params as $i => $p) {
            $replace[':' . $i] = $this->attributes[$p] ?? $p;
        }
        $this->errors[$field][] = strtr($message, $replace);
    }
}
