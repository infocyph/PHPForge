PEST
Source:      phpforge
Config file: pest.xml
Config path: /app/PHPForge/resources/pest.xml
Config:
{
    "@attributes": {
        "bootstrap": "vendor/autoload.php",
        "colors": "true",
        "displayDetailsOnTestsThatTriggerWarnings": "true"
    },
    "testsuites": {
        "testsuite": {
            "@attributes": {
                "name": "tests"
            },
            "directory": "./tests"
        }
    },
    "source": {
        "include": {
            "directory": "./src"
        }
    },
    "coverage": {
        "@attributes": {
            "includeUncoveredFiles": "true"
        }
    },
    "php": {
        "ini": {
            "@attributes": {
                "name": "error_reporting",
                "value": "24575"
            }
        }
    }
}

PHPBENCH
Source:      phpforge
Config file: phpbench.json
Config path: /app/PHPForge/resources/phpbench.json
Config:
{
    "$schema": "./vendor/phpbench/phpbench/phpbench.schema.json",
    "runner.bootstrap": "vendor/autoload.php",
    "runner.path": "benchmarks",
    "runner.file_pattern": "*Bench.php",
    "runner.attributes": true,
    "runner.annotations": false,
    "runner.progress": "dots",
    "runner.retry_threshold": 8,
    "report.generators": {
        "chart": {
            "title": "Benchmark Chart",
            "description": "Console bar chart grouped by benchmark subject",
            "generator": "component",
            "components": [
                {
                    "component": "bar_chart_aggregate",
                    "x_partition": [
                        "subject_name"
                    ],
                    "bar_partition": [
                        "benchmark_name"
                    ],
                    "y_expr": "mode(partition['result_time_avg'])",
                    "y_axes_label": "yValue as time precision 1"
                }
            ]
        }
    }
}

PHPPROBE
Source:      phpforge
Config file: phpprobe.json
Config path: /app/PHPForge/resources/phpprobe.json
Config:
{
    "preset": "standard"
}

PHPCS
Source:      phpforge
Config file: phpcs.xml.dist
Config path: /app/PHPForge/resources/phpcs.xml.dist
Config:
{
    "@attributes": {
        "name": "InfocyphPHPForge"
    },
    "description": "Semantic PHPCS checks not covered by Pint/Psalm/PHPStan.",
    "arg": [
        {
            "@attributes": {
                "name": "basepath",
                "value": "."
            }
        },
        {
            "@attributes": {
                "name": "extensions",
                "value": "php"
            }
        },
        {
            "@attributes": {
                "name": "colors"
            }
        },
        {
            "@attributes": {
                "value": "sp"
            }
        }
    ],
    "exclude-pattern": [
        "*/vendor/*",
        "*/node_modules/*",
        "*/.git/*",
        "*/.idea/*",
        "*/.vscode/*",
        "*/coverage/*",
        "*/.phpunit.cache/*",
        "*/.psalm-cache/*",
        "*/build/*",
        "*/dist/*",
        "*/tmp/*",
        "*/.tmp/*",
        "*/storage/*",
        "*/bootstrap/cache/*",
        "*/var/cache/*"
    ],
    "comment": [
        [],
        [],
        [],
        [],
        [],
        []
    ],
    "rule": [
        {
            "@attributes": {
                "ref": "Generic.NamingConventions.UpperCaseConstantName"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.CodeAnalysis.RequireExplicitBooleanOperatorPrecedence"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.CodeAnalysis.UnusedFunctionParameter"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.CodeAnalysis.EmptyStatement"
            },
            "exclude": {
                "@attributes": {
                    "name": "Generic.CodeAnalysis.EmptyStatement.DetectedCatch"
                }
            }
        },
        {
            "@attributes": {
                "ref": "Squiz.PHP.NonExecutableCode"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.PHP.BacktickOperator"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.PHP.DeprecatedFunctions"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.PHP.NoSilencedErrors"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.PHP.DisallowShortOpenTag"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.VersionControl.GitMergeConflict"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.Files.LineEndings"
            }
        },
        {
            "@attributes": {
                "ref": "Generic.PHP.ForbiddenFunctions"
            },
            "properties": {
                "property": {
                    "@attributes": {
                        "name": "forbiddenFunctions",
                        "type": "array"
                    },
                    "element": [
                        {
                            "@attributes": {
                                "key": "echo",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "print",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "die",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "exit",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "eval",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "sleep",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "print_r",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "d",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "dd",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "dump",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "dump_d",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "ray",
                                "value": "null"
                            }
                        },
                        {
                            "@attributes": {
                                "key": "var_dump",
                                "value": "null"
                            }
                        }
                    ]
                }
            }
        }
    ]
}

