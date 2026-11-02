<?php

namespace App\Domain\Learn;

use InvalidArgumentException;

/**
 * A Learn article parsed from its Markdown (LRN-001).
 *
 * Articles use the small subset the article view shows: paragraphs, `## ` headings
 * and `- ` bullets, with inline **bold** and *italics*. Anything else is refused,
 * links and images included, so an article is text only (LRN-004). The title comes
 * from {@see LearnTopic::title()}, so there is no `# ` heading, and the first block
 * must be a paragraph: it is the summary the index and the ? sheet show (LRN-003).
 *
 * @phpstan-type Span array{text: string, bold: bool, italic: bool}
 * @phpstan-type Block array{kind: 'paragraph'|'heading'|'bullet', spans: list<Span>}
 */
final readonly class LearnArticle
{
    /** @param  list<Block>  $blocks */
    private function __construct(public array $blocks) {}

    /** The first paragraph, as plain text. */
    public function summary(): string
    {
        return implode('', array_column($this->blocks[0]['spans'], 'text'));
    }

    /** @throws InvalidArgumentException naming the first line the view cannot show */
    public static function parse(string $markdown): self
    {
        /** @var list<array{'paragraph'|'heading'|'bullet', string}> $raw */
        $raw = [];
        $paragraph = [];

        // A blank line or a heading or bullet ends the paragraph above it; its lines join with spaces.
        foreach ([...(preg_split('/\R/', $markdown) ?: []), ''] as $rawLine) {
            $line = trim($rawLine);
            $block = null;

            if ($line !== '' && ctype_space($rawLine[0])) {
                // Wrapped or nested bullets would show as separate blocks.
                throw new InvalidArgumentException("Indented line: {$line}");
            } elseif (str_starts_with($line, '## ')) {
                $block = ['heading', substr($line, 3)];
            } elseif (str_starts_with($line, '- ')) {
                $block = ['bullet', substr($line, 2)];
            } elseif (preg_match('/^(#|\||!\[|\* |>|```|\d+\. )/', $line) === 1) {
                throw new InvalidArgumentException("Unsupported Markdown: {$line}");
            } elseif ($line !== '') {
                $paragraph[] = $line;

                continue;
            }

            if ($paragraph !== []) {
                $raw[] = ['paragraph', implode(' ', $paragraph)];
                $paragraph = [];
            }
            if ($block !== null) {
                $raw[] = $block;
            }
        }

        if ($raw === [] || $raw[0][0] !== 'paragraph') {
            throw new InvalidArgumentException('An article must start with a summary paragraph.');
        }

        return new self(array_map(fn (array $block): array => ['kind' => $block[0], 'spans' => self::spans($block[1])], $raw));
    }

    /**
     * A line's runs of text, each with its style. `**` toggles bold, `*` italics, `***` both.
     *
     * @return list<Span>
     */
    private static function spans(string $line): array
    {
        if (str_contains($line, '`') || preg_match('/\[[^\]]*\]\(/', $line) === 1) {
            throw new InvalidArgumentException("Unsupported inline Markdown: {$line}");
        }

        $spans = [];
        $bold = false;
        $italic = false;

        foreach (preg_split('/(\*{1,3})/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (trim($part, '*') === '') {
                $bold = strlen($part) === 1 ? $bold : ! $bold;
                $italic = strlen($part) === 2 ? $italic : ! $italic;

                continue;
            }

            $spans[] = ['text' => $part, 'bold' => $bold, 'italic' => $italic];
        }

        if ($bold || $italic) {
            throw new InvalidArgumentException("Unclosed bold or italics: {$line}");
        }

        return $spans;
    }
}
