<?php

/**
 * Copyright since 2026 Massimiliano Palermo
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to maxx.palermo@gmail.com so we can send you a copy immediately.
 *
 * @author    Massimiliano Palermo <maxx.palermo@gmail.com>
 * @copyright Since 2026 Massimiliano Palermo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace FairyBus\Libraries;

use CodeIgniter\Database\BaseConnection;

class LegacyDatabase
{
    /**
     * @return list<string>
     */
    public static function listTables(BaseConnection $db): array
    {
        $prefix = $db->getPrefix();

        $all = (array) $db->listTables(false);

        if ($prefix === '') {
            return array_values($all);
        }

        return array_values(array_filter(
            $all,
            static fn(string $table): bool => !str_starts_with($table, $prefix),
        ));
    }

    /**
     * @return mixed
     */
    public static function withoutPrefix(BaseConnection $db, callable $callback)
    {
        $oldPrefix = $db->getPrefix();
        $db->setPrefix('');

        try {
            return $callback($db);
        } finally {
            $db->setPrefix($oldPrefix);
        }
    }

    /**
     * @return list<string>
     */
    public static function getFieldNames(BaseConnection $db, string $table): array
    {
        return self::withoutPrefix($db, static fn(BaseConnection $db): array => $db->getFieldNames($table));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function insert(BaseConnection $db, string $table, array $data): bool
    {
        return self::withoutPrefix(
            $db,
            static fn(BaseConnection $db): bool => $db->table($table)->insert($data),
        );
    }
}
