<?php

namespace App\Services;

use RuntimeException;

class PdfBrandingService
{
    /** @return array{logoDataUri: string, officialHeaderDataUri: string, tahomaRegularDataUri: ?string, tahomaBoldDataUri: ?string} */
    public function assets(bool $requireTahoma = false): array
    {
        $logoPath = resource_path('brand/logo.png');
        $officialHeaderPath = resource_path('brand/official-document-header.jpg');
        throw_unless(is_file($logoPath), RuntimeException::class, 'The official Uganda Prisons Service logo is unavailable.');
        throw_unless(is_file($officialHeaderPath), RuntimeException::class, 'The official document header asset is unavailable.');
        $regular = $this->fontDataUri((string) config('erecruit.pdf.tahoma_regular_path'));
        $bold = $this->fontDataUri((string) config('erecruit.pdf.tahoma_bold_path'));
        if ($requireTahoma && ($regular === null || $bold === null)) {
            throw new RuntimeException('Licensed Tahoma regular and bold font files must be mounted before an official recruitment document can be generated.');
        }

        return [
            'logoDataUri' => 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)),
            'officialHeaderDataUri' => 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($officialHeaderPath)),
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
