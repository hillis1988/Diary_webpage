<?php

declare(strict_types=1);

namespace Diary\Access;

/**
 * One control on the home page: a label, where it goes, and the operation kind it
 * stands for.
 *
 * The list a caller gets back from {@see Navigation::for()} already IS the
 * permitted set (Requirement 3.2) - there is no second filtering step in a
 * template, because a template that re-decided visibility could disagree with the
 * permission matrix. A view only ever loops over what it is given.
 */
final class NavigationItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $path,
    ) {
    }
}
