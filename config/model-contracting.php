<?php

return [
    'default_per_page' => (int) env('MODEL_CONTRACTING_DEFAULT_PER_PAGE', 10),

    'max_per_page' => (int) env('MODEL_CONTRACTING_MAX_PER_PAGE', 100),

    'pagination_with_total' => (bool) env('MODEL_CONTRACTING_PAGINATION_WITH_TOTAL', true),
];
