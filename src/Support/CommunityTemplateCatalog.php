<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class CommunityTemplateCatalog
{
    /**
     * @return array<string, string>
     */
    public static function files(): array
    {
        return [
            'CONTRIBUTING.md' => 'resources/community/CONTRIBUTING.md',
            'CODE_OF_CONDUCT.md' => 'resources/community/CODE_OF_CONDUCT.md',
            'SECURITY.md' => 'resources/community/SECURITY.md',
            '.github/ISSUE_TEMPLATE/bug_report.yml' => 'resources/community/.github/ISSUE_TEMPLATE/bug_report.yml',
            '.github/ISSUE_TEMPLATE/regression_report.yml' => 'resources/community/.github/ISSUE_TEMPLATE/regression_report.yml',
            '.github/ISSUE_TEMPLATE/ci_failure.yml' => 'resources/community/.github/ISSUE_TEMPLATE/ci_failure.yml',
            '.github/ISSUE_TEMPLATE/feature_request.yml' => 'resources/community/.github/ISSUE_TEMPLATE/feature_request.yml',
            '.github/ISSUE_TEMPLATE/question.yml' => 'resources/community/.github/ISSUE_TEMPLATE/question.yml',
            '.github/ISSUE_TEMPLATE/docs_improvement.yml' => 'resources/community/.github/ISSUE_TEMPLATE/docs_improvement.yml',
            '.github/ISSUE_TEMPLATE/config.yml' => 'resources/community/.github/ISSUE_TEMPLATE/config.yml',
            '.github/PULL_REQUEST_TEMPLATE.md' => 'resources/community/.github/PULL_REQUEST_TEMPLATE.md',
        ];
    }

    /**
     * @return list<array{source:string,target:string,target_relative:string}>
     */
    public static function publishPairs(): array
    {
        $pairs = [];

        foreach (self::files() as $targetRelative => $sourceRelative) {
            $pairs[] = [
                'source' => Paths::packageFile($sourceRelative),
                'target' => Paths::projectRootPath() . DIRECTORY_SEPARATOR . self::platformPath($targetRelative),
                'target_relative' => $targetRelative,
            ];
        }

        return $pairs;
    }

    private static function platformPath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
