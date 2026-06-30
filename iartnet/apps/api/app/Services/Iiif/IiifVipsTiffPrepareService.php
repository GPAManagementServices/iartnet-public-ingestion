<?php

declare(strict_types=1);

namespace App\Services\Iiif;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Invoca lo script bash di preparazione TIFF IIIF via libvips.
 */
final class IiifVipsTiffPrepareService
{
    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'tif', 'tiff'];

    /**
     * Verifica che vips, vipsheader e lo script siano disponibili.
     *
     * @throws RuntimeException
     */
    public function assertAvailable(): void
    {
        $script = (string) config('images.vips_script');
        if ($script === '' || ! is_file($script)) {
            throw new RuntimeException(
                'Script vips non trovato. Configurare IIIF_VIPS_SCRIPT in .env.'
            );
        }

        $bash = $this->resolveBashPath();
        $bashCheck = new Process([$bash, '--version']);
        $bashCheck->run();
        if (! $bashCheck->isSuccessful()) {
            throw new RuntimeException(
                "bash non eseguibile (BASH_PATH={$bash}). Su Windows usare Git Bash, es. C:/Program Files/Git/bin/bash.exe"
            );
        }

        $vipsBin = rtrim((string) config('images.vips_bin'), DIRECTORY_SEPARATOR);
        $vipsExe = $this->binaryName('vips');
        $headerExe = $this->binaryName('vipsheader');

        if ($vipsBin !== '') {
            if (! is_file($vipsBin.DIRECTORY_SEPARATOR.$vipsExe)) {
                throw new RuntimeException("vips non trovato in VIPS_BIN: {$vipsBin}");
            }
            if (! is_file($vipsBin.DIRECTORY_SEPARATOR.$headerExe)) {
                throw new RuntimeException("vipsheader non trovato in VIPS_BIN: {$vipsBin}");
            }

            return;
        }

        if (! $this->commandExists($vipsExe) || ! $this->commandExists($headerExe)) {
            throw new RuntimeException(
                'vips/vipsheader non disponibili. Installare libvips o impostare VIPS_BIN in .env.'
            );
        }
    }

    public function isExtensionSupported(string $sourcePath): bool
    {
        $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));

        return in_array($ext, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Prepara un TIFF tiled (e opzionalmente piramidale) in outputPath.
     *
     * @throws RuntimeException
     */
    public function prepare(string $sourcePath, string $outputPath): void
    {
        $this->assertAvailable();

        if (! is_file($sourcePath)) {
            throw new RuntimeException("File sorgente non trovato: {$sourcePath}");
        }

        if (! $this->isExtensionSupported($sourcePath)) {
            $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);
            throw new RuntimeException(
                "Formato non supportato per preparazione vips: .{$ext}. "
                .'Formati ammessi: '.implode(', ', self::SUPPORTED_EXTENSIONS)
            );
        }

        $outExt = strtolower((string) pathinfo($outputPath, PATHINFO_EXTENSION));
        if (! in_array($outExt, ['tif', 'tiff'], true)) {
            throw new RuntimeException("L'output vips deve avere estensione .tif: {$outputPath}");
        }

        $script = (string) config('images.vips_script');
        $bash = $this->resolveBashPath();

        $process = new Process(
            [$bash, $script, $this->normalizePath($sourcePath), $this->normalizePath($outputPath)],
            null,
            $this->buildProcessEnv()
        );
        $process->setTimeout((int) config('images.vips_timeout', 600));
        $process->run();

        $outputReady = is_file($outputPath) && filesize($outputPath) > 0;
        $processOutput = trim($process->getErrorOutput()."\n".$process->getOutput());

        if (! $outputReady) {
            throw new RuntimeException(
                'Preparazione vips fallita: output non creato'
                .($processOutput !== '' ? ': '.$processOutput : '')
            );
        }

        if (! $process->isSuccessful()) {
            Log::warning('IiifVipsTiffPrepare: exit code non-zero ma output TIFF creato', [
                'exit_code' => $process->getExitCode(),
                'output_path' => $outputPath,
                'stderr' => trim($process->getErrorOutput()),
            ]);
        }
    }
    /**
     * @return array{width: int|null, height: int|null}
     */
    public function readDimensions(string $filePath): array
    {
        $header = $this->binaryPath('vipsheader');
        $normalized = $this->normalizePath($filePath);
        $env = $this->buildProcessEnv();

        $widthProcess = new Process([$header, '-f', 'width', $normalized], null, $env);
        $widthProcess->run();

        $heightProcess = new Process([$header, '-f', 'height', $normalized], null, $env);
        $heightProcess->run();

        if (! $widthProcess->isSuccessful() || ! $heightProcess->isSuccessful()) {
            return (new IiifImageService)->getImageDimensions($filePath);
        }

        $width = trim($widthProcess->getOutput());
        $height = trim($heightProcess->getOutput());

        return [
            'width' => ctype_digit($width) ? (int) $width : null,
            'height' => ctype_digit($height) ? (int) $height : null,
        ];
    }

    /** @return array<string, string> */
    private function buildProcessEnv(): array
    {
        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        $env = array_merge($env, [
            'PYRAMID_MODE' => (string) config('images.pyramid_mode', 'auto'),
            'TILE_SIZE' => (string) config('images.tile_size', 512),
            'JPEG_Q' => (string) config('images.jpeg_q', 90),
            'PYRAMID_MIN_SIDE' => (string) config('images.pyramid_min_side', 4096),
            'PYRAMID_MIN_LEVELS' => (string) config('images.pyramid_min_levels', 4),
            'PNG_COMPRESSION' => (string) config('images.png_compression', 'deflate'),
            'ALPHA_POLICY' => (string) config('images.alpha_policy', 'deflate'),
            'CONVERT_CMYK_TO_SRGB' => (string) config('images.convert_cmyk_to_srgb', '1'),
        ]);

        $vipsBin = rtrim((string) config('images.vips_bin'), DIRECTORY_SEPARATOR);
        if ($vipsBin !== '') {
            $pathKey = PHP_OS_FAMILY === 'Windows' ? 'Path' : 'PATH';
            $existing = $env[$pathKey] ?? getenv($pathKey) ?: '';
            $env[$pathKey] = $vipsBin.PATH_SEPARATOR.$existing;
        }

        return $env;
    }

    private function binaryName(string $name): string
    {
        return PHP_OS_FAMILY === 'Windows' ? $name.'.exe' : $name;
    }

    private function binaryPath(string $name): string
    {
        $vipsBin = rtrim((string) config('images.vips_bin'), DIRECTORY_SEPARATOR);
        $binary = $this->binaryName($name);

        if ($vipsBin !== '') {
            return $vipsBin.DIRECTORY_SEPARATOR.$binary;
        }

        return $binary;
    }

    private function commandExists(string $command): bool
    {
        $check = new Process(PHP_OS_FAMILY === 'Windows'
            ? ['where', $command]
            : ['command', '-v', $command]);
        $check->run();

        return $check->isSuccessful();
    }

    /**
     * Su Windows il comando "bash" nel PATH punta spesso al launcher WSL (system32/bash.exe),
     * non a Git Bash. Se BASH_PATH non è impostato, prova i percorsi standard di Git Bash.
     */
    private function resolveBashPath(): string
    {
        $configured = trim((string) config('images.bash_path', 'bash'));

        if ($configured !== '' && $configured !== 'bash' && is_file($configured)) {
            return $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach ($this->windowsGitBashCandidates() as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            throw new RuntimeException(
                'bash non trovato su Windows. Installare Git for Windows oppure impostare '
                .'BASH_PATH in .env (es. C:/Program Files/Git/bin/bash.exe). '
                .'Evitare il launcher WSL in C:\\Windows\\system32\\bash.exe.'
            );
        }

        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        return $configured !== '' ? $configured : 'bash';
    }

    /** @return list<string> */
    private function windowsGitBashCandidates(): array
    {
        $programFiles = getenv('ProgramFiles') ?: 'C:\\Program Files';
        $programFilesX86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';

        return [
            $programFiles.'\\Git\\bin\\bash.exe',
            $programFilesX86.'\\Git\\bin\\bash.exe',
        ];
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }
}
