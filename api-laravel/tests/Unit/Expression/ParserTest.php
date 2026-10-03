<?php

declare(strict_types=1);

uses()->group('expression');

use App\Expression\Parser;
use App\Expression\Expression;
use App\Expression\NumberNode;
use App\Expression\StringNode;
use App\Expression\FunctionName;
use App\Expression\FunctionNode;
use App\Expression\BinaryOperator;
use App\Expression\UnaryMinusNode;
use App\Expression\EventAttributeKind;
use App\Expression\EventAttributeNode;
use App\Expression\BinaryOperationNode;

/**
 * Port of the gem's parser spec corpus:
 *   - expression-core/src/parser.rs #[cfg(test)] (test_parse_*)
 *   - expression-ruby/spec/lago-expression/expression_parser_spec.rb
 */
function parseAst(string $input): Expression
{
    try {
        return Parser::parse($input);
    } catch (App\Expression\ExpressionParseException $exception) {
        throw new RuntimeException("Failed to parse expression: {$exception->getMessage()}");
    }
}

it('parses a decimal', function (): void {
    expect(parseAst('1'))->toEqual(new NumberNode('1'));
});

it('parses an event timestamp attribute', function (): void {
    expect(parseAst('event.timestamp'))->toEqual(new EventAttributeNode(EventAttributeKind::Timestamp));
});

it('parses an event property access', function (): void {
    expect(parseAst('event.properties.blah'))->toEqual(new EventAttributeNode(EventAttributeKind::Properties, 'blah'));
});

it('parses a string', function (): void {
    expect(parseAst("'test'"))->toEqual(new StringNode('test'));
});

it('parses concat', function (): void {
    expect(parseAst("concat('a', 'b')"))->toEqual(new FunctionNode(FunctionName::Concat, [
        new StringNode('a'),
        new StringNode('b'),
    ]));
});

it('parses uppercase concat', function (): void {
    expect(parseAst("CONCAT('a', 'b')"))->toEqual(new FunctionNode(FunctionName::Concat, [
        new StringNode('a'),
        new StringNode('b'),
    ]));
});

it('parses capitalized concat', function (): void {
    expect(parseAst("Concat('a', 'b')"))->toEqual(new FunctionNode(FunctionName::Concat, [
        new StringNode('a'),
        new StringNode('b'),
    ]));
});

it('parses ceil with one argument', function (): void {
    expect(parseAst('ceil(123)'))->toEqual(new FunctionNode(
        FunctionName::Ceil,
        [new NumberNode('123')],
        null,
    ));
});

it('parses ceil with a negative digit argument', function (): void {
    expect(parseAst('ceil(123, -1)'))->toEqual(new FunctionNode(
        FunctionName::Ceil,
        [new NumberNode('123')],
        new UnaryMinusNode(new NumberNode('1')),
    ));
});

it('parses round with one argument', function (): void {
    expect(parseAst('round(123)'))->toEqual(new FunctionNode(
        FunctionName::Round,
        [new NumberNode('123')],
        null,
    ));
});

it('parses round with two arguments', function (): void {
    expect(parseAst('round(123, 1)'))->toEqual(new FunctionNode(
        FunctionName::Round,
        [new NumberNode('123')],
        new NumberNode('1'),
    ));
});

it('parses floor with one argument', function (): void {
    expect(parseAst('floor(123)'))->toEqual(new FunctionNode(
        FunctionName::Floor,
        [new NumberNode('123')],
        null,
    ));
});

it('parses floor with two arguments', function (): void {
    expect(parseAst('floor(123, 1)'))->toEqual(new FunctionNode(
        FunctionName::Floor,
        [new NumberNode('123')],
        new NumberNode('1'),
    ));
});

it('parses least and greatest case-insensitively', function (): void {
    expect(parseAst('LEAST(1, 2)'))->toEqual(new FunctionNode(FunctionName::Least, [
        new NumberNode('1'),
        new NumberNode('2'),
    ]))->and(parseAst('GREATEST(1, 2)'))->toEqual(new FunctionNode(FunctionName::Greatest, [
        new NumberNode('1'),
        new NumberNode('2'),
    ]));
});

