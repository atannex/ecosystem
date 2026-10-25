<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

/**
 * How a field is compared with a search term.
 */
enum MatchMode: string
{
    /** Anywhere in the value: "coll" finds "Atanga Collins". */
    case Contains = 'contains';

    /** At the start of the value: "coll" finds "Collins", not "McCollins". */
    case Prefix = 'prefix';

    /** The whole value, ignoring case: codes, emails, slugs. */
    case Exact = 'exact';

    /** Database full-text index (MySQL/PostgreSQL); Contains elsewhere. */
    case FullText = 'fulltext';
}
