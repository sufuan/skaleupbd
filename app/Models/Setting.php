<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'updated_by'];

    public static function encodeValue(mixed $value, string $type): ?string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            'number' => (string) $value,
            default => $value === null ? null : (string) $value,
        };
    }

    public function decodedValue(): mixed
    {
        return match ($this->type) {
            'boolean' => $this->value === '1' || $this->value === 'true',
            'json' => json_decode((string) $this->value, true),
            'number' => is_numeric($this->value) ? $this->value + 0 : $this->value,
            default => $this->value,
        };
    }
}
