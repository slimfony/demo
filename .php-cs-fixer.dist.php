<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/public'])->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        '@PHP8x4Migration' => true,
        '@PHP8x4Migration:risky' => true,
        'declare_strict_types' => true,
        'final_class' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'yoda_style' => true,
        'static_lambda' => true,
        'void_return' => true,
        'modernize_strpos' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'native_constant_invocation' => ['scope' => 'namespaced', 'strict' => true],
    ])
    ->setFinder($finder);
