<?php

declare(strict_types=1);

namespace App\Expression;

/** Token kinds produced by the Lexer for the expression grammar. */
enum TokenType
{
    case Number;
    case String;
    case Identifier;
    case Plus;
    case Minus;
    case Star;
    case Slash;
    case LeftParen;
    case RightParen;
    case Comma;
    case Dot;
    case End;
}
