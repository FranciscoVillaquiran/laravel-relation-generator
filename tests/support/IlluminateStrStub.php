<?php

namespace Illuminate\Support;

class Str
{
    public static function singular(string $value): string
    {
        $irregular = [
            'children' => 'child',
            'people' => 'person',
            'mice' => 'mouse',
            'courses' => 'course',
        ];
        if (isset($irregular[strtolower($value)])) {
            return $irregular[strtolower($value)];
        }
        foreach (['children' => 'child', 'people' => 'person', 'mice' => 'mouse'] as $plural => $singular) {
            if (str_ends_with(strtolower($value), $plural)) {
                return substr($value, 0, -strlen($plural)) . $singular;
            }
        }
        if (preg_match('/ies$/i', $value) === 1) {
            return substr($value, 0, -3) . 'y';
        }
        if (preg_match('/(s|x|z|ch|sh)es$/i', $value) === 1) {
            return substr($value, 0, -2);
        }
        if (preg_match('/s$/i', $value) === 1) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    public static function camel(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))));
    }

    public static function plural(string $value): string
    {
        $irregular = [
            'child' => 'children',
            'person' => 'people',
            'mouse' => 'mice',
        ];
        if (isset($irregular[strtolower($value)])) {
            return $irregular[strtolower($value)];
        }
        foreach (['child' => 'children', 'person' => 'people', 'mouse' => 'mice'] as $singular => $plural) {
            if (str_ends_with(strtolower($value), $singular)) {
                return substr($value, 0, -strlen($singular)) . $plural;
            }
        }
        if (preg_match('/[^aeiou]y$/i', $value) === 1) {
            return substr($value, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $value) === 1) {
            return $value . 'es';
        }

        return $value . 's';
    }

    public static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    public static function afterLast(string $subject, string $search): string
    {
        $position = strrpos($subject, $search);
        return $position === false ? $subject : substr($subject, $position + strlen($search));
    }
}
