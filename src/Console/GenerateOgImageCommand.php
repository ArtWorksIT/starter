<?php

namespace Artworksit\Starter\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

class GenerateOgImageCommand extends Command
{
    protected $signature = 'starter:og-generate
        {--csv= : Path to OG CSV}
        {--force : Overwrite existing OG images}
        {--strict : Fail on invalid rows or missing fonts}
        {--input-default= : Default input image path}
        {--output-default= : Default output folder}
        {--heading-font= : Heading font file path}
        {--heading-size= : Heading font size}
        {--heading-color= : Heading font color}
        {--heading-x= : Heading X position}
        {--heading-y= : Heading Y position}
        {--heading-max-width= : Heading max width}
        {--heading-line-height= : Heading line height}
        {--subtext-font= : Subtext font file path}
        {--subtext-size= : Subtext font size}
        {--subtext-color= : Subtext font color}
        {--subtext-x= : Subtext X position}
        {--subtext-y= : Subtext Y position}
        {--subtext-max-width= : Subtext max width}
        {--subtext-line-height= : Subtext line height}';

    protected $description = 'Generate OG images in batch from a CSV file.';

    private Filesystem $filesystem;

    public function handle(Filesystem $filesystem): int
    {
        $this->filesystem = $filesystem;

        $csvInput = (string) ($this->option('csv') ?: Arr::get(config('starter.og', []), 'csv_path', 'resources/og.csv'));
        $csvPath = $this->resolvePath($csvInput);

        if (! $filesystem->exists($csvPath)) {
            $this->createDefaultCsv($csvPath);
            $this->info("OG CSV created at {$csvInput}.");
            $this->line('Edit the CSV and re-run `php artisan starter:og-generate`.');

            return self::SUCCESS;
        }

        if (! is_readable($csvPath)) {
            $this->error("CSV file is not readable: {$csvInput}.");

            return self::FAILURE;
        }

        $rows = $this->parseCsv($filesystem->get($csvPath));

        if ($rows === []) {
            $this->error('CSV file has no rows.');

            return self::FAILURE;
        }

        $header = array_shift($rows);

        if (! $this->validHeader($header)) {
            $this->error('CSV header must include slug,header,subtext,input,output.');

            return self::FAILURE;
        }

        $defaults = $this->resolveDefaults();
        $strict = (bool) $this->option('strict');
        $force = (bool) $this->option('force');

        if (! $this->ensureDefaultInputExists($defaults['input_default'])) {
            $message = 'Default input image missing and could not be created.';

            if ($strict) {
                $this->error($message);

                return self::FAILURE;
            }

            $this->warn($message);
        }

        $manager = new ImageManager(new Driver());
        $success = true;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $entry = $this->mapRow($header, $row);

            if (! $this->validRow($entry)) {
                $message = "Skipping invalid row {$rowNumber}.";

                if ($strict) {
                    $this->error($message);

                    return self::FAILURE;
                }

                $this->warn($message);

                continue;
            }

            $slug = (string) $entry['slug'];
            $heading = (string) $entry['header'];
            $subtext = (string) $entry['subtext'];
            $input = trim((string) $entry['input']) !== ''
                ? (string) $entry['input']
                : $defaults['input_default'];
            $outputFolder = trim((string) $entry['output']) !== ''
                ? (string) $entry['output']
                : $defaults['output_default'];

            $inputPath = $this->resolvePath($input);
            $outputDir = $this->resolvePath($outputFolder);
            $outputPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slug . '.png';

            if (! $filesystem->isDirectory($outputDir)) {
                $filesystem->makeDirectory($outputDir, 0755, true);
            }

            if ($filesystem->exists($outputPath) && ! $force) {
                $this->line("Skipping {$slug} (exists). Use --force to overwrite.");

                continue;
            }

            if (! $filesystem->exists($inputPath)) {
                $message = "Input image not found for {$slug}: {$input}.";

                if ($strict) {
                    $this->error($message);

                    return self::FAILURE;
                }

                $this->warn($message);

                continue;
            }

            try {
                $image = $manager->read($inputPath);
                $this->renderText($image, $heading, $subtext, $defaults, $strict);
                $image->save($outputPath);
            } catch (\Throwable $exception) {
                $message = "Failed to generate OG image for {$slug}.";

                if ($strict) {
                    $this->error($message);
                    $this->line($exception->getMessage());

                    return self::FAILURE;
                }

                $this->warn($message);
                $success = false;
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $contents): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $contents) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = array_map('trim', str_getcsv($line));
        }

        return $rows;
    }

    /**
     * @param array<int, string>|null $header
     */
    private function validHeader(?array $header): bool
    {
        if ($header === null) {
            return false;
        }

        $expected = ['slug', 'header', 'subtext', 'input', 'output'];
        $normalized = array_map(fn(string $value): string => strtolower(trim($value)), $header);

        if ($normalized !== []) {
            $normalized[0] = ltrim($normalized[0], "\xEF\xBB\xBF");
        }

        return $normalized === $expected;
    }

    /**
     * @param array<int, string> $header
     * @param array<int, string> $row
     * @return array<string, string>
     */
    private function mapRow(array $header, array $row): array
    {
        $entry = [];

        foreach ($header as $index => $key) {
            $entry[strtolower(trim($key))] = $row[$index] ?? '';
        }

        return $entry;
    }

    /**
     * @param array<string, string> $entry
     */
    private function validRow(array $entry): bool
    {
        return trim((string) Arr::get($entry, 'slug', '')) !== ''
            && trim((string) Arr::get($entry, 'header', '')) !== ''
            && trim((string) Arr::get($entry, 'output', '')) !== '';
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (Str::startsWith($path, ['/', '\\'])) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function createDefaultCsv(string $path): void
    {
        $directory = dirname($path);

        if (! $this->filesystem->exists($directory)) {
            $this->filesystem->makeDirectory($directory, 0755, true);
        }

        $rows = $this->defaultCsvRows();
        $contents = array_map(fn(array $row): string => implode(',', $row), $rows);
        $this->filesystem->put($path, implode("\n", $contents) . "\n");
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function defaultCsvRows(): array
    {
        $header = ['slug', 'header', 'subtext', 'input', 'output'];
        $pages = $this->manifestPages();
        $defaultInput = $this->resolveDefaults()['input_default'];
        $defaultOutput = $this->resolveDefaults()['output_default'];

        if ($pages === []) {
            return [
                $header,
                ['home', 'Home', '', $defaultInput, $defaultOutput],
            ];
        }

        $rows = [$header];

        foreach ($pages as $page) {
            $slug = $this->pageSlug($page);
            $rows[] = [
                $slug,
                Str::headline(str_replace('-', ' ', $slug)),
                '',
                $defaultInput,
                $defaultOutput,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function manifestPages(): array
    {
        $manifestPath = config('starter.manifest_path');

        if (! is_string($manifestPath) || $manifestPath === '') {
            return [];
        }

        if (! $this->filesystem->exists($manifestPath)) {
            return [];
        }

        $contents = $this->filesystem->get($manifestPath);
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        $pages = Arr::get($decoded, 'pages', []);

        return is_array($pages)
            ? array_values(array_filter($pages, fn($page): bool => is_string($page)))
            : [];
    }

    private function pageSlug(string $page): string
    {
        return Str::of($page)
            ->replace('>', '-')
            ->kebab()
            ->lower()
            ->value();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDefaults(): array
    {
        $config = (array) config('starter.og', []);

        return [
            'input_default' => (string) ($this->option('input-default') ?: Arr::get($config, 'input_default', 'public/assets/og/default.png')),
            'output_default' => (string) ($this->option('output-default') ?: Arr::get($config, 'output_default', 'public/og')),
            'heading_font' => (string) ($this->option('heading-font') ?: Arr::get($config, 'heading_font')),
            'heading_size' => (int) ($this->option('heading-size') ?: Arr::get($config, 'heading_size', 72)),
            'heading_color' => (string) ($this->option('heading-color') ?: Arr::get($config, 'heading_color', '#111827')),
            'heading_x' => (int) ($this->option('heading-x') ?: Arr::get($config, 'heading_x', 120)),
            'heading_y' => (int) ($this->option('heading-y') ?: Arr::get($config, 'heading_y', 160)),
            'heading_max_width' => $this->option('heading-max-width') !== null
                ? (int) $this->option('heading-max-width')
                : Arr::get($config, 'heading_max_width'),
            'heading_line_height' => (float) ($this->option('heading-line-height') ?: Arr::get($config, 'heading_line_height', 1.1)),
            'subtext_font' => (string) ($this->option('subtext-font') ?: Arr::get($config, 'subtext_font')),
            'subtext_size' => (int) ($this->option('subtext-size') ?: Arr::get($config, 'subtext_size', 32)),
            'subtext_color' => (string) ($this->option('subtext-color') ?: Arr::get($config, 'subtext_color', '#6B7280')),
            'subtext_x' => (int) ($this->option('subtext-x') ?: Arr::get($config, 'subtext_x', 120)),
            'subtext_y' => (int) ($this->option('subtext-y') ?: Arr::get($config, 'subtext_y', 320)),
            'subtext_max_width' => $this->option('subtext-max-width') !== null
                ? (int) $this->option('subtext-max-width')
                : Arr::get($config, 'subtext_max_width'),
            'subtext_line_height' => (float) ($this->option('subtext-line-height') ?: Arr::get($config, 'subtext_line_height', 1.3)),
        ];
    }

    private function ensureDefaultInputExists(string $input): bool
    {
        $inputPath = $this->resolvePath($input);

        if ($this->filesystem->exists($inputPath)) {
            return true;
        }

        $directory = dirname($inputPath);

        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0755, true);
        }

        $packageDefault = $this->packageDefaultImagePath();

        if (! $this->filesystem->exists($packageDefault)) {
            return false;
        }

        $this->filesystem->copy($packageDefault, $inputPath);

        return $this->filesystem->exists($inputPath);
    }

    private function packageDefaultImagePath(): string
    {
        return dirname(__DIR__) . '/og/default.png';
    }

    /**
     * @param \Intervention\Image\Image $image
     * @param array<string, mixed> $defaults
     */
    private function renderText($image, string $heading, string $subtext, array $defaults, bool $strict): void
    {
        $headingText = $this->wrapText(
            $heading,
            $defaults['heading_font'],
            $defaults['heading_size'],
            $defaults['heading_max_width'],
        );

        $image->text($headingText, $defaults['heading_x'], $defaults['heading_y'], function (FontFactory $font) use ($defaults, $strict): void {
            $this->configureFont($font, $defaults['heading_font'], $defaults['heading_size'], $defaults['heading_color'], $defaults['heading_line_height'], $strict);
        });

        if (trim($subtext) !== '') {
            $subtextText = $this->wrapText(
                $subtext,
                $defaults['subtext_font'],
                $defaults['subtext_size'],
                $defaults['subtext_max_width'],
            );

            $image->text($subtextText, $defaults['subtext_x'], $defaults['subtext_y'], function (FontFactory $font) use ($defaults, $strict): void {
                $this->configureFont($font, $defaults['subtext_font'], $defaults['subtext_size'], $defaults['subtext_color'], $defaults['subtext_line_height'], $strict);
            });
        }
    }

    private function configureFont(
        FontFactory $font,
        string $fontFile,
        int $size,
        string $color,
        float $lineHeight,
        bool $strict,
    ): void {
        $fontPath = $fontFile !== '' ? $this->resolvePath($fontFile) : '';

        if ($fontPath !== '' && $this->filesystem->exists($fontPath)) {
            $font->file($fontPath);
        } else {
            if ($strict && $fontFile !== '') {
                throw new \RuntimeException("Font file not found: {$fontFile}");
            }
        }

        $font->size($size);
        $font->color($color);
        $font->align('left');
        $font->valign('top');
        $font->lineHeight($lineHeight);
    }

    private function wrapText(string $text, string $fontFile, int $size, ?int $maxWidth): string
    {
        if ($maxWidth === null || $maxWidth <= 0) {
            return $text;
        }

        $fontPath = $fontFile !== '' ? $this->resolvePath($fontFile) : '';

        if ($fontPath === '' || ! $this->filesystem->exists($fontPath) || ! function_exists('imagettfbbox')) {
            return $this->wrapByCharacters($text, $maxWidth, $size);
        }

        return $this->wrapByWidth($text, $fontPath, $size, $maxWidth);
    }

    private function wrapByCharacters(string $text, int $maxWidth, int $size): string
    {
        $approxChars = max(1, (int) floor($maxWidth / max(1, (int) ($size * 0.55))));

        return trim(wordwrap($text, $approxChars, "\n", true));
    }

    private function wrapByWidth(string $text, string $fontPath, int $size, int $maxWidth): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            $box = imagettfbbox($size, 0, $fontPath, $test);
            $width = is_array($box) ? abs($box[2] - $box[0]) : 0;

            if ($width <= $maxWidth || $current === '') {
                $current = $test;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return implode("\n", $lines);
    }
}
