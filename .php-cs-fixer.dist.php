<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

return new Config()
    ->setRiskyAllowed(false)
    ->setRules([
        'statement_indentation' => true,

        'align_multiline_comment' => [
            'comment_type' => 'phpdocs_only',
        ],

        'phpdoc_indent' => true,
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,

        'phpdoc_order' => [
            'order' => [
                'param',
                'return',
                'throws',
            ],
        ],

        'phpdoc_param_order' => true,

        'phpdoc_align' => [
            'align' => 'left',
        ],

        'phpdoc_types' => true,
        'phpdoc_scalar' => true,

        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],

        'phpdoc_no_duplicate_types' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_var_annotation_correct_order' => true,
        'phpdoc_var_without_name' => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_tag_casing' => true,

        'no_empty_phpdoc' => true,
    ])
    ->setFinder($finder);