PHPSTAN
Source:      phpforge
Config file: phpstan.neon.dist
Config path: /app/PHPForge/resources/phpstan.neon.dist
Config:
"includes:\n    - %currentWorkingDirectory%/vendor/tomasvotruba/cognitive-complexity/config/extension.neon\n\nparameters:\n    customRulesetUsed: true\n    level: max\n    paths:\n        - %currentWorkingDirectory%\n    excludePaths:\n        analyseAndScan:\n            - %currentWorkingDirectory%/vendor/*\n            - %currentWorkingDirectory%/node_modules/*\n            - %currentWorkingDirectory%/coverage/*\n            - %currentWorkingDirectory%/.phpunit.cache/*\n            - %currentWorkingDirectory%/.psalm-cache/*\n            - %currentWorkingDirectory%/build/*\n            - %currentWorkingDirectory%/dist/*\n            - %currentWorkingDirectory%/tmp/*\n            - %currentWorkingDirectory%/.tmp/*\n            - %currentWorkingDirectory%/storage/*\n            - %currentWorkingDirectory%/bootstrap/cache/*\n            - %currentWorkingDirectory%/var/cache/*\n            - %currentWorkingDirectory%/tests/*\n            - %currentWorkingDirectory%/resources/*\n            - %currentWorkingDirectory%/bin/*\n            - %currentWorkingDirectory%/benchmarks/*\n            - %currentWorkingDirectory%/examples/*\n    parallel:\n        maximumNumberOfProcesses: 2\n    cognitive_complexity:\n        class: 80\n        function: 12\n        dependency_tree: 80\n        dependency_tree_types: []\n    reportUnmatchedIgnoredErrors: true\n"
Effective:
{
    "tool": "phpstan",
    "source": "phpforge",
    "config_file": "phpstan.neon.dist",
    "config_path": "/app/PHPForge/resources/phpstan.neon.dist",
    "memory_limit": "1G",
    "analyse_paths": []
}

PINT
Source:      phpforge
Config file: pint.json
Config path: /app/PHPForge/resources/pint.json
Config:
{
    "preset": "per",
    "exclude": [
        "tests",
        "vendor",
        "node_modules",
        "coverage",
        ".phpunit.cache",
        ".psalm-cache",
        "build",
        "dist",
        "tmp",
        ".tmp",
        "storage",
        "bootstrap/cache",
        "var/cache"
    ],
    "notPath": [
        "rector.php"
    ],
    "rules": {
        "ordered_imports": {
            "imports_order": [
                "class",
                "function",
                "const"
            ],
            "sort_algorithm": "alpha"
        },
        "no_unused_imports": true,
        "class_attributes_separation": {
            "elements": {
                "trait_import": "none",
                "case": "one",
                "const": "one",
                "property": "one",
                "method": "one"
            }
        },
        "ordered_class_elements": {
            "order": [
                "use_trait",
                "case",
                "constant_public",
                "constant_protected",
                "constant_private",
                "constant",
                "property_public_static",
                "property_protected_static",
                "property_private_static",
                "property_static",
                "property_public_readonly",
                "property_protected_readonly",
                "property_private_readonly",
                "property_public_abstract",
                "property_protected_abstract",
                "property_public",
                "property_protected",
                "property_private",
                "property",
                "construct",
                "destruct",
                "magic",
                "phpunit",
                "method_public_abstract_static",
                "method_protected_abstract_static",
                "method_private_abstract_static",
                "method_public_abstract",
                "method_protected_abstract",
                "method_private_abstract",
                "method_abstract",
                "method_public_static",
                "method_public",
                "method_protected_static",
                "method_protected",
                "method_private_static",
                "method_private",
                "method_static",
                "method"
            ],
            "sort_algorithm": "alpha"
        },
        "blank_line_after_opening_tag": true,
        "no_alias_functions": true,
        "multiline_whitespace_before_semicolons": true,
        "no_trailing_whitespace": true,
        "blank_line_before_statement": {
            "statements": [
                "break",
                "continue",
                "declare",
                "return",
                "throw",
                "try"
            ]
        },
        "phpdoc_align": {
            "align": "left"
        },
        "binary_operator_spaces": {
            "default": "single_space"
        },
        "concat_space": {
            "spacing": "one"
        },
        "cast_spaces": true,
        "unary_operator_spaces": true,
        "ternary_operator_spaces": true,
        "array_indentation": true,
        "trim_array_spaces": true,
        "method_argument_space": {
            "on_multiline": "ensure_fully_multiline"
        },
        "trailing_comma_in_multiline": {
            "elements": [
                "arrays",
                "arguments",
                "parameters",
                "match"
            ]
        },
        "single_quote": true,
        "single_line_empty_body": true,
        "no_multiple_statements_per_line": true,
        "no_extra_blank_lines": true,
        "no_whitespace_in_blank_line": true,
        "single_blank_line_at_eof": true,
        "statement_indentation": true,
        "control_structure_braces": true,
        "control_structure_continuation_position": true,
        "declare_parentheses": true,
        "declare_strict_types": true,
        "lowercase_keywords": true,
        "constant_case": true,
        "lowercase_static_reference": true,
        "native_function_casing": true,
        "nullable_type_declaration_for_default_null_value": true,
        "no_superfluous_phpdoc_tags": true,
        "phpdoc_trim": true
    }
}

