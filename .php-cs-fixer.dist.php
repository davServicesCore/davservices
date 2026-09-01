<?php

declare(strict_types=1);

/**
 * Coding standard: PSR-12 plus the rules that keep diffs small and
 * intent visible.
 *
 * @license Apache-2.0
 */

$header = <<<'TXT'
This file is part of davServices.

(c) Felix Böck <https://dav.services>

Licensed under the Apache License, Version 2.0.
For the full copyright and license information, see the LICENSE file.
TXT;

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/bin',
    ])
    ->name('*.php')
    // autoload.php ships inside the package and is hashed into MANIFEST.json,
    // so it is production code and belongs under the same standard as src/.
    ->append([__DIR__ . '/autoload.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@PSR12'                          => true,
        '@PHP82Migration'                 => true,
        'declare_strict_types'            => true,
        'strict_param'                    => true,
        'strict_comparison'               => true,
        'header_comment'                  => ['header' => $header, 'separate' => 'both', 'location' => 'after_open'],
        'ordered_imports'                 => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'               => true,
        'global_namespace_import'         => ['import_classes' => true, 'import_functions' => false],
        'phpdoc_align'                    => ['align' => 'left'],
        'phpdoc_order'                    => true,
        'phpdoc_separation'               => true,
        'no_superfluous_phpdoc_tags'      => ['allow_mixed' => true],
        'void_return'                     => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'trailing_comma_in_multiline'     => ['elements' => ['arrays', 'arguments', 'parameters']],
        'single_line_throw'               => false,
        'concat_space'                    => ['spacing' => 'one'],
        'yoda_style'                      => false,
    ]);
