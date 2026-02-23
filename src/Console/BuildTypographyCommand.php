<?php

namespace Artworksit\Starter\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class BuildTypographyCommand extends Command
{
    protected $signature = 'starter:typography:build {--csv=resources/typography.csv : Path to typography CSV} {--scale=0.9 : Scale factor for 3xl}';

    protected $description = 'Generate typography tokens from a CSV file.';

    private Filesystem $filesystem;

    public function handle(Filesystem $filesystem): int
    {
        $this->filesystem = $filesystem;

        $csvInput = (string)$this->option('csv');
        $csvPath = $this->resolvePath($csvInput);

        if (!$filesystem->exists($csvPath)) {
            $this->createDefaultCsv($csvPath);
            $this->info("Typography CSV created at {$csvInput}.");
            $this->line('Edit the CSV and re-run `php artisan starter:typography:build`.');

            return self::SUCCESS;
        }

        if (!is_readable($csvPath)) {
            $this->error("CSV file is not readable: {$csvInput}.");

            return self::FAILURE;
        }

        $scale = $this->resolveScale();

        if ($scale === null) {
            return self::FAILURE;
        }

        $contents = $filesystem->get($csvPath);
        $rows = $this->parseCsv($contents);

        if ($rows === []) {
            $this->error('CSV file has no rows.');

            return self::FAILURE;
        }

        $header = array_shift($rows);

        if ($header === null || count($header) < 2) {
            $this->error('CSV header must include at least two columns.');

            return self::FAILURE;
        }

        $breakpoints = $this->parseBreakpoints($header);

        if ($breakpoints === null) {
            return self::FAILURE;
        }

        $parsedRows = $this->parseFontRows($rows, count($breakpoints));

        if ($parsedRows === null) {
            return self::FAILURE;
        }

        if ($parsedRows === []) {
            $this->error('CSV file needs at least one typography row.');

            return self::FAILURE;
        }

        $css = $this->buildCss($breakpoints, $parsedRows, $scale);
        $cssPath = $this->resolveCssPath();
        $this->writeCssTokens($cssPath, $css);

        $relativePath = Str::after($cssPath, base_path() . '/');
        $this->info("Typography tokens written to {$relativePath}.");

        return self::SUCCESS;
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

        return (bool)preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function resolveScale(): ?float
    {
        $scale = (float)$this->option('scale');

        if (!$this->input->hasParameterOption('--scale')) {
            $scale = (float)$this->ask('Scale factor for 3xl', (string)$scale);
        }

        if ($scale <= 0) {
            $this->error('Scale factor must be a positive number.');

            return null;
        }

        return $scale;
    }

    private function createDefaultCsv(string $path): void
    {
        $directory = dirname($path);

        if (!$this->filesystem->exists($directory)) {
            $this->filesystem->makeDirectory($directory, 0755, true);
        }

        $this->filesystem->put($path, $this->defaultCsvContents());
    }

    private function defaultCsvContents(): string
    {
        return implode("\n", [
            '1920,1024,640',
            '',
        ]);
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
     * @param array<int, string> $header
     * @return array<int, float>|null
     */
    private function parseBreakpoints(array $header): ?array
    {
        $breakpoints = [];

        foreach ($header as $value) {
            if (!$this->isNumericValue($value)) {
                $this->error('CSV header must include only numeric breakpoint values.');

                return null;
            }

            $breakpoint = (float)$value;

            if ($breakpoint <= 0) {
                $this->error('Breakpoint values must be positive numbers.');

                return null;
            }

            $breakpoints[] = $breakpoint;
        }

        return $breakpoints;
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return array<int, array<int, float>>|null
     */
    private function parseFontRows(array $rows, int $columnCount): ?array
    {
        $parsed = [];

        foreach ($rows as $index => $row) {
            if (count($row) !== $columnCount) {
                $this->error('CSV row column count mismatch on row ' . ($index + 2) . '.');

                return null;
            }

            $values = [];

            foreach ($row as $value) {
                if (!$this->isNumericValue($value)) {
                    $this->error('CSV contains non-numeric font values.');

                    return null;
                }

                $numericValue = (float)$value;

                if ($numericValue <= 0) {
                    $this->error('Font values must be positive numbers.');

                    return null;
                }

                $values[] = $numericValue;
            }

            $parsed[] = $values;
        }

        return $parsed;
    }

    private function isNumericValue(string $value): bool
    {
        return $value !== '' && is_numeric($value);
    }

    /**
     * @param array<int, float> $breakpoints
     * @param array<int, array<int, float>> $rows
     */
    private function buildCss(array $breakpoints, array $rows, float $scale): string
    {
        $tokens = $this->buildTokens($rows);
        $sections = [];
        $comment = $this->buildSourceSizesComment($breakpoints, $tokens, $scale);

        if ($comment !== '') {
            $sections[] = $comment;
        }

        $sections[] = $this->renderBlock('@theme', $this->buildLines($tokens, $breakpoints[0]));

        foreach ($this->sortedBreakpointColumns($breakpoints) as $column) {

            $correctMaxWidth = match ($column['index']) {
                1 => 1024,
                2 => 640
            };

            $sections[] = $this->renderMediaBlock(
                'max-width',
                $correctMaxWidth,
                $this->buildLines($tokens, $correctMaxWidth, $column['index']),
            );
        }

        $scaledLines = $this->buildScaledLines($tokens, $scale);
        $sections[] = $this->renderMediaBlock('min-width', 1920.0, $scaledLines);

        return implode("\n\n", $sections);
    }

    /**
     * @param array<int, float> $breakpoints
     * @param array<int, array{name: string, values: array<int, float>}> $tokens
     */
    private function buildSourceSizesComment(array $breakpoints, array $tokens, float $scale): string
    {
        if ($breakpoints === [] || $tokens === []) {
            return '';
        }

        $baseBreakpoint = $breakpoints[0];
        $baseLabel = $this->formatBreakpoint($baseBreakpoint);
        $baseValue = $this->formatCsvValue($baseBreakpoint);
        $scaleValue = $this->formatCsvValue($scale);
        $nameWidth = max(array_map(fn(array $token): int => strlen($token['name']), $tokens));
        $nameWidth = max($nameWidth, strlen('Token name'));
        $breakpointLabels = array_map(fn(float $bp): string => $this->formatBreakpoint($bp), $breakpoints);
        $breakpointLabels[] = '>1920px';
        $columnWidths = array_map(fn(string $label): int => max(strlen($label), strlen('Base px')), $breakpointLabels);

        $lines = [];
        $lines[] = "/* Source sizes from CSV file.";
        $lines[] = '';
        $header = sprintf('   %-' . $nameWidth . 's', 'Token name');
        $divider = sprintf('   %-' . $nameWidth . 's', str_repeat('-', strlen('Token name')));

        foreach ($breakpointLabels as $index => $label) {
            $width = $columnWidths[$index];
            $header .= '  ' . str_pad($label, $width, ' ', STR_PAD_LEFT);
            $divider .= '  ' . str_pad(str_repeat('-', strlen($label)), $width, ' ', STR_PAD_LEFT);
        }

        $lines[] = $header;
        $lines[] = $divider;

        foreach ($tokens as $token) {
            $row = sprintf('   %-' . $nameWidth . 's', $token['name']);

            foreach ($token['values'] as $index => $value) {
                $width = $columnWidths[$index] ?? strlen($this->formatCsvValue($value));
                $row .= '  ' . str_pad($this->formatCsvValue($value), $width, ' ', STR_PAD_LEFT);
            }

            $scaledValue = $this->formatCsvValue($token['values'][0] * $scale);
            $scaledWidth = $columnWidths[count($breakpointLabels) - 1] ?? strlen($scaledValue);
            $row .= '  ' . str_pad($scaledValue, $scaledWidth, ' ', STR_PAD_LEFT);

            $lines[] = $row;
        }

        $lines[] = '*/';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, values: array<int, float>}> $tokens
     * @return array<int, string>
     */
    private function buildLines(array $tokens, float $breakpoint, int $column = 0): array
    {
        $lines = [];

        foreach ($tokens as $token) {
            $value = $this->formatVw($token['values'][$column], $breakpoint);
            $lines[] = "    --{$token['name']}: {$value};";
        }

        return $lines;
    }

    /**
     * @param array<int, array{name: string, values: array<int, float>}> $tokens
     * @return array<int, string>
     */
    private function buildScaledLines(array $tokens, float $scale): array
    {
        $lines = [];

        foreach ($tokens as $token) {
            $scaledValue = $token['values'][0] * $scale;
            $value = $this->formatVw($scaledValue, 1920.0);
            $lines[] = "    --{$token['name']}: {$value};";
        }

        return $lines;
    }

    private function renderBlock(string $selector, array $lines): string
    {
        return $selector . " {\n" . implode("\n", $lines) . "\n}";
    }

    private function renderMediaBlock(string $type, float $breakpoint, array $lines): string
    {
        $formatted = $this->formatBreakpoint($breakpoint);
        $body = ":root {\n" . implode("\n", $lines) . "\n}";

        return "@media ({$type}: {$formatted}) {\n    " . str_replace("\n", "\n    ", $body) . "\n}";
    }

    private function formatBreakpoint(float $breakpoint): string
    {
        if (fmod($breakpoint, 1.0) === 0.0) {
            return (string)((int)$breakpoint) . 'px';
        }

        return rtrim(rtrim((string)$breakpoint, '0'), '.') . 'px';
    }

    private function formatVw(float $fontPx, float $breakpoint): string
    {
        $vw = ($fontPx / $breakpoint) * 100;

        return number_format($vw, 3, '.', '') . 'vw';
    }

    /**
     * @param array<int, array<int, float>> $rows
     * @return array<int, array{name: string, values: array<int, float>}>
     */
    private function buildTokens(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $key = $this->numberKey($row[0]);

            if (!array_key_exists($key, $grouped)) {
                $grouped[$key] = [
                    'default' => $row[0],
                    'rows' => [],
                ];
            }

            $grouped[$key]['rows'][] = $row;
        }

        uasort($grouped, function (array $left, array $right): int {
            return $left['default'] <=> $right['default'];
        });

        $tokens = [];
        $groupIndex = 0;

        foreach ($grouped as $group) {
            $scale = $this->scaleName($groupIndex);

            foreach ($group['rows'] as $variantIndex => $row) {
                $tokens[] = [
                    'name' => $scale . $this->variantSuffix($variantIndex),
                    'values' => $row,
                ];
            }

            $groupIndex++;
        }

        return $tokens;
    }

    private function numberKey(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function formatCsvValue(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function scaleName(int $index): string
    {
        $base = [
            'text-sm',
            'text-base',
            'text-lg',
            'text-xl',
            'text-2xl',
            'text-3xl',
            'text-4xl',
            'text-5xl',
            'text-6xl',
            'text-7xl',
            'text-8xl',
            'text-9xl',
            'text-10xl',
        ];

        if (array_key_exists($index, $base)) {
            return $base[$index];
        }

        $extra = $index - count($base) + 11;

        return 'text-' . $extra . 'xl';
    }

    private function variantSuffix(int $index): string
    {
        if ($index === 0) {
            return '';
        }

        if ($index === 1) {
            return '-variant';
        }

        return '-variant-' . $index;
    }

    /**
     * @param array<int, float> $breakpoints
     * @return array<int, array{index: int, breakpoint: float}>
     */
    private function sortedBreakpointColumns(array $breakpoints): array
    {
        $columns = [];

        foreach ($breakpoints as $index => $breakpoint) {
            if ($index === 0) {
                continue;
            }

            $columns[] = [
                'index' => $index,
                'breakpoint' => $breakpoint,
            ];
        }

        usort($columns, fn(array $left, array $right): int => $right['breakpoint'] <=> $left['breakpoint']);

        return $columns;
    }

    private function resolveCssPath(): string
    {
        $tokensPath = resource_path('css/tokens.typography.css');

        if ($this->filesystem->exists($tokensPath)) {
            return $tokensPath;
        }

        return resource_path('css/app.css');
    }

    private function writeCssTokens(string $path, string $css): void
    {
        $markerStart = '/* === AUTO-GENERATED TYPOGRAPHY TOKENS (do not edit) === */';
        $markerEnd = '/* === END AUTO-GENERATED TYPOGRAPHY TOKENS === */';
        $block = $markerStart . "\n" . $css . "\n" . $markerEnd;

        if (!$this->filesystem->exists($path)) {
            $this->filesystem->put($path, $block . "\n");

            return;
        }

        $contents = $this->filesystem->get($path);

        if (str_contains($contents, $markerStart) && str_contains($contents, $markerEnd)) {
            $pattern = '/' . preg_quote($markerStart, '/') . '.*?' . preg_quote($markerEnd, '/') . '/s';
            $updated = preg_replace($pattern, $block, $contents);

            if ($updated === null) {
                $this->filesystem->put($path, $block . "\n");

                return;
            }

            $this->filesystem->put($path, $updated);

            return;
        }

        $this->filesystem->put($path, rtrim($contents) . "\n\n" . $block . "\n");
    }
}
