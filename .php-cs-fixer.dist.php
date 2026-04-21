<?php

declare(strict_types=1);

/**
 * Netresearch fork coding-style configuration.
 *
 *   composer global require friendsofphp/php-cs-fixer
 *   php-cs-fixer fix --config Build/.php-cs-fixer.dist.php
 */

if (PHP_SAPI !== 'cli') {
    die('This script supports command line usage only. Please check your command.');
}

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2x0'                    => true,
        '@Symfony'                      => true,

        // Additional custom rules
        'concat_space'                  => [
            'spacing' => 'one',
        ],
        'phpdoc_to_comment'             => false,
        'phpdoc_no_alias_tag'           => false,
        'phpdoc_annotation_without_dot' => false,
        'no_superfluous_phpdoc_tags'    => false,
        'phpdoc_separation'             => [
            'groups' => [
                [
                    'author',
                    'license',
                    'link',
                ],
            ],
        ],
        'single_line_throw'             => false,
        'self_accessor'                 => false,
        'global_namespace_import'       => [
            'import_classes'   => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'method_argument_space'         => [
            'on_multiline'        => 'ensure_fully_multiline',
            'attribute_placement' => 'same_line',
        ],
        'binary_operator_spaces'        => [
            'operators' => [
                '='  => 'align_single_space_minimal',
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'yoda_style'                    => [
            'equal'                => false,
            'identical'            => false,
            'less_and_greater'     => false,
            'always_move_variable' => false,
        ],
        'blank_line_before_statement'   => [
            'statements' => [
                'break',
                'continue',
                'for',
                'foreach',
                'if',
                'return',
                'switch',
                'throw',
                'try',
                'while',
            ],
        ],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude('.build')
            ->in(__DIR__)
    );
