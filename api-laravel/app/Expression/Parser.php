<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Recursive-descent parser mirroring the gem's pest grammar
 * (expression-core/src/grammar.pest, v0.2.0):
 *
 *   expr    := atom (("+" | "-" | "*" | "/") atom)*      left-associative,
 *              with add/sub binding looser than mul/div
 *   atom    := "-"? primary                              a single minus only
 *   primary := function | event-variable | decimal | string | "(" expr ")"
 *
 * Parse errors raise ExpressionParseException, matching where the gem's
 * pest parse fails; the gem's WrongNumberOfArguments quirk (the message
 * always names "round", even for ceil/floor) is preserved verbatim.
 *
 * Rails consumes the gem in three places; the first two are wired, the
 * third is the documented integration call for the fee/event pipeline:
 *
 *   1. BillableMetric::validateExpression — wired (app/Models/BillableMetric.php);
 *   2. BillableMetrics::EvaluateExpressionService — wired (evaluate_expression API);
 *   3. Events::CalculateExpressionService (app/services/events/
 *      calculate_expression_service.rb, consumed by event ingestion and the
 *      fee estimate services) — for a custom-aggregation charge:
 *
 *      $value = (new Evaluator)->evaluate(
 *          Parser::parse($metric->expression),   // saved metrics always parse
 *          ExpressionEvent::fromPayload($payload),
 *      );
 *      $payload['properties'][$metric->field_name] = $value->display();
 */
final class Parser
{
    /** @var list<Token> */
    private array $tokens;

    private int $position = 0;

    private function __construct(string $input)
    {
        $this->tokens = (new Lexer($input))->tokenize();
    }

    /**
     * Port of `Lago::ExpressionParser.parse_expression` — parses or throws.
     *
     * @throws ExpressionParseException
     */
    public static function parse(string $input): Expression
    {
        try {
            return (new self($input))->doParse();
        } catch (ExpressionParseException $exception) {
            // The gem's pest errors quote the offending input; keep the
            // excerpt in the message ("expected ... in `1+`").
            throw new ExpressionParseException(
                $exception->getMessage().' in `'.$input.'`',
            );
        }
    }

    /**
     * Port of `Lago::ExpressionParser.parse` — null when the input is not a
     * valid expression.
     */
    public static function tryParse(string $input): ?Expression
    {
        try {
            return self::parse($input);
        } catch (ExpressionParseException) {
            return null;
        }
    }

