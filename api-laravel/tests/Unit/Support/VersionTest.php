<?php

declare(strict_types=1);

use App\Support\Utils\Version;

/**
 * Port of spec/lib/lago_utils/version_spec.rb (Rails).
 */
it('returns the default and the base github url when the version file is missing', function (): void {
    // api-laravel ships without a LAGO_VERSION file (Rails passes Rails.env).
    expect(file_exists(VersionFilePath()))->toBeFalse();

    $version = Version::call('testing-default');

    expect($version->number)->toBe('testing-default')
        ->and($version->sha)->toBeNull()
        ->and($version->githubUrl)->toBe('https://github.com/getlago/lago-api')
        ->and($version->shaOrNumber())->toBe('testing-default');
});

it('returns the tag as the number when the file holds a version tag', function (): void {
    writeVersionFile("v1.20.0\n");

    try {
        $version = Version::call('testing-default');

        expect($version->number)->toBe('v1.20.0')
            ->and($version->sha)->toBeNull()
            ->and($version->githubUrl)->toBe('https://github.com/getlago/lago-api/tree/v1.20.0')
            ->and($version->shaOrNumber())->toBe('v1.20.0');
    } finally {
        removeVersionFile();
    }
});

it('returns the release date as the number when the file holds a git sha', function (): void {
    writeVersionFile("0f425aee1b9e7c927eb9559055fd1d11708bc7b5\n");

    try {
        $version = Version::call('testing-default');

        expect($version->number)->toBe(date('Y-m-d', filectime(VersionFilePath())))
            ->and($version->sha)->toBe('0f425aee1b9e7c927eb9559055fd1d11708bc7b5')
            ->and($version->githubUrl)->toBe('https://github.com/getlago/lago-api/tree/0f425aee1b9e7c927eb9559055fd1d11708bc7b5')
            ->and($version->shaOrNumber())->toBe('0f425aee1b9e7c927eb9559055fd1d11708bc7b5');
    } finally {
        removeVersionFile();
    }
});

function VersionFilePath(): string
{
    return base_path('LAGO_VERSION');
}

function writeVersionFile(string $content): void
{
    removeVersionFile();
    file_put_contents(VersionFilePath(), $content);
}

function removeVersionFile(): void
{
    if (file_exists(VersionFilePath())) {
        unlink(VersionFilePath());
    }
}
