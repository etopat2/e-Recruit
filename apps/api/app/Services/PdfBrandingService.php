<?php

namespace App\Services;

use RuntimeException;

class PdfBrandingService
{
    /** @return array{logoDataUri: string, nationalEmblemDataUri: ?string, tahomaRegularDataUri: ?string, tahomaBoldDataUri: ?string} */
    public function assets(bool $requireTahoma = false, bool $requireNationalEmblem = false): array
    {
        $logoPath = resource_path('brand/logo.png');
        $nationalEmblemPath = resource_path('brand/uganda-national-emblem.png');
        throw_unless(is_file($logoPath), RuntimeException::class, 'The official Uganda Prisons Service logo is unavailable.');
        if ($requireNationalEmblem) {
            throw_unless(is_file($nationalEmblemPath), RuntimeException::class, 'The Uganda national emblem is unavailable.');
        }
        $regular = $this->fontDataUri((string) config('erecruit.pdf.tahoma_regular_path'));
        $bold = $this->fontDataUri((string) config('erecruit.pdf.tahoma_bold_path'));
        if ($requireTahoma && ($regular === null || $bold === null)) {
            throw new RuntimeException('Licensed Tahoma regular and bold font files must be mounted before an official recruitment document can be generated.');
        }

        return [
            'logoDataUri' => 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)),
            'nationalEmblemDataUri' => $requireNationalEmblem ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($nationalEmblemPath)) : null,
            'tahomaRegularDataUri' => $regular,
            'tahomaBoldDataUri' => $bold,
        ];
    }

    private function fontDataUri(string $path): ?string
    {
        if ($path !== '' && ! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return 'data:font/truetype;base64,'.base64_encode((string) file_get_contents($path));
    }
}
