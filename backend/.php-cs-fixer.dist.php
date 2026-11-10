<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations'])
    ->exclude('Fixtures');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'phpdoc_to_comment' => false,
        'phpdoc_summary' => false,
        'single_line_throw' => false,
        'yoda_style' => true,
    ])
    ->setFinder($finder);