    /**
     * Port of `Lago::ExpressionParser.validate` — null when valid, the
     * parse error message otherwise.
     */
    public static function validate(string $input): ?string
    {
        try {
            self::parse($input);
        } catch (ExpressionParseException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function doParse(): Expression
    {
        $expression = $this->parseExpression();

        $end = $this->peek();

        if ($end->type !== TokenType::End) {
            throw new ExpressionParseException(sprintf(
                "expected the end of the expression at position %d, found '%s'",
                $end->position,
                $end->value,
            ));
        }

        return $expression;
    }

    private function parseExpression(int $minPrecedence = 1): Expression
    {
        $lhs = $this->parseAtom();

        while (true) {
            $operator = match ($this->peek()->type) {
                TokenType::Plus => BinaryOperator::Add,
                TokenType::Minus => BinaryOperator::Subtract,
                TokenType::Star => BinaryOperator::Multiply,
                TokenType::Slash => BinaryOperator::Divide,
                default => null,
            };

            if ($operator === null || $operator->precedence() < $minPrecedence) {
                return $lhs;
            }

            $this->advance();

            $lhs = new BinaryOperationNode(
                $lhs,
                $operator,
                $this->parseExpression($operator->precedence() + 1),
            );
        }
    }

    private function parseAtom(): Expression
    {
        if ($this->peek()->type === TokenType::Minus) {
            $this->advance();

            return new UnaryMinusNode($this->parsePrimary());
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): Expression
    {
        $token = $this->peek();

        switch ($token->type) {
            case TokenType::Number:
                $this->advance();

                return new NumberNode($token->value);

            case TokenType::String:
                $this->advance();

                return new StringNode($token->value);

            case TokenType::LeftParen:
                $this->advance();
                $expression = $this->parseExpression();
                $this->expect(TokenType::RightParen, "expected ')'");

                return $expression;

            case TokenType::Identifier:
                return $this->parseIdentifier();

            default:
                throw new ExpressionParseException(sprintf(
                    "expected an expression at position %d, found '%s'",
                    $token->position,
                    $token->value === '' ? 'end of input' : $token->value,
                ));
        }
    }

    private function parseIdentifier(): Expression
    {
        $token = $this->advance();
        $spelling = $token->value;

        $function = FunctionName::fromSpelling($spelling);

        if ($function !== null) {
            return $this->parseFunction($function, $token);
        }

        if ($spelling === 'event') {
            return $this->parseEventVariable($token);
        }

        throw new ExpressionParseException(sprintf(
            "expected a function, event attribute, number or string at position %d, found '%s'",
            $token->position,
            $spelling,
        ));
    }

    private function parseFunction(FunctionName $name, Token $token): Expression
    {
        $this->expect(TokenType::LeftParen, "expected '(' after the function name '{$token->value}'");

        $arguments = [];

        if ($this->peek()->type !== TokenType::RightParen) {
            $arguments[] = $this->parseExpression();

            while ($this->peek()->type === TokenType::Comma) {
                $this->advance();
                $arguments[] = $this->parseExpression();
            }
        }

        $this->expect(TokenType::RightParen, "expected ')' to close the arguments of '{$token->value}'");

        if (! $name->hasOptionalSecondArgument() && $arguments === []) {
            // The gem's grammar requires at least one argument (function_args
            // = expr ~ ("," expr)*), so an empty call fails to parse.
            throw new ExpressionParseException(sprintf(
                "expected at least one argument at position %d, found ')'",
                $token->position,
            ));
        }

        if ($name->hasOptionalSecondArgument()) {
            if (count($arguments) < 1 || count($arguments) > 2) {
                // The gem hardcodes "round" in this message for ceil and
                // floor too — preserved for fidelity.
                throw new ExpressionParseException(sprintf(
                    'Wrong number of arguments to function round, expected: 1..2, provided: %d',
                    count($arguments),
                ));
            }

            return new FunctionNode($name, [$arguments[0]], $arguments[1] ?? null);
        }

        return new FunctionNode($name, $arguments);
    }

    private function parseEventVariable(Token $token): Expression
    {
        $this->expect(TokenType::Dot, "expected '.' after 'event'");

        $attribute = $this->expect(TokenType::Identifier, 'expected an event attribute name');

        switch ($attribute->value) {
            case 'timestamp':
                return new EventAttributeNode(EventAttributeKind::Timestamp);

            case 'code':
                return new EventAttributeNode(EventAttributeKind::Code);

            case 'properties':
                $this->expect(TokenType::Dot, "expected '.' after 'event.properties'");

                $property = $this->expect(
                    TokenType::Identifier,
                    'expected a property name after event.properties',
                );

                return new EventAttributeNode(EventAttributeKind::Properties, $property->value);

            default:
                throw new ExpressionParseException(sprintf(
                    "expected 'timestamp', 'properties' or 'code' at position %d, found '%s'",
                    $attribute->position,
                    $attribute->value,
                ));
        }
    }

    private function peek(): Token
    {
        return $this->tokens[$this->position];
    }

    private function advance(): Token
    {
        return $this->tokens[$this->position++];
    }

    private function expect(TokenType $type, string $message): Token
    {
        $token = $this->peek();

        if ($token->type !== $type) {
            throw new ExpressionParseException(sprintf(
                "%s at position %d, found '%s'",
                $message,
                $token->position,
                $token->value === '' ? 'end of input' : $token->value,
            ));
        }

        return $this->advance();
    }
}
