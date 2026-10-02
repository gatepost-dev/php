<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Fixtures;

// GenerateDataTest starts the generator with this file as auto_prepend_file. It replaces the
// https wrapper, so the download of UnicodeData.txt reads the file served-download next to this
// one and never uses the network. The anonymous class keeps this file out of the PSR-4 map.

$standIn = new class {
    /** @var resource|null */
    public $context;

    private string $payload = '';

    private int $offset = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $payload = \file_get_contents(__DIR__ . '/served-download');
        if ($payload === false) {
            return false;
        }
        $this->payload = $payload;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = \substr($this->payload, $this->offset, $count);
        $this->offset += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= \strlen($this->payload);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => \strlen($this->payload)];
    }
};

\stream_wrapper_unregister('https');
\stream_wrapper_register('https', $standIn::class);
