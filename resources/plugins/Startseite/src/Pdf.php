<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

/**
 * Minimal PDF writer for simple A4 lists: text in Helvetica (WinAnsi, so umlauts work), lines and boxes
 *
 * Coordinates are in points from the top left corner of the page
 */
class Pdf
{
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    /** @var string[] Content stream per page */
    protected array $pages = [];

    protected int $current = -1;

    // Glyph widths (1/1000 em) of the standard fonts for the characters 32 - 126
    protected const WIDTHS = [
        [
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
        ],
        [
            278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
        ],
    ];

    // Widths of other (WinAnsi) characters used in German texts: [regular, bold], others count as 556
    protected const EXTRA_WIDTHS = [
        0x95 => [350, 350], 0x96 => [556, 556], 0xB7 => [278, 278],
        0xC4 => [667, 722], 0xD6 => [778, 778], 0xDC => [722, 722], 0xDF => [611, 611],
        0xE4 => [556, 556], 0xF6 => [556, 611], 0xFC => [556, 611],
    ];

    public function __construct(protected string $title = '')
    {
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setPage(int $page): void
    {
        $this->current = $page;
    }

    /**
     * @param string $align left, right or center (x is the right edge / the center)
     * @param float $gray 0 = black, 1 = white
     */
    public function text(
        float $x,
        float $y,
        string $text,
        float $size = 10,
        bool $bold = false,
        string $align = 'left',
        float $gray = 0
    ): void {
        $encoded = $this->encode($text);
        $width = $this->encodedWidth($encoded, $size, $bold);
        $x = match ($align) {
            'right' => $x - $width,
            'center' => $x - $width / 2,
            default => $x,
        };

        $this->pages[$this->current] .= sprintf(
            "BT %s g /F%d %s Tf %s %s Td (%s) Tj ET\n",
            $this->number($gray),
            $bold ? 2 : 1,
            $this->number($size),
            $this->number($x),
            $this->number(self::HEIGHT - $y),
            strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r'])
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = .5, float $gray = 0): void
    {
        $this->pages[$this->current] .= sprintf(
            "%s G %s w %s %s m %s %s l S\n",
            $this->number($gray),
            $this->number($width),
            $this->number($x1),
            $this->number(self::HEIGHT - $y1),
            $this->number($x2),
            $this->number(self::HEIGHT - $y2)
        );
    }

    public function box(float $x, float $y, float $width, float $height, float $gray): void
    {
        $this->pages[$this->current] .= sprintf(
            "%s g %s %s %s %s re f\n",
            $this->number($gray),
            $this->number($x),
            $this->number(self::HEIGHT - $y - $height),
            $this->number($width),
            $this->number($height)
        );
    }

    public function width(string $text, float $size = 10, bool $bold = false): float
    {
        return $this->encodedWidth($this->encode($text), $size, $bold);
    }

    /**
     * Shortens the text with "…" to fit into the width
     */
    public function fit(string $text, float $width, float $size = 10, bool $bold = false): string
    {
        if ($this->width($text, $size, $bold) <= $width) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $this->width($text . '…', $size, $bold) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text) . '…';
    }

    public function output(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => sprintf(
                '<< /Title (%s) /Producer (Helfersystem) /CreationDate (D:%s) >>',
                $this->literal($this->title),
                date('YmdHis')
            ),
        ];

        $kids = [];
        foreach ($this->pages as $content) {
            $pageId = count($objects) + 1;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->number(self::WIDTH),
                $this->number(self::HEIGHT),
                $pageId + 1
            );
            $objects[$pageId + 1] = sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($content), $content);
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info 5 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            count($objects) + 1,
            $xref
        );

        return $pdf;
    }

    protected function encode(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    protected function encodedWidth(string $encoded, float $size, bool $bold): float
    {
        $width = 0;
        foreach (str_split($encoded) as $char) {
            $code = ord($char);
            $width += $code >= 32 && $code <= 126
                ? self::WIDTHS[(int) $bold][$code - 32]
                : (self::EXTRA_WIDTHS[$code][(int) $bold] ?? 556);
        }

        return $width * $size / 1000;
    }

    /**
     * Text for the document info, as literal string
     */
    protected function literal(string $text): string
    {
        return strtr($this->encode($text), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    protected function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.') ?: '0';
    }
}