it('rejects an invalid decimal', function (): void {
    expect(fn (): Expression => Parser::parse('1.1.1'))->toThrow(App\Expression\ExpressionParseException::class);
});

it('builds left-associative multiplication over addition', function (): void {
    // 1 + 2 * 3 -> 1 + (2 * 3): mul/div bind tighter than add/sub
    expect(parseAst('1 + 2 * 3'))->toEqual(new BinaryOperationNode(
        new NumberNode('1'),
        BinaryOperator::Add,
        new BinaryOperationNode(new NumberNode('2'), BinaryOperator::Multiply, new NumberNode('3')),
    ));
});

it('allows a single prefix minus but not two', function (): void {
    expect(parseAst('-1'))->toEqual(new UnaryMinusNode(new NumberNode('1')))
        ->and(parseAst('1 - -2'))->toEqual(new BinaryOperationNode(
            new NumberNode('1'),
            BinaryOperator::Subtract,
            new UnaryMinusNode(new NumberNode('2')),
        ))
        ->and(fn (): Expression => Parser::parse('--1'))->toThrow(App\Expression\ExpressionParseException::class);
});

it('rejects operators the grammar does not have', function (string $input): void {
    expect(fn (): Expression => Parser::parse($input))->toThrow(App\Expression\ExpressionParseException::class);
})->with([
    'power' => '2 ** 3',
    'modulo' => '5 % 2',
    'comparison' => '1 < 2',
    'leading op' => '+2',
    'dangling op' => '1 +',
    'empty' => '',
    'bare function name' => 'round + 1',
    'wrong event attribute' => 'event.wat',
    'uppercase prefix' => 'EVENT.properties.wat',
    'bare identifier' => 'wat',
    'unterminated string' => "'abc",
]);

it('only accepts the plain space character as whitespace', function (): void {
    expect(parseAst('1 +2'))->toEqual(new BinaryOperationNode(
        new NumberNode('1'),
        BinaryOperator::Add,
        new NumberNode('2'),
    ))->and(fn (): Expression => Parser::parse("1\n+2"))->toThrow(App\Expression\ExpressionParseException::class)
        ->and(fn (): Expression => Parser::parse("1\t+2"))->toThrow(App\Expression\ExpressionParseException::class);
});

it('requires at least one argument on variadic functions', function (): void {
    expect(fn (): Expression => Parser::parse('greatest()'))->toThrow(App\Expression\ExpressionParseException::class)
        ->and(fn (): Expression => Parser::parse('concat()'))->toThrow(App\Expression\ExpressionParseException::class);
});

it('rejects the wrong argument count on rounding functions with the gem message', function (): void {
    // The gem hardcodes "round" in this message for ceil and floor too.
    expect(fn (): Expression => Parser::parse('ceil()'))->toThrow(
        App\Expression\ExpressionParseException::class,
        'Wrong number of arguments to function round, expected: 1..2, provided: 0',
    )->and(fn (): Expression => Parser::parse('floor(1, 2, 3)'))->toThrow(
        App\Expression\ExpressionParseException::class,
        'Wrong number of arguments to function round, expected: 1..2, provided: 3',
    );
});

// -- Port of expression_parser_spec.rb ------------------------------------------

it('returns an expression when it is valid', function (): void {
    expect(Parser::tryParse('1+2'))->not->toBeNull();
});

it('returns null when the expression is not valid', function (): void {
    expect(Parser::tryParse('1+'))->toBeNull();
});

it('validates a valid expression to null', function (): void {
    expect(Parser::validate('1+2'))->toBeNull();
});

it('validates an invalid expression to an error message', function (): void {
    $error = Parser::validate('1+');

    expect($error)->not->toBeNull()
        ->and($error)->toContain('1+')
        ->and($error)->toContain('expected');
});
