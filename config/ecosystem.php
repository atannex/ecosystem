<?php

return [

    'search' => [

        /*
        |----------------------------------------------------------------------
        | Input limits
        |----------------------------------------------------------------------
        |
        | Search input comes from visitors: long queries and many terms make
        | expensive SQL, so both are capped. Terms shorter than the minimum are
        | ignored (unless quoted or used with a field filter).
        |
        */

        'max_query_length' => 200,

        'max_terms' => 8,

        'min_term_length' => 2,

        /*
        | Words ignored unless the query has nothing else: ['the', 'of', …].
        */

        'stopwords' => [],

        /*
        |----------------------------------------------------------------------
        | Translatable columns
        |----------------------------------------------------------------------
        |
        | Columns of models using spatie/laravel-translatable are searched in
        | the current locale, falling back to app.fallback_locale.
        |
        */

        'translatable' => true,

        /*
        |----------------------------------------------------------------------
        | Relevance
        |----------------------------------------------------------------------
        |
        | A field's weight is multiplied by how well it matched.
        |
        */

        'scores' => [
            'exact' => 3,
            'prefix' => 2,
            'contains' => 1,
        ],

        /*
        | Results per model in global searches (Search::across()).
        */

        'per_type' => 5,

        /*
        | Element wrapped around matches by Search::highlight().
        */

        'highlight_tag' => 'mark',

        'highlight_class' => null,

    ],

];
