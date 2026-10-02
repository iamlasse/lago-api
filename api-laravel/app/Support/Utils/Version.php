<?php

namespace App\Support\Utils;

/**
 * Port of Rails' LagoUtils::Version (lib/lago_utils/lago_utils/version.rb):
 * reads the LAGO_VERSION file at the app root. When the file holds a git SHA
 * (40 chars), `number` becomes the file's release date and `sha` the commit;
 * when it holds a tag, `number` is the tag. Missing file → `default`
 * (Rails passes Rails.env).
 */
final class Version
{
    public const GITHUB_BASE_URL = 'https://github.com/getlago/lago-api';

    public function __construct(
        public readonly string $number,
        public readonly string $githubUrl,
        public readonly ?string $sha,
    ) {}

    public function shaOrNumber(): string
    {
        return $this->sha ?? $this->number;
    }

    public static function call(string $default): self
    {
        $content = self::fileContent();

        if ($content === null) {
            return new self($default, self::GITHUB_BASE_URL, null);
        }

        if (self::isGitHash($content)) {
            return new self(
                self::releaseDate(),
                self::GITHUB_BASE_URL.'/tree/'.$content,
                $content,
            );
        }

        return new self($content, self::GITHUB_BASE_URL.'/tree/'.$content, null);
    }

    private static function versionFilePath(): string
    {
        return base_path('LAGO_VERSION');
    }

    private static function fileContent(): ?string
    {
        $content = @file_get_contents(self::versionFilePath());

        if ($content === false) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $content));
    }

    private static function releaseDate(): string
    {
        $ctime = filectime(self::versionFilePath());

        return date('Y-m-d', $ctime === false ? time() : $ctime);
    }

    private static function isGitHash(string $content): bool
    {
        return strlen($content) === 40;
    }
}
