<?php

namespace App\Support\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * مطابقة عمودٍ بما يراه المستخدم في الخلية، لا بما خُزّن خامّاً.
 * أسماء الأعمدة من المتحكّم لا من الطلب.
 */
class ColumnMatch
{
    public static function number(Builder|QueryBuilder $query, string $column, string $term): void
    {
        $number = self::numberIn($term);

        if ($number === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereRaw('CAST('.self::qualify($query, $column).' AS CHAR) LIKE ?', ["%{$number}%"]);
    }

    /**
     * تاريخٌ واحد يطابق يومه، ومدى «من|إلى» يشمل الطرفين.
     * طرفٌ فارغ يعني بلا حدّ من تلك الجهة.
     */
    public static function date(Builder|QueryBuilder $query, string $column, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $column = self::qualify($query, $column);

        if (! str_contains($term, '|')) {
            $day = self::day($term);
            $day ? $query->whereDate($column, $day) : $query->whereRaw('0 = 1');

            return;
        }

        [$from, $to] = array_pad(explode('|', $term, 2), 2, '');
        $from = self::day($from);
        $to = self::day($to);

        if ($from === null && $to === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        if ($from !== null) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate($column, '<=', $to);
        }
    }

    /** مبلغ مخزّن بالوحدة الصغرى. يطابق «10» و«10.00» كما يُعرضان. */
    public static function money(
        Builder|QueryBuilder $query,
        string $column,
        string $term,
        ?string $currencyColumn = 'currency',
    ): void {
        $number = self::numberIn($term);

        if ($number === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $amount = self::qualify($query, $column);
        $decimals = $currencyColumn === null
            ? '2'
            : 'COALESCE((SELECT decimal_places FROM currencies WHERE currencies.code = '.self::qualify($query, $currencyColumn).'), 2)';

        $query->where(function ($inner) use ($amount, $number, $decimals) {
            $inner->whereRaw("CAST({$amount} / POW(10, {$decimals}) AS CHAR) LIKE ?", ["%{$number}%"])
                ->orWhereRaw("{$amount} = ROUND(? * POW(10, {$decimals}))", [(float) $number]);
        });
    }

    /** اسم مستأجر أو الاسم الظاهر في ملفّه. */
    public static function tenantName(Builder|QueryBuilder $query, string $relation, string $term): void
    {
        $query->whereHas($relation, function ($tenant) use ($term) {
            $tenant->where('name', 'like', "%{$term}%")
                ->orWhereHas('supplierProfile', fn ($profile) => $profile->where('display_name', 'like', "%{$term}%"));
        });
    }

    private static function qualify(Builder|QueryBuilder $query, string $column): string
    {
        if (str_contains($column, '.') || str_contains($column, '(')) {
            return $column;
        }

        $table = $query instanceof Builder ? $query->getModel()->getTable() : '';

        return $table === '' ? $column : "{$table}.{$column}";
    }

    private static function day(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private static function numberIn(string $term): ?string
    {
        $latin = strtr(mb_strtolower(trim($term)), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.',
        ]);
        $latin = str_replace([' ', '٬', '،'], '', $latin);
        $latin = preg_replace('/,(?=\d{3}(?:\D|$))/', '', $latin) ?? $latin;

        return preg_match('/\d+(?:\.\d+)?/', $latin, $match) ? $match[0] : null;
    }
}
