<?php

namespace Codewiser\Tests;

/**
 * Locale identifiers as a backed enum, to cover the BackedEnum code paths.
 */
enum TestLocale: string
{
    case Russian = 'ru';
    case British = 'en-GB';
    case Devanagari = 'de-DEVA';
}
