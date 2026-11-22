<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/** Speichern abgelehnt – die Rolle darf diese Blöcke nicht einfügen, ändern oder löschen (Core\BlockGuard). HTTP 403. */
final class BlockDenied extends Http\HttpException
{
    /** @param list<string> $problems einzelne Gründe (Block und was nicht erlaubt ist) */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(403, __('Nicht gespeichert: {list}', ['list' => implode(' · ', array_slice($problems, 0, 5))])
            . (count($problems) > 5 ? ' ' . __('(und {n} weitere)', ['n' => count($problems) - 5]) : ''));
    }
}
