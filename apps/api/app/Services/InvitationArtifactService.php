<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Storage;

class InvitationArtifactService
{
    public function __construct(private readonly PdfBrandingService $branding) {}

    /** @param array<string, mixed> $data
     * @return array{path: string, sha256: string}
     */
    public function create(string $view, array $data, string $path, string $verificationUrl): array
    {
        $qrCode = new QrCode(
            data: $verificationUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 220,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );
        $qrSvg = (new SvgWriter)->write($qrCode)->getString();
        $branding = $this->branding->assets(requireTahoma: true);
        $bytes = Pdf::loadView($view, [
            ...$data,
            ...$branding,
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qrSvg),
        ])->setPaper('a4')->output();
        Storage::disk(config('erecruit.uploads.disk'))->put($path, $bytes);

        return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
    }
}
