<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $st = Database::pdo()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public const DEFAULT_SCHOOL_NAME = 'วิทยาลัยเทคนิค';

    public static function schoolName(): string
    {
        $name = trim((string) self::get('school_name', ''));
        return $name !== '' ? $name : self::DEFAULT_SCHOOL_NAME;
    }

    /**
     * Current academic year (พ.ศ.) and term. An admin-set value in settings
     * wins; a blank one falls back to the Thai school calendar: term 1 runs
     * May–October, term 2 November–April (Jan–Apr still belong to the
     * academic year that started the previous May).
     *
     * @return array{year:int,term:int,auto_year:bool,auto_term:bool}
     */
    public static function academicTerm(?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $month = (int) $now->format('n');
        $be = (int) $now->format('Y') + 543;
        $autoYear = $month >= 5 ? $be : $be - 1;
        $autoTerm = ($month >= 5 && $month <= 10) ? 1 : 2;

        $year = (int) self::get('academic_year', '0');
        $term = (int) self::get('academic_term', '0');
        $yearOk = $year >= 2500 && $year <= 2700;
        $termOk = $term >= 1 && $term <= 3;

        return [
            'year' => $yearOk ? $year : $autoYear,
            'term' => $termOk ? $term : $autoTerm,
            'auto_year' => !$yearOk,
            'auto_term' => !$termOk,
        ];
    }

    public static function set(string $key, string $value): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $st->execute([$key, $value]);
    }
}
