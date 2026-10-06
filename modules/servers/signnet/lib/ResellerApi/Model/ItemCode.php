<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

/**
 * The items a reseller can put in its packages and add-ons.
 */
final class ItemCode
{
    public const DOCUMENTS = 'documents';
    public const SEATS = 'seats';
    public const TEMPLATES = 'templates';
    public const NOTARIZATIONS = 'notarizations';

    public const ALL = [self::DOCUMENTS, self::SEATS, self::TEMPLATES, self::NOTARIZATIONS];

    private function __construct()
    {
    }
}