PSALM
Source:      phpforge
Config file: psalm.xml
Config path: /app/PHPForge/resources/psalm.xml
Config:
{
    "@attributes": {
        "errorLevel": "2",
        "findUnusedCode": "true",
        "findUnusedPsalmSuppress": "true",
        "runTaintAnalysis": "true",
        "reportInfo": "true",
        "checkForThrowsDocblock": "true",
        "resolveFromConfigFile": "false"
    },
    "projectFiles": {
        "directory": {
            "@attributes": {
                "name": "."
            }
        },
        "ignoreFiles": {
            "@attributes": {
                "allowMissingFiles": "true"
            },
            "directory": [
                {
                    "@attributes": {
                        "name": "vendor"
                    }
                },
                {
                    "@attributes": {
                        "name": "tests"
                    }
                },
                {
                    "@attributes": {
                        "name": "resources"
                    }
                },
                {
                    "@attributes": {
                        "name": "bin"
                    }
                },
                {
                    "@attributes": {
                        "name": "benchmarks"
                    }
                }
            ]
        }
    },
    "issueHandlers": {
        "TaintedInput": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedSql": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedShell": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedHtml": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedXpath": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedInclude": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedUnserialize": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedEval": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedFile": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "TaintedSSRF": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "UnusedClass": {
            "@attributes": {
                "errorLevel": "error"
            }
        },
        "UnusedConstructor": {
            "@attributes": {
                "errorLevel": "info"
            }
        },
        "PossiblyUnusedMethod": {
            "@attributes": {
                "errorLevel": "info"
            }
        },
        "PossiblyUnusedProperty": {
            "@attributes": {
                "errorLevel": "info"
            }
        },
        "PossiblyUnusedReturnValue": {
            "@attributes": {
                "errorLevel": "info"
            }
        }
    }
}

RECTOR
Source:      phpforge
Config file: rector.php
Config path: /app/PHPForge/resources/rector.php
Config:
"<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\nuse Rector\\ValueObject\\PhpVersion;\n\nfunction resolveRectorPhpVersion(): ?int\n{\n    $reflection = new ReflectionClass(PhpVersion::class);\n    $constants = $reflection->getConstants();\n    $current = (PHP_MAJOR_VERSION * 10) + PHP_MINOR_VERSION;\n    $candidates = [];\n\n    foreach ($constants as $name => $value) {\n        if (! is_int($value) || ! is_string($name) || ! str_starts_with($name, 'PHP_')) {\n            continue;\n        }\n\n        $suffix = substr($name, 4);\n\n        if ($suffix === false || $suffix === '' || ! ctype_digit($suffix)) {\n            continue;\n        }\n\n        $versionId = (int) $suffix;\n\n        if ($versionId <= $current) {\n            $candidates[$versionId] = $value;\n        }\n    }\n\n    if ($candidates === []) {\n        return null;\n    }\n\n    ksort($candidates);\n\n    return end($candidates) ?: null;\n}\n\n$config = RectorConfig::configure()\n    ->withPaths([getcwd()])\n    ->withSkip([\n        getcwd().'/vendor',\n        getcwd().'/node_modules',\n        getcwd().'/coverage',\n        getcwd().'/.phpunit.cache',\n        getcwd().'/.psalm-cache',\n        getcwd().'/build',\n        getcwd().'/dist',\n        getcwd().'/tmp',\n        getcwd().'/.tmp',\n        getcwd().'/storage',\n        getcwd().'/bootstrap/cache',\n        getcwd().'/var/cache',\n        getcwd().'/tests',\n        getcwd().'/resources',\n        getcwd().'/bin',\n        getcwd().'/benchmarks',\n        getcwd().'/examples',\n    ])\n    ->withPreparedSets(deadCode: true)\n    ->withPhpSets();\n\n$resolvedPhpVersion = resolveRectorPhpVersion();\n\nif (is_int($resolvedPhpVersion)) {\n    $config = $config->withPhpVersion($resolvedPhpVersion);\n}\n\nreturn $config;\n"

