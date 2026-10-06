<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

/**
 * WHMCS cannot take a catalogue change as asked. The message says what to do and is meant for the
 * administrator.
 */
final class CatalogueException extends \RuntimeException
{
}
