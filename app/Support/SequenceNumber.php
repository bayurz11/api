<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

class SequenceNumber
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function generate(string $prefix, string $modelClass, string $column): string
    {
        do {
            $number = sprintf(
                '%s-%s-%04d',
                $prefix,
                now()->format('YmdHis'),
                random_int(1, 9999),
            );
        } while ($modelClass::query()->where($column, $number)->exists());

        return $number;
    }
}
