<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Hand-written tokenizer for the gem's pest grammar. Faithful details:
 *   - the only whitespace the grammar accepts is the plain space character
 *     (tabs and newlines are parse errors, exactly like the gem);
 *   - decimals are digits with an optional dot-digits fraction ("1.1.1"
 *     lexes as Number("1.1"), Dot, Number("1") and then fails to parse);
 *   - string literals are single-quoted with no escape sequences, and the
 *     contents may span any character except the closing quote.
 */
final class Lexer
{
    public function __construct(
        private readonly string $input,
    ) {}

    /** @return list<Token> ending with an End token */
    public function tokenize(): array
    {
        $tokens = [];
        $length = mb_strlen($this->input, '8bit');
        $position = 0;

        while ($position < $length) {
            $char = $this->input[$position];

            if ($char === ' ') {
                $position++;

                continue;
            }

            $symbol = match ($char) {
                '+' => TokenType::Plus,
                '-' => TokenType::Minus,
                '*' => TokenType::Star,
                '/' => TokenType::Slash,
                '(' => TokenType::LeftParen,
                ')' => TokenType::RightParen,
                ',' => TokenType::Comma,
                '.' => TokenType::Dot,
                default => null,
            };

            if ($symbol !== null) {
                $tokens[] = new Token($symbol, $char, $position);
                $position++;

                continue;
            }

            if (ctype_digit($char)) {
                preg_match('/\d+(?:\.\d+)?/A', $this->input, $matches, 0, $position);
                $text = $matches[0];
                $tokens[] = new Token(TokenType::Number, $text, $position);
                $position += mb_strlen($text);

                continue;
            }

            if (ctype_alpha($char)) {
                preg_match('/[A-Za-z][A-Za-z0-9_]*/A', $this->input, $matches, 0, $position);
                $text = $matches[0];
                $tokens[] = new Token(TokenType::Identifier, $text, $position);
                $position += mb_strlen($text);

                continue;
            }

            if ($char === "'") {
                $end = mb_strpos($this->input, "'", $position + 1, '8bit');

                if ($end === false) {
                    throw new ExpressionParseException(
                        "expected a closing quote for the string at position {$position}, found end of input",
                    );
                }

                $contents = mb_substr($this->input, $position + 1, $end - $position - 1, '8bit');
                $tokens[] = new Token(TokenType::String, $contents, $position);
                $position = $end + 1;

                continue;
            }

            throw new ExpressionParseException(
                "expected a valid expression character at position {$position}, found '{$char}'",
            );
        }

        $tokens[] = new Token(TokenType::End, '', $length);

        return $tokens;
    }
}