CAPTAINHOOK
Source:      project
Config file: captainhook.json
Config path: /app/PHPForge/captainhook.json
Config:
{
    "commit-msg": {
        "enabled": false,
        "actions": []
    },
    "pre-push": {
        "enabled": false,
        "actions": []
    },
    "pre-commit": {
        "enabled": true,
        "actions": [
            {
                "action": "composer validate --strict",
                "options": []
            },
            {
                "action": "composer normalize --dry-run",
                "options": []
            },
            {
                "action": "composer ic:release:audit",
                "options": []
            },
            {
                "action": "composer ic:ci",
                "options": []
            }
        ]
    },
    "prepare-commit-msg": {
        "enabled": false,
        "actions": []
    },
    "post-commit": {
        "enabled": false,
        "actions": []
    },
    "post-merge": {
        "enabled": false,
        "actions": []
    },
    "post-checkout": {
        "enabled": false,
        "actions": []
    },
    "post-rewrite": {
        "enabled": false,
        "actions": []
    },
    "post-change": {
        "enabled": false,
        "actions": []
    }
}

DEPTRAC
Source:      phpforge
Config file: deptrac.yaml
Config path: /app/PHPForge/resources/deptrac.yaml
Config:
"deptrac:\n  paths:\n    - '%currentWorkingDirectory%'\n  exclude_files:\n    - '#(^|[\\\\/])vendor([\\\\/]|$)#'\n    - '#(^|[\\\\/])node_modules([\\\\/]|$)#'\n    - '#(^|[\\\\/])\\.git([\\\\/]|$)#'\n    - '#(^|[\\\\/])\\.idea([\\\\/]|$)#'\n    - '#(^|[\\\\/])\\.vscode([\\\\/]|$)#'\n    - '#(^|[\\\\/])coverage([\\\\/]|$)#'\n    - '#(^|[\\\\/])\\.phpunit\\.cache([\\\\/]|$)#'\n    - '#(^|[\\\\/])\\.psalm-cache([\\\\/]|$)#'\n    - '#(^|[\\\\/])build([\\\\/]|$)#'\n    - '#(^|[\\\\/])dist([\\\\/]|$)#'\n    - '#(^|[\\\\/])tmp([\\\\/]|$)#'\n    - '#(^|[\\\\/])\\.tmp([\\\\/]|$)#'\n    - '#(^|[\\\\/])storage([\\\\/]|$)#'\n    - '#(^|[\\\\/])bootstrap[\\\\/]cache([\\\\/]|$)#'\n    - '#(^|[\\\\/])var[\\\\/]cache([\\\\/]|$)#'\n    - '#(^|[\\\\/])tests([\\\\/]|$)#'\n    - '#(^|[\\\\/])resources([\\\\/]|$)#'\n    - '#(^|[\\\\/])bin([\\\\/]|$)#'\n    - '#(^|[\\\\/])benchmarks([\\\\/]|$)#'\n    - '#(^|[\\\\/])examples([\\\\/]|$)#'\n  layers:\n    - name: Project\n      collectors:\n        - type: directory\n          value: '.*'\n    - name: Vendor\n      collectors:\n        - type: composer\n          composerPath: composer.json\n          composerLockPath: composer.lock\n          packages:\n            - captainhook/captainhook\n            - deptrac/deptrac\n            - ergebnis/composer-normalize\n            - infocyph/phpprobe\n            - laravel/pint\n            - pestphp/pest\n            - pestphp/pest-plugin-drift\n            - phpbench/phpbench\n            - phpstan/phpstan\n            - rector/rector\n            - squizlabs/php_codesniffer\n            - symfony/console\n            - symfony/process\n            - symfony/string\n            - symfony/var-dumper\n            - tomasvotruba/cognitive-complexity\n            - vimeo/psalm\n  ruleset:\n    Project:\n      - Project\n      - Vendor\n"
